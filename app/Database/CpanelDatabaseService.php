<?php

declare(strict_types=1);

namespace App\Database;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Cpanel\UapiClient;

final class CpanelDatabaseService
{
    /**
     * Values accepted by the current cPanel Mysql::set_privileges_on_database
     * contract. Server-returned privileges are merged into this list so newer
     * cPanel/MySQL capabilities appear without a Mini App release.
     *
     * @var list<string>
     */
    private const DOCUMENTED_PRIVILEGES = [
        'ALL PRIVILEGES',
        'ALTER',
        'ALTER ROUTINE',
        'CREATE',
        'CREATE ROUTINE',
        'CREATE TEMPORARY TABLES',
        'CREATE VIEW',
        'DELETE',
        'DROP',
        'EVENT',
        'EXECUTE',
        'INDEX',
        'INSERT',
        'LOCK TABLES',
        'REFERENCES',
        'SELECT',
        'SHOW VIEW',
        'TRIGGER',
        'UPDATE',
    ];

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function listDatabases(int $userId, int $accountId): array
    {
        $result = $this->call($userId, $accountId, 'list_databases');
        return ['databases' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function restrictions(int $userId, int $accountId): array
    {
        $result = $this->call($userId, $accountId, 'get_restrictions');
        $data = $result['data'];
        if (is_array($data) && array_is_list($data) && is_array($data[0] ?? null)) {
            return $data[0];
        }
        return is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    public function createDatabase(int $userId, int $accountId, string $name): array
    {
        $name = $this->databaseName($name);
        $result = $this->call($userId, $accountId, 'create_database', ['name' => $name], 'POST', false);
        $this->audit->record($userId, $accountId, 'database.create', 'success', 'database', $name);
        return ['name' => $name, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function deleteDatabase(int $userId, int $accountId, string $name): array
    {
        $name = $this->databaseName($name);
        $result = $this->call($userId, $accountId, 'delete_database', ['name' => $name], 'POST', false);
        $this->audit->record($userId, $accountId, 'database.delete', 'success', 'database', $name);
        return ['name' => $name, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function listUsers(int $userId, int $accountId): array
    {
        $result = $this->call($userId, $accountId, 'list_users');
        return ['users' => $result['data']];
    }

    public function createUser(int $userId, int $accountId, string $name, string $password): array
    {
        $name = $this->databaseName($name);
        $this->password($password);
        $result = $this->call($userId, $accountId, 'create_user', ['name' => $name, 'password' => $password], 'POST', false);
        $this->audit->record($userId, $accountId, 'database_user.create', 'success', 'database_user', $name);
        return ['name' => $name, 'cpanel' => $result['data']];
    }

    public function deleteUser(int $userId, int $accountId, string $name): array
    {
        $name = $this->databaseName($name);
        $result = $this->call($userId, $accountId, 'delete_user', ['name' => $name], 'POST', false);
        $this->audit->record($userId, $accountId, 'database_user.delete', 'success', 'database_user', $name);
        return ['name' => $name, 'cpanel' => $result['data']];
    }

    public function changePassword(int $userId, int $accountId, string $name, string $password): array
    {
        $name = $this->databaseName($name);
        $this->password($password);
        $result = $this->call($userId, $accountId, 'set_password', ['user' => $name, 'password' => $password], 'POST', false);
        $this->audit->record($userId, $accountId, 'database_user.password_change', 'success', 'database_user', $name);
        return ['name' => $name, 'cpanel' => $result['data']];
    }

    /** @param list<string> $privileges */
    public function assign(int $userId, int $accountId, string $database, string $dbUser, array $privileges): array
    {
        $database = $this->databaseName($database);
        $dbUser = $this->databaseName($dbUser);
        $capability = $this->privilegesFor($userId, $accountId, $database, $dbUser);
        $privileges = $this->privileges($privileges, $capability['supported_privileges']);
        $result = $this->call($userId, $accountId, 'set_privileges_on_database', ['user' => $dbUser, 'database' => $database, 'privileges' => implode(',', $privileges)], 'POST', false);
        $this->audit->record($userId, $accountId, 'database.privileges_update', 'success', 'database', $database, ['db_user' => $dbUser, 'privileges' => $privileges]);
        return ['database' => $database, 'user' => $dbUser, 'privileges' => $privileges, 'cpanel' => $result['data']];
    }

    public function revoke(int $userId, int $accountId, string $database, string $dbUser): array
    {
        $database = $this->databaseName($database);
        $dbUser = $this->databaseName($dbUser);
        $result = $this->call($userId, $accountId, 'revoke_access_to_database', ['user' => $dbUser, 'database' => $database], 'POST', false);
        $this->audit->record($userId, $accountId, 'database.access_revoke', 'success', 'database', $database, ['db_user' => $dbUser]);
        return ['database' => $database, 'user' => $dbUser, 'cpanel' => $result['data']];
    }

    public function privilegesFor(int $userId, int $accountId, string $database, string $dbUser): array
    {
        $database = $this->databaseName($database);
        $dbUser = $this->databaseName($dbUser);
        $result = $this->call($userId, $accountId, 'get_privileges_on_database', ['user' => $dbUser, 'database' => $database]);
        $observed = $this->extractPrivileges($result['data']);
        $supported = array_values(array_unique(array_merge(self::DOCUMENTED_PRIVILEGES, $observed)));
        sort($supported, SORT_STRING);

        return [
            'database' => $database,
            'user' => $dbUser,
            'granted_privileges' => $observed,
            'supported_privileges' => $supported,
            'capability_source' => $observed === [] ? 'official_uapi_contract' : 'cpanel_response_and_official_uapi_contract',
            'cpanel' => $result['data'],
        ];
    }

    public function remoteHosts(int $userId, int $accountId): array
    {
        $result = $this->cpanel->callLegacyApi2($this->accounts->connection($userId, $accountId), 'MysqlFE', 'listhosts');
        return ['hosts' => $result['data'], 'provider_api' => 'api2_no_uapi_equivalent'];
    }

    public function addRemoteHost(int $userId, int $accountId, string $host): array
    {
        $host = $this->remoteHost($host);
        $result = $this->call($userId, $accountId, 'add_host', ['host' => $host], 'POST', false);
        $this->audit->record($userId, $accountId, 'database.remote_host_add', 'success', 'remote_host', $host);
        return ['host' => $host, 'cpanel' => $result['data']];
    }

    public function removeRemoteHost(int $userId, int $accountId, string $host): array
    {
        $host = $this->remoteHost($host);
        $result = $this->call($userId, $accountId, 'delete_host', ['host' => $host], 'POST', false);
        $this->audit->record($userId, $accountId, 'database.remote_host_remove', 'success', 'remote_host', $host);
        return ['host' => $host, 'cpanel' => $result['data']];
    }

    /** @param array<string,scalar|null> $params
     *  @return array{data:mixed,metadata:array<string,mixed>,messages:list<string>,warnings:list<string>}
     */
    private function call(int $userId, int $accountId, string $function, array $params = [], string $method = 'GET', bool $idempotent = true): array
    {
        return $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Mysql', $function, $params, $method, [], $idempotent);
    }

    private function databaseName(string $name): string
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name)) {
            throw new AppException('Database or database-user name is invalid.', 422, 'invalid_database_name', [], 'database.overview');
        }
        return $name;
    }

    private function password(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 255 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new AppException('Database password must be 12–255 characters with upper, lower, number, and symbol.', 422, 'weak_database_password', [], 'database.users');
        }
    }

    /** @param list<string> $privileges
     *  @return list<string>
     */
    private function privileges(array $privileges, array $supported): array
    {
        $normalized = [];
        foreach ($privileges as $privilege) {
            $privilege = strtoupper(trim($privilege));
            if (!preg_match('/^[A-Z][A-Z_ ]{1,63}$/', $privilege) || !in_array($privilege, $supported, true)) {
                throw new AppException('A malformed database privilege was requested.', 422, 'invalid_database_privilege', ['privilege' => $privilege], 'database.privileges');
            }
            $normalized[] = $privilege;
        }
        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            throw new AppException('Select at least one privilege or use revoke access.', 422, 'empty_database_privileges', [], 'database.privileges');
        }
        return in_array('ALL PRIVILEGES', $normalized, true) ? ['ALL PRIVILEGES'] : $normalized;
    }

    /** @return list<string> */
    private function extractPrivileges(mixed $payload): array
    {
        $found = [];
        $visit = function (mixed $value, bool $privilegeContext = false) use (&$visit, &$found): void {
            if (is_string($value) && $privilegeContext) {
                foreach (preg_split('/\s*[,;\n]\s*/', strtoupper(trim($value))) ?: [] as $candidate) {
                    if (preg_match('/^[A-Z][A-Z_ ]{1,63}$/', $candidate)) {
                        $found[] = $candidate;
                    }
                }
                return;
            }
            if (!is_array($value)) {
                return;
            }
            $isList = array_is_list($value);
            foreach ($value as $key => $child) {
                $keyText = is_string($key) ? strtoupper(str_replace('_', ' ', trim($key))) : '';
                $keyIsPrivilege = $keyText !== '' && preg_match('/^[A-Z][A-Z ]{1,63}$/', $keyText) === 1
                    && (in_array($keyText, self::DOCUMENTED_PRIVILEGES, true) || is_bool($child) || $child === 0 || $child === 1 || $child === '0' || $child === '1');
                if ($keyIsPrivilege && filter_var($child, FILTER_VALIDATE_BOOL)) {
                    $found[] = $keyText;
                }
                $namedContext = is_string($key) && in_array(strtolower($key), ['privilege', 'privileges', 'grant', 'grants'], true);
                $visit($child, $privilegeContext || $isList || $namedContext);
            }
        };
        $visit($payload, array_is_list(is_array($payload) ? $payload : []));
        $found = array_values(array_unique($found));
        sort($found, SORT_STRING);
        return $found;
    }

    private function remoteHost(string $host): string
    {
        $host = trim($host);
        if ($host === '%' || filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return $host;
        }
        throw new AppException('Remote MySQL host is invalid.', 422, 'invalid_remote_mysql_host', [], 'database.remote');
    }
}
