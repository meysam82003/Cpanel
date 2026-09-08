<?php

declare(strict_types=1);

namespace App\Backup;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Notifications\NotificationService;

final class BackupJobTracker
{
    public function __construct(
        private readonly Database $database,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @param array<string,mixed> $result */
    public function completed(int $jobId, array $result = []): int
    {
        $rows = $this->linked($jobId, 'completed', ['queued', 'processing']);
        $updated = 0;
        foreach ($rows as $row) {
            $metadata = $this->metadata($row['metadata_json'] ?? null);
            $metadata['job_id'] = $jobId;
            if ($result !== []) {
                $metadata['job_result'] = $this->safeResult($result);
            }
            $size = $this->resultSize($result) ?? ($row['size_bytes'] === null ? null : (int) $row['size_bytes']);
            $providerRef = (string) $row['provider_ref'];
            if ((string) $row['type'] === 'database' && is_string($result['file'] ?? null) && basename((string) $result['file']) === $result['file']) {
                $providerRef = 'local:' . $result['file'];
            }
            $statement = $this->database->execute(
                "UPDATE backups SET status = 'completed', size_bytes = COALESCE(?, size_bytes), provider_ref = ?, metadata_json = ?, completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP) WHERE id = ? AND user_id = ? AND account_id = ? AND status IN ('queued', 'processing')",
                [$size, $providerRef, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $row['id'], $row['user_id'], $row['account_id']]
            );
            if ($statement->rowCount() !== 1) {
                continue;
            }
            $updated++;
            if ($this->needsNotification($row, $jobId)) {
                $this->notifications->queue((int) $row['user_id'], 'backup', 'notification.backup_title', 'notification.backup_done', ['target' => (string) $row['target']]);
            }
            $this->audit->record((int) $row['user_id'], (int) $row['account_id'], 'backup.job_completed', 'success', 'backup', (string) $row['id'], ['job_id' => $jobId, 'type' => (string) $row['type'], 'size_bytes' => $size]);
        }
        return $updated;
    }

    public function failed(int $jobId, string $errorCode): int
    {
        $rows = $this->linked($jobId, 'failed', ['queued', 'processing']);
        $updated = 0;
        foreach ($rows as $row) {
            $metadata = $this->metadata($row['metadata_json'] ?? null);
            $metadata['job_id'] = $jobId;
            $metadata['error_code'] = $errorCode;
            $statement = $this->database->execute(
                "UPDATE backups SET status = 'failed', metadata_json = ?, completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP) WHERE id = ? AND user_id = ? AND account_id = ? AND status IN ('queued', 'processing')",
                [json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $row['id'], $row['user_id'], $row['account_id']]
            );
            if ($statement->rowCount() !== 1) {
                continue;
            }
            $updated++;
            if ($this->needsNotification($row, $jobId)) {
                $this->notifications->queue((int) $row['user_id'], 'backup', 'notification.backup_title', 'notification.backup_failed', ['target' => (string) $row['target']]);
            }
            $this->audit->record((int) $row['user_id'], (int) $row['account_id'], 'backup.job_failed', 'failed', 'backup', (string) $row['id'], ['job_id' => $jobId, 'type' => (string) $row['type'], 'error_code' => $errorCode]);
        }
        return $updated;
    }

    /** @return array{queued:int,processing:int,completed:int,failed:int} */
    public function reconcile(int $userId, int $accountId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->database->all(
            "SELECT id, provider_ref FROM backups WHERE user_id = ? AND account_id = ? AND status IN ('queued', 'processing') AND provider_ref LIKE 'job:%' ORDER BY id LIMIT " . $limit,
            [$userId, $accountId]
        );
        $summary = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            if (!preg_match('/^job:(\d+)$/', (string) $row['provider_ref'], $match)) {
                continue;
            }
            $jobId = (int) $match[1];
            $job = $this->database->one('SELECT status, progress, last_error_code FROM queue_jobs WHERE id = ? AND user_id = ? AND account_id = ?', [$jobId, $userId, $accountId]);
            if ($job === null) {
                continue;
            }
            $status = (string) $job['status'];
            if ($status === 'completed') {
                $summary['completed'] += $this->completed($jobId);
                continue;
            }
            if ($status === 'failed') {
                $summary['failed'] += $this->failed($jobId, (string) ($job['last_error_code'] ?: 'queue_job_failed'));
                continue;
            }
            $backupStatus = $status === 'running' ? 'processing' : 'queued';
            $this->database->execute("UPDATE backups SET status = ? WHERE id = ? AND user_id = ? AND account_id = ? AND status IN ('queued', 'processing')", [$backupStatus, $row['id'], $userId, $accountId]);
            $summary[$backupStatus]++;
        }
        return $summary;
    }

    /** @param list<string> $statuses
     *  @return list<array<string,mixed>>
     */
    private function linked(int $jobId, string $jobStatus, array $statuses): array
    {
        $job = $this->database->one('SELECT user_id, account_id, status FROM queue_jobs WHERE id = ?', [$jobId]);
        if ($job === null || (string) $job['status'] !== $jobStatus || $job['user_id'] === null || $job['account_id'] === null) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        return $this->database->all(
            "SELECT * FROM backups WHERE provider_ref = ? AND user_id = ? AND account_id = ? AND status IN ($placeholders) ORDER BY id",
            array_merge(['job:' . $jobId, (int) $job['user_id'], (int) $job['account_id']], $statuses)
        );
    }

    /** @return array<string,mixed> */
    private function metadata(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $backup */
    private function needsNotification(array $backup, int $jobId): bool
    {
        if ((string) $backup['type'] !== 'directory') {
            return true;
        }
        $archive = $this->database->one(
            'SELECT notification_id FROM file_archive_jobs WHERE job_id = ? AND user_id = ? AND account_id = ?',
            [$jobId, $backup['user_id'], $backup['account_id']]
        );
        return $archive === null || $archive['notification_id'] === null;
    }

    /** @param array<string,mixed> $result
     *  @return array<string,mixed>
     */
    private function safeResult(array $result): array
    {
        $allowed = ['database', 'tables', 'rows', 'bytes', 'file', 'compression', 'destination', 'format', 'source_count', 'reconciled', 'completed', 'operation'];
        return array_intersect_key($result, array_fill_keys($allowed, true));
    }

    /** @param array<string,mixed> $result */
    private function resultSize(array $result): ?int
    {
        foreach (['bytes', 'output_bytes', 'size_bytes'] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key]) && (int) $result[$key] >= 0) {
                return (int) $result[$key];
            }
        }
        return null;
    }
}
