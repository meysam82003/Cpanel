<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Cpanel\CapabilityDetector;
use App\Cpanel\UapiClient;
use App\Security\HostValidator;

final class AccountService
{
    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly HostValidator $hosts,
        private readonly UapiClient $cpanel,
        private readonly CapabilityDetector $capabilities,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array{id:int,account_info:array<string,mixed>,capabilities:array<string,mixed>} */
    public function add(int $userId, string $hostInput, string $username, string $token, bool $storeToken, ?string $ip = null): array
    {
        $username = trim($username);
        $token = trim($token);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $username) || strlen($token) < 8 || strlen($token) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new AppException('The cPanel username or API token format is invalid.', 422, 'invalid_cpanel_credentials', [], 'hosts.add');
        }
        $this->assertHostLimit($userId);
        $host = $this->hosts->validate($hostInput);
        $connection = ['base_url' => $host['base_url'], 'username' => $username, 'token' => $token];
        try {
            $info = $this->cpanel->testConnection($connection);
            $actualUsername = (string) ($info['user'] ?? $info['username'] ?? $username);
            if ($actualUsername !== '' && strcasecmp($actualUsername, $username) !== 0) {
                throw new AppException('The API token belongs to a different cPanel username.', 422, 'cpanel_username_mismatch', [], 'hosts.add');
            }
            $root = isset($info['homedir']) ? rtrim((string) $info['homedir'], '/') : (isset($info['home']) ? rtrim((string) $info['home'], '/') : null);
            $domain = isset($info['main_domain']) ? (string) $info['main_domain'] : (isset($info['domain']) ? (string) $info['domain'] : null);
            $accountId = $this->accounts->create($userId, $host, $username, $token, $storeToken, $root, $domain);
            $detected = $this->capabilities->detect($accountId, $connection);
            $this->audit->record($userId, $accountId, 'host.add', 'success', 'host', $host['host'], ['token_mode' => $storeToken ? 'stored' : 'temporary'], $ip);
            return ['id' => $accountId, 'account_info' => $info, 'capabilities' => $detected];
        } catch (\Throwable $exception) {
            $this->audit->record($userId, null, 'host.add', 'failed', 'host', $host['host'], ['error_code' => $exception instanceof AppException ? $exception->safeCode : 'unexpected_error'], $ip);
            throw $exception;
        } finally {
            $token = str_repeat("\0", strlen($token));
        }
    }

    /** @return array<string,mixed> */
    public function health(int $userId, int $accountId, ?string $ip = null): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $connection = $this->accounts->connection($userId, $accountId);
        $started = microtime(true);
        try {
            $info = $this->cpanel->testConnection($connection);
            $capabilities = $this->capabilities->detect($accountId, $connection);
            $this->database->execute('UPDATE cpanel_accounts SET status = \'active\', last_error_code = NULL, last_checked_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?', [$accountId, $userId]);
            $latency = (int) round((microtime(true) - $started) * 1000);
            $result = [
                'online' => true,
                'latency_ms' => $latency,
                'account' => $info,
                'checks' => [
                    'cpanel' => ['ok' => true, 'latency_ms' => $latency],
                    'api' => ['ok' => true, 'authenticated' => true],
                    'disk' => ['ok' => (bool) ($capabilities['usage']['available'] ?? false), 'capability' => $capabilities['usage'] ?? null],
                    'ssl' => ['ok' => (bool) ($capabilities['ssl']['available'] ?? false), 'capability' => $capabilities['ssl'] ?? null],
                    'database' => ['ok' => (bool) ($capabilities['mysql']['available'] ?? false), 'capability' => $capabilities['mysql'] ?? null],
                ],
                'capabilities' => $capabilities,
            ];
            $this->audit->record($userId, $accountId, 'host.health', 'success', 'host', (string) $account['hostname'], ['latency_ms' => $result['latency_ms']], $ip);
            return $result;
        } catch (AppException $exception) {
            $this->database->execute('UPDATE cpanel_accounts SET status = \'error\', last_error_code = ?, last_checked_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?', [$exception->safeCode, $accountId, $userId]);
            $this->database->execute(
                "INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) SELECT ?, 'token_alert', 'notification.token_title', 'notification.token_failed', ? WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE user_id = ? AND type = 'token_alert' AND parameters_json = ? AND created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE))",
                [$userId, json_encode(['host' => (string) $account['hostname']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $userId, json_encode(['host' => (string) $account['hostname']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]
            );
            $this->audit->record($userId, $accountId, 'host.health', 'failed', 'host', (string) $account['hostname'], ['error_code' => $exception->safeCode], $ip);
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function rotateToken(int $userId, int $accountId, string $token, bool $store, ?string $ip = null): array
    {
        $token = trim($token);
        if (strlen($token) < 8 || strlen($token) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new AppException('API token format is invalid.', 422, 'invalid_cpanel_credentials', [], 'security.token-rotation');
        }
        $account = $this->accounts->getOwned($userId, $accountId);
        $connection = [
            'base_url' => (string) $account['base_url'],
            'username' => (string) $account['cpanel_username'],
            'token' => $token,
        ];
        try {
            $info = $this->cpanel->testConnection($connection);
            $actualUsername = (string) ($info['user'] ?? $info['username'] ?? $account['cpanel_username']);
            if ($actualUsername !== '' && strcasecmp($actualUsername, (string) $account['cpanel_username']) !== 0) {
                throw new AppException('The API token belongs to a different cPanel username.', 422, 'cpanel_username_mismatch', [], 'security.token-rotation');
            }
            $this->accounts->updateToken($userId, $accountId, $token, $store);
            $capabilities = $this->capabilities->detect($accountId, $connection);
            $this->database->execute("UPDATE cpanel_accounts SET status = 'active', last_error_code = NULL, last_checked_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?", [$accountId, $userId]);
            $this->audit->record($userId, $accountId, 'host.token_rotate', 'success', 'host', (string) $account['hostname'], ['token_mode' => $store ? 'stored' : 'temporary'], $ip);
            return ['rotated' => true, 'account' => $info, 'capabilities' => $capabilities];
        } catch (\Throwable $exception) {
            $this->audit->record($userId, $accountId, 'host.token_rotate', 'failed', 'host', (string) $account['hostname'], ['error_code' => $exception instanceof AppException ? $exception->safeCode : 'unexpected_error'], $ip);
            throw $exception;
        } finally {
            $token = str_repeat("\0", strlen($token));
            $connection['token'] = '';
        }
    }

    public function rename(int $userId, int $accountId, string $label, ?string $ip = null): void
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $this->accounts->updateLabel($userId, $accountId, $label);
        $this->audit->record($userId, $accountId, 'host.rename', 'success', 'host', (string) $account['hostname'], ['label' => mb_substr(trim($label), 0, 120)], $ip);
    }

    private function assertHostLimit(int $userId): void
    {
        $row = $this->database->one(
            'SELECT COALESCE(u.host_limit, p.host_limit, 1) AS host_limit, (SELECT COUNT(*) FROM cpanel_accounts a WHERE a.user_id = u.id) AS host_count '
            . 'FROM users u LEFT JOIN user_plans up ON up.user_id = u.id AND up.status = \'active\' LEFT JOIN plans p ON p.id = up.plan_id WHERE u.id = ?',
            [$userId]
        );
        if ($row === null || (int) $row['host_count'] >= (int) $row['host_limit']) {
            throw new AppException('Your plan host limit has been reached.', 403, 'host_limit_reached', [], 'plans');
        }
    }
}
