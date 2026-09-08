<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Core\Config;
use App\Core\Env;
use App\Queue\QueueService;
use App\Security\Crypto;
use App\Telegram\TelegramClient;

final class AdminService
{
    public function __construct(
        private readonly Database $database,
        private readonly QueueService $queue,
        private readonly AuditLogger $audit,
        private readonly TelegramClient $telegram,
        private readonly Crypto $crypto,
        private readonly string $root,
    )
    {
    }

    /** @return array<string,mixed> */
    public function dashboard(int $adminId): array
    {
        $this->admin($adminId);
        $totals = $this->database->one("SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM users WHERE last_seen_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY) AND status = 'active') AS active_users, (SELECT COUNT(*) FROM cpanel_accounts) AS hosts, (SELECT COUNT(*) FROM api_request_metrics WHERE created_at >= UTC_DATE()) AS api_requests_today, (SELECT COUNT(*) FROM api_request_metrics WHERE status_code >= 500 AND created_at >= UTC_DATE()) AS errors_today, (SELECT COUNT(*) FROM queue_jobs WHERE status IN ('queued','running')) AS queued, (SELECT COUNT(*) FROM queue_failed_jobs) AS failed_jobs, (SELECT COUNT(*) FROM security_events WHERE acknowledged_at IS NULL AND severity IN ('warning','danger')) AS security_alerts");
        $totals['storage_free_bytes'] = @disk_free_space($this->root . '/storage') ?: null;
        return ['totals' => $totals, 'queue' => $this->database->all('SELECT status, COUNT(*) AS total FROM queue_jobs GROUP BY status'), 'recent_errors' => $this->database->all('SELECT route, method, status_code, duration_ms, created_at FROM api_request_metrics WHERE status_code >= 400 ORDER BY id DESC LIMIT 25')];
    }

    /** @return array{items:list<array<string,mixed>>,page:int,per_page:int} */
    public function users(int $adminId, string $search = '', int $page = 1, int $perPage = 50): array
    {
        $this->admin($adminId);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $where = '';
        $params = [];
        if (trim($search) !== '') {
            $where = 'WHERE CAST(u.telegram_id AS CHAR) LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?';
            $needle = '%' . mb_substr(trim($search), 0, 100) . '%';
            $params = [$needle, $needle, $needle, $needle];
        }
        $sql = "SELECT u.id, u.telegram_id, u.username, u.first_name, u.last_name, u.status, u.language, u.ux_mode, u.is_super_admin, u.host_limit, u.created_at, u.last_seen_at, p.slug AS plan, (SELECT COUNT(*) FROM cpanel_accounts a WHERE a.user_id = u.id) AS hosts FROM users u LEFT JOIN user_plans up ON up.user_id = u.id AND up.status = 'active' LEFT JOIN plans p ON p.id = up.plan_id {$where} ORDER BY u.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage);
        return ['items' => $this->database->all($sql, $params), 'page' => $page, 'per_page' => $perPage];
    }

    public function setUserStatus(int $adminId, int $targetUserId, string $status): void
    {
        $admin = $this->admin($adminId);
        if (!in_array($status, ['active', 'banned', 'suspended'], true)) {
            throw new AppException('User status is invalid.', 422, 'invalid_user_status');
        }
        $target = $this->database->one('SELECT id, is_super_admin FROM users WHERE id = ?', [$targetUserId]);
        if ($target === null || ((bool) $target['is_super_admin'] && $status !== 'active')) {
            throw new AppException('The target user cannot be changed.', 422, 'admin_user_change_blocked');
        }
        $this->database->transaction(function (Database $db) use ($targetUserId, $status): void {
            $db->execute('UPDATE users SET status = ? WHERE id = ?', [$status, $targetUserId]);
            if ($status !== 'active') {
                $db->execute('UPDATE miniapp_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND revoked_at IS NULL', [$targetUserId]);
            }
        });
        $this->audit->record($adminId, null, 'admin.user_status', 'success', 'user', (string) $targetUserId, ['status' => $status]);
    }

    /** @return list<array<string,mixed>> */
    public function hosts(int $adminId, int $limit = 100): array
    {
        $this->admin($adminId);
        $limit = max(1, min(500, $limit));
        return $this->database->all('SELECT a.id, a.user_id, a.label, a.hostname, a.port, a.cpanel_username, a.token_mode, a.main_domain, a.status, a.last_error_code, a.last_checked_at, a.created_at FROM cpanel_accounts a ORDER BY a.id DESC LIMIT ' . $limit);
    }

    /** @return list<array<string,mixed>> */
    public function plans(int $adminId): array
    {
        $this->admin($adminId);
        return $this->database->all('SELECT * FROM plans ORDER BY id');
    }

    /** @param array<string,mixed> $values */
    public function updatePlan(int $adminId, string $slug, array $values): void
    {
        $this->admin($adminId);
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $slug)) {
            throw new AppException('Plan slug is invalid.', 422, 'invalid_plan');
        }
        $allowed = ['name_fa', 'name_en', 'host_limit', 'max_upload_bytes', 'database_manager', 'sql_console', 'backup_enabled', 'deployment_enabled', 'daily_operation_limit'];
        $booleans = ['database_manager', 'sql_console', 'backup_enabled', 'deployment_enabled'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            if (str_starts_with($field, 'name_')) {
                $value = mb_substr(trim((string) $value), 0, 100);
                if ($value === '') {
                    throw new AppException('Plan names cannot be empty.', 422, 'invalid_plan');
                }
            } elseif (in_array($field, $booleans, true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    throw new AppException('A plan feature flag must be boolean.', 422, 'invalid_plan');
                }
                $value = $value ? 1 : 0;
            } else {
                $value = filter_var($value, FILTER_VALIDATE_INT);
                if ($value === false || $value < 0) {
                    throw new AppException('A numeric plan limit is invalid.', 422, 'invalid_plan');
                }
            }
            $sets[] = $field . ' = ?';
            $params[] = $value;
        }
        if ($sets === []) {
            throw new AppException('No plan fields were supplied.', 422, 'invalid_plan');
        }
        $params[] = $slug;
        $statement = $this->database->execute('UPDATE plans SET ' . implode(', ', $sets) . ' WHERE slug = ?', $params);
        if ($statement->rowCount() < 1 && $this->database->one('SELECT id FROM plans WHERE slug = ?', [$slug]) === null) {
            throw new AppException('Plan was not found.', 404, 'plan_not_found');
        }
        $this->audit->record($adminId, null, 'admin.plan_update', 'success', 'plan', $slug, ['fields' => array_keys($values)]);
    }

    public function assignPlan(int $adminId, int $userId, string $slug, ?string $endsAt = null): void
    {
        $this->admin($adminId);
        $plan = $this->database->one('SELECT id FROM plans WHERE slug = ?', [$slug]);
        if ($plan === null || $this->database->one('SELECT id FROM users WHERE id = ?', [$userId]) === null) {
            throw new AppException('User or plan was not found.', 404, 'plan_assignment_not_found');
        }
        if ($endsAt !== null && strtotime($endsAt) === false) {
            throw new AppException('Plan end date is invalid.', 422, 'invalid_plan_end');
        }
        $this->database->transaction(function (Database $db) use ($userId, $plan, $endsAt): void {
            $db->execute("UPDATE user_plans SET status = 'replaced' WHERE user_id = ? AND status = 'active'", [$userId]);
            $db->execute("INSERT INTO user_plans (user_id, plan_id, ends_at, status) VALUES (?, ?, ?, 'active')", [$userId, $plan['id'], $endsAt]);
        });
        $this->audit->record($adminId, null, 'admin.plan_assign', 'success', 'user', (string) $userId, ['plan' => $slug, 'ends_at' => $endsAt]);
    }

    public function createBroadcast(int $adminId, string $messageFa, string $messageEn, array $filter = []): array
    {
        $this->admin($adminId);
        $messageFa = trim($messageFa);
        $messageEn = trim($messageEn);
        if ($messageFa === '' || $messageEn === '' || mb_strlen($messageFa) > 4096 || mb_strlen($messageEn) > 4096) {
            throw new AppException('Broadcast messages must be present in both languages and fit Telegram limits.', 422, 'invalid_broadcast');
        }
        $filter = array_intersect_key($filter, array_flip(['plan', 'language']));
        if (isset($filter['language']) && !in_array($filter['language'], ['fa', 'en'], true)) {
            throw new AppException('Broadcast language filter is invalid.', 422, 'invalid_broadcast');
        }
        if (isset($filter['plan']) && (!is_string($filter['plan']) || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $filter['plan']))) {
            throw new AppException('Broadcast plan filter is invalid.', 422, 'invalid_broadcast');
        }
        $this->database->execute('INSERT INTO admin_broadcasts (created_by, message_fa, message_en, target_filter_json) VALUES (?, ?, ?, ?)', [$adminId, $messageFa, $messageEn, json_encode($filter, JSON_THROW_ON_ERROR)]);
        $id = $this->database->lastInsertId();
        $job = $this->queue->dispatch('admin.broadcast', $adminId, null, ['broadcast_id' => $id, 'after_user_id' => 0], 'broadcast:' . $id);
        $this->audit->record($adminId, null, 'admin.broadcast_create', 'success', 'broadcast', (string) $id, ['filter' => $filter]);
        return ['broadcast_id' => $id, 'job_id' => $job];
    }

    public function setMaintenance(int $adminId, bool $enabled, string $messageFa, string $messageEn): void
    {
        $this->admin($adminId);
        $value = json_encode(['enabled' => $enabled, 'message_fa' => mb_substr(trim($messageFa), 0, 500), 'message_en' => mb_substr(trim($messageEn), 0, 500)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->database->execute('INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (\'maintenance\', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)', [$value, $adminId]);
        $this->audit->record($adminId, null, 'admin.maintenance', 'success', 'setting', 'maintenance', ['enabled' => $enabled]);
    }

    /** @return list<array<string,mixed>> */
    public function auditLogs(int $adminId, int $limit = 100): array
    {
        $this->admin($adminId);
        $limit = max(1, min(500, $limit));
        return $this->database->all('SELECT id, user_id, account_id, action, target_type, target_ref, result, request_id, metadata_json, created_at FROM audit_logs ORDER BY id DESC LIMIT ' . $limit);
    }

    /** @return list<array<string,mixed>> */
    public function securityEvents(int $adminId, int $limit = 100): array
    {
        $this->admin($adminId);
        $limit = max(1, min(500, $limit));
        return $this->database->all('SELECT id, user_id, account_id, event_type, severity, fingerprint, metadata_json, acknowledged_at, created_at FROM security_events ORDER BY id DESC LIMIT ' . $limit);
    }

    /** @return list<array<string,mixed>> */
    public function jobs(int $adminId, int $limit = 100): array
    {
        $this->admin($adminId);
        $limit = max(1, min(500, $limit));
        return $this->database->all('SELECT id, queue, job_type, user_id, account_id, status, progress, status_message, attempts, max_attempts, last_error_code, available_at, reserved_at, completed_at, created_at, updated_at FROM queue_jobs ORDER BY id DESC LIMIT ' . $limit);
    }

    /** @return list<array<string,mixed>> */
    public function failedJobs(int $adminId, int $limit = 100): array
    {
        $this->admin($adminId);
        $limit = max(1, min(500, $limit));
        return $this->database->all('SELECT id, original_job_id, queue, job_type, user_id, account_id, error_code, error_message_safe, failed_at FROM queue_failed_jobs ORDER BY id DESC LIMIT ' . $limit);
    }

    /** @return list<array<string,mixed>> */
    public function broadcasts(int $adminId, int $limit = 100): array
    {
        $this->admin($adminId);
        $limit = max(1, min(200, $limit));
        return $this->database->all('SELECT id, created_by, message_fa, message_en, status, target_filter_json, sent_count, failed_count, created_at, completed_at FROM admin_broadcasts ORDER BY id DESC LIMIT ' . $limit);
    }

    /** @return array{enabled:bool,message_fa:string,message_en:string} */
    public function maintenance(int $adminId): array
    {
        $this->admin($adminId);
        $row = $this->database->one("SELECT setting_value FROM settings WHERE setting_key = 'maintenance'");
        $value = $row === null ? null : json_decode((string) $row['setting_value'], true);
        return is_array($value) ? ['enabled' => ($value['enabled'] ?? false) === true, 'message_fa' => (string) ($value['message_fa'] ?? ''), 'message_en' => (string) ($value['message_en'] ?? '')] : ['enabled' => false, 'message_fa' => '', 'message_en' => ''];
    }

    public function retryFailedJob(int $adminId, int $failedJobId): int
    {
        $this->admin($adminId);
        $jobId = $this->queue->retryFailed($failedJobId);
        $this->audit->record($adminId, null, 'admin.queue_retry', 'success', 'job', (string) $jobId, ['failed_job_id' => $failedJobId]);
        return $jobId;
    }

    /** @return array<string,int> */
    public function systemSettings(int $adminId): array
    {
        $this->admin($adminId);
        $defaults = ['worker_max_jobs' => 20, 'notification_batch' => 25, 'audit_retention_days' => 365, 'log_retention_days' => 30];
        $rows = $this->database->all("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('worker_max_jobs','notification_batch','audit_retention_days','log_retention_days')");
        foreach ($rows as $row) {
            $value = json_decode((string) $row['setting_value'], true);
            if (is_int($value)) {
                $defaults[(string) $row['setting_key']] = $value;
            }
        }
        return $defaults;
    }

    /** @param array<string,mixed> $values
     *  @return array<string,int>
     */
    public function updateSystemSettings(int $adminId, array $values): array
    {
        $this->admin($adminId);
        $limits = [
            'worker_max_jobs' => [1, 100],
            'notification_batch' => [1, 100],
            'audit_retention_days' => [30, 3650],
            'log_retention_days' => [7, 365],
        ];
        $changed = [];
        foreach ($limits as $key => [$minimum, $maximum]) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = filter_var($values[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < $minimum || $value > $maximum) {
                throw new AppException('A system setting is outside its allowed range.', 422, 'invalid_system_setting', ['setting' => $key, 'minimum' => $minimum, 'maximum' => $maximum]);
            }
            $this->database->execute('INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)', [$key, json_encode($value, JSON_THROW_ON_ERROR), $adminId]);
            $changed[$key] = (int) $value;
        }
        if ($changed === []) {
            throw new AppException('No supported system setting was supplied.', 422, 'invalid_system_setting');
        }
        $this->audit->record($adminId, null, 'admin.settings_update', 'success', 'setting', implode(',', array_keys($changed)), ['values' => $changed]);
        return $this->systemSettings($adminId);
    }

    /** @return array<string,mixed> */
    public function health(int $adminId): array
    {
        $this->admin($adminId);
        $storage = $this->root . '/storage';
        $databaseStarted = microtime(true);
        $this->database->one('SELECT 1 AS ok');
        $databaseLatency = (int) round((microtime(true) - $databaseStarted) * 1000);
        $telegram = ['reachable' => false, 'url_matches' => false, 'pending_updates' => null, 'last_error_at' => null];
        try {
            $webhook = $this->telegram->call('getWebhookInfo');
            if (is_array($webhook)) {
                $telegram = [
                    'reachable' => true,
                    'url_matches' => hash_equals(rtrim((string) Config::app('url'), '/') . '/webhook/' . Env::require('WEBHOOK_SECRET'), (string) ($webhook['url'] ?? '')),
                    'pending_updates' => isset($webhook['pending_update_count']) ? (int) $webhook['pending_update_count'] : null,
                    'last_error_at' => isset($webhook['last_error_date']) ? gmdate('c', (int) $webhook['last_error_date']) : null,
                ];
            }
        } catch (\Throwable) {
        }
        $encryption = false;
        try {
            $plain = bin2hex(random_bytes(32));
            $encryption = hash_equals($plain, $this->crypto->decrypt($this->crypto->encrypt($plain, 'admin-health'), 'admin-health'));
        } catch (\Throwable) {
        }
        $heartbeatRow = $this->database->one("SELECT setting_value FROM settings WHERE setting_key = 'cron_heartbeat'");
        $heartbeat = $heartbeatRow === null ? null : json_decode((string) $heartbeatRow['setting_value'], true);
        $heartbeatTime = is_array($heartbeat) && is_string($heartbeat['ran_at'] ?? null) ? strtotime($heartbeat['ran_at']) : false;
        $storageChecks = [];
        foreach (['cache', 'logs', 'temp', 'sessions', 'locks', 'backups', 'downloads'] as $directory) {
            $storageChecks[$directory] = is_dir($storage . '/' . $directory) && is_writable($storage . '/' . $directory);
        }
        return [
            'app_version' => Config::app('version'),
            'php_version' => PHP_VERSION,
            'required_extensions' => array_combine(['curl', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'zip'], array_map('extension_loaded', ['curl', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'zip'])),
            'database' => ['reachable' => true, 'latency_ms' => $databaseLatency, 'schema_versions' => $this->database->all('SELECT version, applied_at FROM schema_migrations ORDER BY applied_at')],
            'telegram_webhook' => $telegram,
            'encryption_round_trip' => $encryption,
            'storage_writable' => !in_array(false, $storageChecks, true),
            'storage_paths' => $storageChecks,
            'disk_free_bytes' => @disk_free_space($storage) ?: null,
            'queue' => $this->database->all('SELECT status, COUNT(*) AS total FROM queue_jobs GROUP BY status'),
            'failed_jobs' => $this->database->one('SELECT COUNT(*) AS total FROM queue_failed_jobs'),
            'cron' => ['healthy' => $heartbeatTime !== false && $heartbeatTime >= time() - 600, 'last_run' => $heartbeat, 'command' => sprintf('%s %s/cli/cron.php --secret=%s', PHP_BINARY ?: '/usr/local/bin/php', $this->root, Env::require('CRON_SECRET'))],
            'recent_errors' => $this->database->all('SELECT route, method, status_code, duration_ms, created_at FROM api_request_metrics WHERE status_code >= 400 ORDER BY id DESC LIMIT 25'),
        ];
    }

    /** @return array<string,mixed> */
    private function admin(int $userId): array
    {
        $user = $this->database->one('SELECT id, status, is_super_admin FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['status'] !== 'active' || !(bool) $user['is_super_admin']) {
            throw new AppException('Super-admin permission is required.', 403, 'admin_required');
        }
        return $user;
    }
}
