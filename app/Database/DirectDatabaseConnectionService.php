<?php

declare(strict_types=1);

namespace App\Database;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Security\Crypto;
use App\Security\HostValidator;
use PDO;
use PDOException;

final class DirectDatabaseConnectionService
{
    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly CpanelDatabaseService $cpanelDatabases,
        private readonly HostValidator $hostValidator,
        private readonly Crypto $crypto,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array{status:string,database:string,db_user:string,tested_ip:?string,error_code:?string} */
    public function provision(int $userId, int $accountId, string $databaseName): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $databaseName = $this->identifier($databaseName);
        $existing = $this->database->one(
            'SELECT * FROM database_connections WHERE user_id = ? AND account_id = ? AND database_name = ?',
            [$userId, $accountId, $databaseName]
        );
        if ($existing !== null) {
            try {
                $pdo = $this->pdo($userId, $accountId, $databaseName);
                $pdo->query('SELECT 1')->fetchColumn();
                return ['status' => 'active', 'database' => $databaseName, 'db_user' => (string) $existing['db_username'], 'tested_ip' => null, 'error_code' => null];
            } catch (\Throwable) {
                // Replace an unusable managed credential instead of accumulating
                // orphaned cPanel users on each retry.
                $this->disable($userId, $accountId, $databaseName, true);
            }
        }
        $dbUser = $this->managedUserName($userId, $accountId, (string) $account['cpanel_username']);
        $password = $this->strongPassword();
        $createdUser = false;
        $storedConnection = false;
        try {
            $this->cpanelDatabases->createUser($userId, $accountId, $dbUser, $password);
            $createdUser = true;
            $this->cpanelDatabases->assign($userId, $accountId, $databaseName, $dbUser, [
                'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX',
                'CREATE VIEW', 'SHOW VIEW', 'TRIGGER', 'EXECUTE', 'EVENT', 'REFERENCES',
                'LOCK TABLES', 'CREATE TEMPORARY TABLES',
            ]);
            $encrypted = $this->crypto->encrypt($password, $this->context($userId, $accountId, $databaseName, $dbUser));
            $validated = $this->hostValidator->validate((string) $account['base_url']);
            $this->database->execute(
                'INSERT INTO database_connections (user_id, account_id, database_name, db_host, db_port, db_username, encrypted_password, key_version, status) VALUES (?, ?, ?, ?, 3306, ?, ?, 1, \'pending\') '
                . 'ON DUPLICATE KEY UPDATE db_host = VALUES(db_host), db_port = VALUES(db_port), db_username = VALUES(db_username), encrypted_password = VALUES(encrypted_password), key_version = VALUES(key_version), status = \'pending\', last_error_code = NULL, updated_at = CURRENT_TIMESTAMP',
                [$userId, $accountId, $databaseName, (string) $account['hostname'], $dbUser, $encrypted]
            );
            $storedConnection = true;
            $testedIp = $this->testResolvedIps($userId, $accountId, $databaseName, $validated['ips']);
            $this->database->execute('UPDATE database_connections SET status = \'active\', tested_at = CURRENT_TIMESTAMP, last_error_code = NULL WHERE user_id = ? AND account_id = ? AND database_name = ?', [$userId, $accountId, $databaseName]);
            $this->setCapability($accountId, true, null, $testedIp);
            $this->audit->record($userId, $accountId, 'database.connection_provision', 'success', 'database', $databaseName, ['db_user' => $dbUser, 'tested_ip' => $testedIp]);
            return ['status' => 'active', 'database' => $databaseName, 'db_user' => $dbUser, 'tested_ip' => $testedIp, 'error_code' => null];
        } catch (\Throwable $exception) {
            $code = $exception instanceof AppException ? $exception->safeCode : 'remote_mysql_unavailable';
            $cleanedUser = !$createdUser;
            if ($createdUser) {
                try {
                    $this->cpanelDatabases->deleteUser($userId, $accountId, $dbUser);
                    $cleanedUser = true;
                } catch (\Throwable) {
                    $cleanedUser = false;
                }
            }
            if ($storedConnection) {
                $this->database->execute(
                    'DELETE FROM database_connections WHERE user_id = ? AND account_id = ? AND database_name = ? AND db_username = ?',
                    [$userId, $accountId, $databaseName, $dbUser]
                );
            }
            $this->setCapability($accountId, false, $code, null);
            $this->audit->record($userId, $accountId, 'database.connection_provision', 'failed', 'database', $databaseName, ['error_code' => $code, 'managed_user_cleanup' => $cleanedUser]);
            if (!$cleanedUser) {
                throw new AppException('Direct MySQL provisioning failed and cPanel refused automatic cleanup of the generated database user. Remove that user in Database Users before retrying.', 424, 'database_connection_cleanup_required', ['db_user' => $dbUser], 'database.remote');
            }
            if ($exception instanceof AppException) {
                throw $exception;
            }
            throw new AppException('Direct MySQL could not connect. cPanel database management remains available; allow this server in Remote MySQL and retry.', 424, 'remote_mysql_unavailable', [], 'database.remote');
        } finally {
            $password = str_repeat("\0", strlen($password));
        }
    }

    public function pdo(int $userId, int $accountId, string $databaseName): PDO
    {
        $this->accounts->getOwned($userId, $accountId);
        $databaseName = $this->identifier($databaseName);
        $row = $this->database->one('SELECT * FROM database_connections WHERE user_id = ? AND account_id = ? AND database_name = ? AND status = \'active\'', [$userId, $accountId, $databaseName]);
        if ($row === null) {
            throw new AppException('Direct database access is not active for this database. cPanel-level database controls are still available.', 424, 'database_connection_unavailable', [], 'database.remote');
        }
        $account = $this->accounts->getOwned($userId, $accountId);
        $validated = $this->hostValidator->validate((string) $account['base_url']);
        $password = $this->crypto->decrypt((string) $row['encrypted_password'], $this->context($userId, $accountId, $databaseName, (string) $row['db_username']));
        try {
            foreach ($validated['ips'] as $ip) {
                try {
                    return $this->connect($ip, (int) $row['db_port'], $databaseName, (string) $row['db_username'], $password);
                } catch (PDOException) {
                    continue;
                }
            }
        } finally {
            $password = str_repeat("\0", strlen($password));
        }
        throw new AppException('Direct MySQL is currently unreachable. Check Remote MySQL allowlisting and provider policy.', 424, 'remote_mysql_unavailable', [], 'database.remote');
    }

    /** @return list<array<string,mixed>> */
    public function status(int $userId, int $accountId): array
    {
        $this->accounts->getOwned($userId, $accountId);
        return $this->database->all('SELECT id, database_name, db_host, db_port, db_username, status, last_error_code, tested_at, created_at, updated_at FROM database_connections WHERE user_id = ? AND account_id = ? ORDER BY database_name', [$userId, $accountId]);
    }

    public function disable(int $userId, int $accountId, string $databaseName, bool $removeCpanelUser = true): void
    {
        $databaseName = $this->identifier($databaseName);
        $row = $this->database->one('SELECT * FROM database_connections WHERE user_id = ? AND account_id = ? AND database_name = ?', [$userId, $accountId, $databaseName]);
        if ($row === null) {
            throw new AppException('Database connection was not found.', 404, 'database_connection_not_found');
        }
        if ($removeCpanelUser) {
            $this->cpanelDatabases->deleteUser($userId, $accountId, (string) $row['db_username']);
        }
        $this->database->execute('DELETE FROM database_connections WHERE id = ? AND user_id = ?', [$row['id'], $userId]);
        $this->audit->record($userId, $accountId, 'database.connection_disable', 'success', 'database', $databaseName, ['removed_cpanel_user' => $removeCpanelUser]);
    }

    /** @param list<string> $ips */
    private function testResolvedIps(int $userId, int $accountId, string $databaseName, array $ips): string
    {
        $row = $this->database->one('SELECT * FROM database_connections WHERE user_id = ? AND account_id = ? AND database_name = ?', [$userId, $accountId, $databaseName]);
        if ($row === null) {
            throw new AppException('Database connection record was not created.', 500, 'database_connection_missing');
        }
        $password = $this->crypto->decrypt((string) $row['encrypted_password'], $this->context($userId, $accountId, $databaseName, (string) $row['db_username']));
        try {
            foreach ($ips as $ip) {
                try {
                    $pdo = $this->connect($ip, 3306, $databaseName, (string) $row['db_username'], $password);
                    $pdo->query('SELECT 1')->fetchColumn();
                    return $ip;
                } catch (PDOException) {
                    continue;
                }
            }
        } finally {
            $password = str_repeat("\0", strlen($password));
        }
        throw new AppException('The hosting provider refused direct MySQL access. Add this application server to Remote MySQL or keep using cPanel-level database controls.', 424, 'remote_mysql_unavailable', [], 'database.remote');
    }

    private function connect(string $ip, int $port, string $databaseName, string $username, string $password): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 7,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        $ca = \App\Core\Env::get('MYSQL_SSL_CA');
        if (is_string($ca) && $ca !== '' && is_file($ca) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
        }
        return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $ip, $port, $databaseName), $username, $password, $options);
    }

    private function identifier(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $value)) {
            throw new AppException('Database identifier is invalid.', 422, 'invalid_database_name', [], 'database.overview');
        }
        return $value;
    }

    private function strongPassword(): string
    {
        return 'A' . 'a' . random_int(0, 9) . '!' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function managedUserName(int $userId, int $accountId, string $cpanelUsername): string
    {
        $prefix = $cpanelUsername . '_';
        $maximum = 32;
        try {
            $restrictions = $this->cpanelDatabases->restrictions($userId, $accountId);
            foreach (['prefix', 'database_prefix', 'user_prefix'] as $key) {
                if (is_string($restrictions[$key] ?? null) && preg_match('/^[A-Za-z0-9_$-]+$/', (string) $restrictions[$key])) {
                    $prefix = (string) $restrictions[$key];
                    break;
                }
            }
            foreach (['max_username_length', 'max_user_name_length'] as $key) {
                if (is_numeric($restrictions[$key] ?? null)) {
                    $maximum = max(8, min(64, (int) $restrictions[$key]));
                    break;
                }
            }
        } catch (\Throwable) {
            // Older providers may omit get_restrictions. The conservative fallback
            // remains compatible with common MySQL/MariaDB username limits.
        }
        $suffix = 'tc' . strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
        $available = $maximum - strlen($prefix);
        if ($available < 4) {
            throw new AppException('This provider leaves no safe namespace for a managed database user. Create a limited user manually and review provider naming restrictions.', 424, 'database_username_restriction', ['max_length' => $maximum], 'database.remote');
        }
        return $prefix . substr($suffix, 0, $available);
    }

    private function context(int $userId, int $accountId, string $databaseName, string $dbUser): string
    {
        return "db-password:{$userId}:{$accountId}:{$databaseName}:{$dbUser}";
    }

    private function setCapability(int $accountId, bool $available, ?string $errorCode, ?string $testedIp): void
    {
        $details = json_encode(
            array_filter(['error_code' => $errorCode, 'tested_ip' => $testedIp], static fn (mixed $value): bool => $value !== null),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $this->database->execute(
            'INSERT INTO account_capabilities (account_id, capability, available, writable, details_json, detected_at) VALUES (?, \'direct_mysql\', ?, ?, ?, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE available = VALUES(available), writable = VALUES(writable), details_json = VALUES(details_json), detected_at = CURRENT_TIMESTAMP',
            [$accountId, $available ? 1 : 0, $available ? 1 : 0, $details]
        );
    }
}
