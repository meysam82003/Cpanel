<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Core\AppException;
use App\Core\Database;
use App\Security\Crypto;

final class AccountRepository
{
    public function __construct(private readonly Database $database, private readonly Crypto $crypto)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listOwned(int $userId): array
    {
        $accounts = $this->database->all(
            'SELECT a.id, a.label, a.base_url, a.hostname, a.port, a.cpanel_username, a.token_mode, a.root_path, a.main_domain, a.status, a.last_error_code, a.last_checked_at, a.capability_checked_at, a.created_at, '
            . '(SELECT COUNT(*) FROM account_capabilities c WHERE c.account_id = a.id AND c.available = 1) AS capability_count '
            . 'FROM cpanel_accounts a WHERE a.user_id = ? ORDER BY a.id DESC',
            [$userId]
        );

        if ($accounts === []) {
            return [];
        }

        $accountIndexes = [];
        foreach ($accounts as $index => &$account) {
            $accountId = (int) $account['id'];
            $accountIndexes[$accountId] = $index;
            $account['capability_count'] = (int) $account['capability_count'];
            $account['capabilities'] = [];
        }
        unset($account);

        $placeholders = implode(',', array_fill(0, count($accountIndexes), '?'));
        $capabilities = $this->database->all(
            "SELECT account_id, capability, available, writable, details_json, detected_at FROM account_capabilities WHERE account_id IN ({$placeholders}) ORDER BY capability",
            array_keys($accountIndexes)
        );
        foreach ($capabilities as $capability) {
            $accountId = (int) $capability['account_id'];
            if (!isset($accountIndexes[$accountId])) {
                continue;
            }
            $details = json_decode((string) ($capability['details_json'] ?? ''), true);
            $accounts[$accountIndexes[$accountId]]['capabilities'][(string) $capability['capability']] = [
                'available' => (bool) $capability['available'],
                'writable' => (bool) $capability['writable'],
                'details' => is_array($details) ? $details : [],
                'detected_at' => (string) $capability['detected_at'],
            ];
        }

        return $accounts;
    }

    /** @return array<string,mixed> */
    public function getOwned(int $userId, int $accountId): array
    {
        $row = $this->database->one('SELECT * FROM cpanel_accounts WHERE id = ? AND user_id = ?', [$accountId, $userId]);
        if ($row === null) {
            throw new AppException('The host was not found or does not belong to you.', 404, 'host_not_found', [], 'security.idor');
        }
        return $row;
    }

    /** @param array{base_url:string,host:string,port:int} $host */
    public function create(int $userId, array $host, string $username, string $token, bool $storeToken, ?string $rootPath, ?string $mainDomain): int
    {
        return $this->database->transaction(function (Database $db) use ($userId, $host, $username, $token, $storeToken, $rootPath, $mainDomain): int {
            $db->execute(
                'INSERT INTO cpanel_accounts (user_id, label, base_url, hostname, port, cpanel_username, token_mode, root_path, main_domain, status, last_checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', CURRENT_TIMESTAMP)',
                [$userId, $mainDomain ?: $host['host'], $host['base_url'], $host['host'], $host['port'], $username, $storeToken ? 'stored' : 'temporary', $rootPath, $mainDomain]
            );
            $accountId = $db->lastInsertId();
            $encrypted = $this->crypto->encrypt($token, $this->tokenContext($accountId, $userId));
            if ($storeToken) {
                $db->execute('UPDATE cpanel_accounts SET encrypted_token = ?, token_key_version = ? WHERE id = ?', [$encrypted, $this->envelopeVersion($encrypted), $accountId]);
            } else {
                $db->execute(
                    'INSERT INTO temporary_connections (user_id, account_id, encrypted_token, token_key_version, expires_at) VALUES (?, ?, ?, ?, ?)',
                    [$userId, $accountId, $encrypted, $this->envelopeVersion($encrypted), gmdate('Y-m-d H:i:s', time() + 1800)]
                );
            }
            return $accountId;
        });
    }

    /** @return array{base_url:string,username:string,token:string} */
    public function connection(int $userId, int $accountId): array
    {
        $account = $this->getOwned($userId, $accountId);
        $encrypted = $account['encrypted_token'];
        if ($encrypted === null) {
            $temporary = $this->database->one(
                'SELECT encrypted_token FROM temporary_connections WHERE user_id = ? AND account_id = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1',
                [$userId, $accountId]
            );
            $encrypted = $temporary['encrypted_token'] ?? null;
        }
        if (!is_string($encrypted) || $encrypted === '') {
            throw new AppException('This temporary connection expired. Enter a new API token.', 401, 'temporary_token_expired', [], 'security.temporary-connection');
        }
        return [
            'base_url' => (string) $account['base_url'],
            'username' => (string) $account['cpanel_username'],
            'token' => $this->crypto->decrypt($encrypted, $this->tokenContext($accountId, $userId)),
        ];
    }

    public function updateToken(int $userId, int $accountId, string $token, bool $store): void
    {
        $this->getOwned($userId, $accountId);
        $encrypted = $this->crypto->encrypt($token, $this->tokenContext($accountId, $userId));
        $this->database->transaction(function (Database $db) use ($userId, $accountId, $encrypted, $store): void {
            $db->execute('DELETE FROM temporary_connections WHERE user_id = ? AND account_id = ?', [$userId, $accountId]);
            $db->execute('UPDATE cpanel_accounts SET encrypted_token = ?, token_key_version = ?, token_mode = ?, last_error_code = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?', [$store ? $encrypted : null, $store ? $this->envelopeVersion($encrypted) : null, $store ? 'stored' : 'temporary', $accountId, $userId]);
            if (!$store) {
                $db->execute('INSERT INTO temporary_connections (user_id, account_id, encrypted_token, token_key_version, expires_at) VALUES (?, ?, ?, ?, ?)', [$userId, $accountId, $encrypted, $this->envelopeVersion($encrypted), gmdate('Y-m-d H:i:s', time() + 1800)]);
            }
        });
    }

    public function updateLabel(int $userId, int $accountId, string $label): void
    {
        $this->getOwned($userId, $accountId);
        $label = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $label) ?? '');
        if ($label === '' || mb_strlen($label) > 120) {
            throw new AppException('Host label must contain 1–120 printable characters.', 422, 'invalid_host_label', [], 'hosts.add');
        }
        $this->database->execute('UPDATE cpanel_accounts SET label = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?', [$label, $accountId, $userId]);
    }

    public function remove(int $userId, int $accountId): void
    {
        $this->getOwned($userId, $accountId);
        $this->database->execute('DELETE FROM cpanel_accounts WHERE id = ? AND user_id = ?', [$accountId, $userId]);
    }

    private function tokenContext(int $accountId, int $userId): string
    {
        return "cpanel-token:{$accountId}:{$userId}";
    }

    private function envelopeVersion(string $envelope): int
    {
        $padding = (4 - strlen($envelope) % 4) % 4;
        $json = base64_decode(strtr($envelope . str_repeat('=', $padding), '-_', '+/'), true);
        $payload = $json !== false ? json_decode($json, true) : null;
        return (int) ($payload['v'] ?? 0);
    }
}
