<?php

declare(strict_types=1);

namespace App\Queue;

use App\Core\Database;

final class CleanupService
{
    public function __construct(private readonly Database $database, private readonly string $storageRoot)
    {
    }

    /** @return array<string,int> */
    public function run(): array
    {
        $counts = [];
        foreach (['temporary_connections', 'callback_states', 'confirmation_nonces', 'miniapp_sessions', 'replay_nonces', 'rate_limits', 'download_tokens', 'operation_locks'] as $table) {
            $column = $table === 'miniapp_sessions' ? 'expires_at' : 'expires_at';
            $statement = $this->database->execute("DELETE FROM {$table} WHERE {$column} < CURRENT_TIMESTAMP");
            $counts[$table] = $statement->rowCount();
        }
        $expiredPackages = $this->database->all('SELECT id, local_path FROM deployment_packages WHERE expires_at < CURRENT_TIMESTAMP');
        foreach ($expiredPackages as $package) {
            $path = (string) $package['local_path'];
            if ($this->insideStorage($path) && is_file($path) && @unlink($path)) {
                $counts['deployment_package_files'] = ($counts['deployment_package_files'] ?? 0) + 1;
            }
        }
        $counts['deployment_packages'] = $this->database->execute('DELETE FROM deployment_packages WHERE expires_at < CURRENT_TIMESTAMP')->rowCount();
        $counts['telegram_updates'] = $this->database->execute("DELETE FROM telegram_updates WHERE (processed_at IS NOT NULL AND processed_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)) OR received_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)")->rowCount();
        $counts['api_metrics'] = $this->database->execute('DELETE FROM api_request_metrics WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY)')->rowCount();
        $counts['completed_jobs'] = $this->database->execute("DELETE FROM queue_jobs WHERE status = 'completed' AND completed_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)")->rowCount();
        $auditDays = $this->setting('audit_retention_days', 365, 30, 3650);
        $counts['audit_logs'] = $this->database->execute('DELETE FROM audit_logs WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $auditDays . ' DAY)')->rowCount();
        $counts['recent_actions'] = $this->database->execute('DELETE FROM recent_actions WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY)')->rowCount();
        $threshold = time() - 86400;
        foreach (glob(rtrim($this->storageRoot, '/') . '/temp/*') ?: [] as $path) {
            if (is_file($path) && filemtime($path) !== false && filemtime($path) < $threshold) {
                @unlink($path);
                $counts['temp_files'] = ($counts['temp_files'] ?? 0) + 1;
            }
        }
        $logThreshold = time() - ($this->setting('log_retention_days', 30, 7, 365) * 86400);
        foreach (glob(rtrim($this->storageRoot, '/') . '/logs/*.log') ?: [] as $path) {
            if (is_file($path) && filemtime($path) !== false && filemtime($path) < $logThreshold && @unlink($path)) {
                $counts['log_files'] = ($counts['log_files'] ?? 0) + 1;
            }
        }
        return $counts;
    }

    private function setting(string $key, int $default, int $minimum, int $maximum): int
    {
        $row = $this->database->one('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        $value = $row === null ? null : json_decode((string) $row['setting_value'], true);
        return is_int($value) ? max($minimum, min($maximum, $value)) : $default;
    }

    private function insideStorage(string $path): bool
    {
        $storage = rtrim(str_replace('\\', '/', $this->storageRoot), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $storage) && !str_contains($normalized, '/../');
    }
}
