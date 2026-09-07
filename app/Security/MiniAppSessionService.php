<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AppException;
use App\Core\Database;

final class MiniAppSessionService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $serverSecret,
        private readonly int $ttlSeconds = 3600,
    ) {
    }

    /** @return array{token:string,csrf:string,expires_at:string} */
    public function create(int $userId, int $telegramAuthDate, ?string $userAgent, ?string $ip): array
    {
        $token = $this->randomToken();
        $csrf = $this->randomToken();
        $expires = gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds);
        $userAgentHash = $this->optionalHash($userAgent);
        $this->database->transaction(function (Database $db) use ($userId, $token, $csrf, $telegramAuthDate, $userAgentHash, $ip, $expires): void {
            if ($userAgentHash !== null) {
                $db->execute('UPDATE miniapp_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND user_agent_hash = ? AND revoked_at IS NULL', [$userId, $userAgentHash]);
            }
            $db->execute(
                'INSERT INTO miniapp_sessions (user_id, token_hash, csrf_hash, telegram_auth_date, user_agent_hash, ip_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $this->hash($token), $this->hash($csrf), $telegramAuthDate, $userAgentHash, $this->optionalHash($ip), $expires]
            );
        });
        return ['token' => $token, 'csrf' => $csrf, 'expires_at' => $expires];
    }

    /** @return array<string, mixed> */
    public function authenticate(string $token, ?string $csrf, string $method, ?string $userAgent): array
    {
        if ($token === '') {
            throw new AppException('Authentication is required.', 401, 'authentication_required');
        }
        $row = $this->database->one(
            'SELECT s.*, u.telegram_id, u.username, u.first_name, u.last_name, u.language, u.ux_mode, u.status, u.is_super_admin FROM miniapp_sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? LIMIT 1',
            [$this->hash($token)]
        );
        if ($row === null || $row['revoked_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
            throw new AppException('Your session has expired or was revoked.', 401, 'session_expired', [], 'security.sessions');
        }
        if ((string) $row['status'] !== 'active') {
            throw new AppException('This account is not active.', 403, 'user_not_active');
        }
        if ($row['user_agent_hash'] !== null && !hash_equals((string) $row['user_agent_hash'], (string) $this->optionalHash($userAgent))) {
            throw new AppException('Session device binding does not match.', 401, 'session_binding_failed');
        }
        if (!in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            if ($csrf === null || !hash_equals((string) $row['csrf_hash'], $this->hash($csrf))) {
                throw new AppException('The request CSRF token is invalid.', 419, 'csrf_failed');
            }
        }
        $this->database->execute('UPDATE miniapp_sessions SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?', [$row['id']]);
        return $row;
    }

    public function revoke(int $sessionId, int $ownerUserId): void
    {
        $statement = $this->database->execute('UPDATE miniapp_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$sessionId, $ownerUserId]);
        if ($statement->rowCount() !== 1) {
            throw new AppException('The session was not found or does not belong to you.', 404, 'session_not_found');
        }
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->serverSecret);
    }

    private function optionalHash(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $this->hash($value);
    }
}
