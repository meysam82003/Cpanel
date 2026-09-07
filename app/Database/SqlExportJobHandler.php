<?php

declare(strict_types=1);

namespace App\Database;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Queue\JobContext;
use App\Queue\JobHandler;
final class SqlExportJobHandler implements JobHandler
{
    public function __construct(
        private readonly DirectDatabaseConnectionService $connections,
        private readonly DatabaseDumpWriter $dumps,
        private readonly Database $database,
        private readonly AuditLogger $audit,
        private readonly string $downloadRoot,
    )
    {
    }

    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId() ?? throw new AppException('Export job has no owner.', 500, 'queue_owner_missing');
        $accountId = $context->accountId() ?? throw new AppException('Export job has no host.', 500, 'queue_account_missing');
        $database = (string) ($payload['database'] ?? '');
        $mode = in_array($payload['mode'] ?? '', ['full', 'structure', 'data'], true) ? (string) $payload['mode'] : 'full';
        $compression = ($payload['compression'] ?? 'gz') === 'none' ? 'none' : 'gz';
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $requested = is_array($payload['tables'] ?? null) ? array_values(array_map('strval', $payload['tables'])) : [];
        if (!is_dir($this->downloadRoot) && !mkdir($this->downloadRoot, 0700, true) && !is_dir($this->downloadRoot)) {
            throw new AppException('Secure download storage is unavailable.', 500, 'download_storage_unavailable');
        }
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '_', $database) . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
        $path = rtrim($this->downloadRoot, '/') . '/' . $base . ($compression === 'gz' ? '.gz' : '');
        $context->progress(5, 'preparing_export');
        $stats = $this->dumps->write($pdo, $database, $requested, $mode, $compression, $path, static function (int $percentage, string $message) use ($context): void {
            $context->progress(min(95, 5 + (int) floor($percentage * 0.9)), $message);
        });
        $this->audit->record($userId, $accountId, 'sql.export', 'success', 'database', $database, ['tables' => $stats['tables'], 'rows' => $stats['rows'], 'bytes' => $stats['bytes'], 'mode' => $mode, 'sha256' => $stats['sha256']]);
        $backupUpdated = $this->database->execute(
            "UPDATE backups SET status = 'completed', size_bytes = ?, provider_ref = ?, metadata_json = ?, completed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND account_id = ? AND provider_ref = ? AND status = 'queued'",
            [$stats['bytes'], 'local:' . basename($path), json_encode(['mode' => $mode, 'tables' => $stats['tables'], 'rows' => $stats['rows'], 'sha256' => $stats['sha256']], JSON_THROW_ON_ERROR), $userId, $accountId, 'job:' . $context->job['id']]
        );
        if ($backupUpdated->rowCount() > 0) {
            $this->database->execute("INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, 'backup', 'notification.backup_title', 'notification.backup_done', ?)", [$userId, json_encode(['target' => $database], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        $context->progress(99, 'finalizing');
        return ['database' => $database, 'tables' => $stats['tables'], 'rows' => $stats['rows'], 'bytes' => $stats['bytes'], 'sha256' => $stats['sha256'], 'file' => basename($path), 'compression' => $compression];
    }
}
