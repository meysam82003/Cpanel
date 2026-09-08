<?php

declare(strict_types=1);

namespace App\Security;

use App\Accounts\AccountRepository;
use App\Accounts\UserRepository;
use App\Core\AppException;
use App\Core\Database;

final class SecurityCenterService
{
    public function __construct(
        private readonly Database $database,
        private readonly UserRepository $users,
        private readonly AccountRepository $accounts,
        private readonly MiniAppSessionService $sessions,
    ) {
    }

    /** @return array<string,mixed> */
    public function dashboard(int $userId): array
    {
        $this->users->requireActive($userId);
        $hosts = $this->accounts->listOwned($userId);
        $sessions = $this->database->all('SELECT id, channel, telegram_auth_date, last_used_at, expires_at, created_at FROM miniapp_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP ORDER BY id DESC', [$userId]);
        $alerts = $this->database->all('SELECT id, account_id, event_type, severity, metadata_json, acknowledged_at, created_at FROM security_events WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$userId]);
        $destructive = $this->database->all("SELECT id, account_id, action, target_type, target_ref, result, created_at FROM audit_logs WHERE user_id = ? AND (action LIKE '%delete%' OR action LIKE '%drop%' OR action LIKE '%truncate%' OR action LIKE '%deploy%' OR action LIKE '%rollback%') ORDER BY id DESC LIMIT 50", [$userId]);
        $failedTokens = count(array_filter($hosts, static fn (array $host): bool => (string) ($host['last_error_code'] ?? '') === 'cpanel_auth_failed'));
        $suspicious = $this->database->one("SELECT COUNT(*) AS total FROM security_events WHERE user_id = ? AND event_type IN ('ssrf_target_blocked','path_outside_root','path_traversal_blocked','host_not_found','csrf_failed','session_binding_failed','invalid_init_data','init_data_replay','expired_init_data','rate_limit_exceeded','callback_invalid','callback_expired')", [$userId]);
        $unacknowledged = $this->database->one('SELECT COUNT(*) AS total FROM security_events WHERE user_id = ? AND acknowledged_at IS NULL', [$userId]);
        return [
            'summary' => [
                'connected_hosts' => count($hosts),
                'failed_tokens' => $failedTokens,
                'suspicious_requests' => (int) ($suspicious['total'] ?? 0),
                'recent_destructive_actions' => count($destructive),
                'active_sessions' => count($sessions),
                'security_alerts' => (int) ($unacknowledged['total'] ?? 0),
            ],
            'hosts' => $hosts,
            'sessions' => $sessions,
            'alerts' => $alerts,
            'destructive_actions' => $destructive,
        ];
    }

    public function revokeSession(int $userId, int $sessionId): void
    {
        $this->sessions->revoke($sessionId, $userId);
    }

    public function acknowledge(int $userId, int $eventId): void
    {
        $statement = $this->database->execute('UPDATE security_events SET acknowledged_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND acknowledged_at IS NULL', [$eventId, $userId]);
        if ($statement->rowCount() !== 1) {
            throw new AppException('Security alert was not found or was already acknowledged.', 404, 'security_event_not_found', [], 'security.idor');
        }
    }

    /** @param array<string,mixed> $metadata */
    public function record(?int $userId, ?int $accountId, string $type, string $severity, array $metadata): void
    {
        if (!in_array($severity, ['info', 'warning', 'danger'], true)) {
            throw new AppException('Security event severity is invalid.', 500, 'invalid_security_severity');
        }
        $fingerprint = hash('sha256', $type . '|' . ($userId ?? 0) . '|' . ($accountId ?? 0) . '|' . json_encode($metadata));
        $this->database->execute('INSERT INTO security_events (user_id, account_id, event_type, severity, fingerprint, metadata_json) VALUES (?, ?, ?, ?, ?, ?)', [$userId, $accountId, $type, $severity, $fingerprint, json_encode(\App\Core\SecretMasker::mask($metadata), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
