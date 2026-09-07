<?php

declare(strict_types=1);

namespace App\Backup;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;
use App\Database\SqlTransferService;
use App\FileManager\FileManagerService;
use App\Notifications\NotificationService;

final class BackupService
{
    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly FileManagerService $files,
        private readonly SqlTransferService $sqlTransfers,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return array<string,mixed> */
    public function list(int $userId, int $accountId): array
    {
        $this->accounts->getOwned($userId, $accountId);
        $this->reconcileAccount($userId, $accountId);
        $local = $this->database->all('SELECT id, type, target, remote_path, size_bytes, status, provider_ref, metadata_json, created_at, completed_at FROM backups WHERE user_id = ? AND account_id = ? ORDER BY id DESC LIMIT 200', [$userId, $accountId]);
        try {
            $provider = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Backup', 'list_backups')['data'];
        } catch (CpanelApiException $exception) {
            $provider = ['available' => false, 'error_code' => $exception->safeCode];
        }
        return ['managed' => $local, 'provider' => $provider];
    }

    /** @return array{id:int,cpanel:mixed} */
    public function generateFull(int $userId, int $accountId): array
    {
        $connection = $this->accounts->connection($userId, $accountId);
        $result = $this->cpanel->call($connection, 'Backup', 'fullbackup_to_homedir', [], 'POST', [], false);
        $metadata = [
            'provider' => 'cpanel',
            'requested_at' => gmdate('c'),
            'provider_response' => $result['data'],
        ];
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, status, metadata_json) VALUES (?, ?, 'full', 'home', 'requested', ?)", [$userId, $accountId, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $id = $this->database->lastInsertId();
        $this->audit->record($userId, $accountId, 'backup.full_request', 'success', 'backup', (string) $id, ['provider' => 'cpanel']);
        return ['id' => $id, 'cpanel' => $result['data']];
    }

    public function database(int $userId, int $accountId, string $database, array $tables = []): int
    {
        $jobId = $this->sqlTransfers->export($userId, $accountId, $database, $tables, 'full', 'gz');
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, status, provider_ref, metadata_json) VALUES (?, ?, 'database', ?, 'queued', ?, ?)", [$userId, $accountId, $database, 'job:' . $jobId, json_encode(['tables' => $tables], JSON_THROW_ON_ERROR)]);
        $this->audit->record($userId, $accountId, 'backup.database_queue', 'success', 'database', $database, ['job_id' => $jobId, 'selected_tables' => count($tables)]);
        return $jobId;
    }

    /** @return array{id:int,remote_path:string} */
    public function directory(int $userId, int $accountId, string $directory, ?string $destination = null): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $destination ??= $root . '/.tcm-backups/directory-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        $result = $this->files->compress($userId, $accountId, [$directory], $destination, 'zip');
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, remote_path, status, metadata_json, completed_at) VALUES (?, ?, 'directory', ?, ?, 'completed', ?, CURRENT_TIMESTAMP)", [$userId, $accountId, $directory, $result['destination'], json_encode(['format' => 'zip'], JSON_THROW_ON_ERROR)]);
        $id = $this->database->lastInsertId();
        $this->audit->record($userId, $accountId, 'backup.directory', 'success', 'directory', $directory, ['backup_id' => $id, 'remote_path' => $result['destination']]);
        $this->notifications->queue($userId, 'backup', 'notification.backup_title', 'notification.backup_done', ['target' => $directory]);
        return ['id' => $id, 'remote_path' => $result['destination']];
    }

    /**
     * Reconciles manual full-backup requests with cPanel's completed home-directory
     * archives. A candidate must be observed twice with an unchanged non-zero size,
     * which prevents exposing a tarball while cPanel is still writing it.
     *
     * @return array{checked:int,completed:int,failed:int}
     */
    public function reconcilePendingFull(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->database->all(
            "SELECT DISTINCT user_id, account_id FROM backups WHERE type = 'full' AND status IN ('requested', 'processing') ORDER BY id LIMIT " . $limit
        );
        $summary = ['checked' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $result = $this->reconcileAccount((int) $row['user_id'], (int) $row['account_id']);
            $summary['checked']++;
            $summary['completed'] += $result['completed'];
            $summary['failed'] += $result['failed'];
        }
        return $summary;
    }

    /** @return array{completed:int,failed:int} */
    private function reconcileAccount(int $userId, int $accountId): array
    {
        $pending = $this->database->all(
            "SELECT * FROM backups WHERE user_id = ? AND account_id = ? AND type = 'full' AND status IN ('requested', 'processing') ORDER BY id",
            [$userId, $accountId]
        );
        if ($pending === []) {
            return ['completed' => 0, 'failed' => 0];
        }

        $now = time();
        $failed = 0;
        foreach ($pending as $index => $row) {
            $created = strtotime((string) $row['created_at']) ?: $now;
            if ($created < $now - 172800) {
                $updated = $this->database->execute(
                    "UPDATE backups SET status = 'failed', completed_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('requested', 'processing')",
                    [$row['id']]
                );
                if ($updated->rowCount() > 0) {
                    $this->notifications->queue($userId, 'backup', 'notification.backup_title', 'notification.backup_failed', ['target' => 'Full Backup']);
                    $this->audit->record($userId, $accountId, 'backup.full_timeout', 'failed', 'backup', (string) $row['id']);
                    $failed++;
                }
                unset($pending[$index]);
            }
        }
        if ($pending === []) {
            return ['completed' => 0, 'failed' => $failed];
        }

        try {
            $account = $this->accounts->getOwned($userId, $accountId);
            $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
            $archives = $this->fullBackupArchives($userId, $accountId, $root);
        } catch (\Throwable $exception) {
            // A temporary token may have expired or the provider may be offline.
            // Keep requests pending so a later cron run or token rotation can resume.
            return ['completed' => 0, 'failed' => $failed];
        }

        $used = [];
        foreach ($this->database->all("SELECT remote_path FROM backups WHERE account_id = ? AND type = 'full' AND remote_path IS NOT NULL", [$accountId]) as $row) {
            $used[(string) $row['remote_path']] = true;
        }
        $completed = 0;
        foreach ($pending as $row) {
            $created = strtotime((string) $row['created_at']) ?: 0;
            $candidate = null;
            foreach ($archives as $archive) {
                if (isset($used[$archive['path']]) || $archive['mtime'] < $created - 600) {
                    continue;
                }
                $candidate = $archive;
                break;
            }
            if ($candidate === null) {
                continue;
            }
            $used[$candidate['path']] = true;
            $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $previous = is_array($metadata['candidate'] ?? null) ? $metadata['candidate'] : [];
            $stable = ($previous['path'] ?? null) === $candidate['path']
                && (int) ($previous['size'] ?? -1) === $candidate['size']
                && $candidate['size'] > 0
                && (strtotime((string) ($previous['observed_at'] ?? '')) ?: $now) <= $now - 30;
            if (!$stable) {
                $metadata['candidate'] = ['path' => $candidate['path'], 'size' => $candidate['size'], 'observed_at' => gmdate('c')];
                $this->database->execute(
                    "UPDATE backups SET status = 'processing', metadata_json = ? WHERE id = ? AND status IN ('requested', 'processing')",
                    [json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $row['id']]
                );
                continue;
            }
            $metadata['completed_archive'] = $candidate;
            unset($metadata['candidate']);
            $updated = $this->database->execute(
                "UPDATE backups SET status = 'completed', remote_path = ?, size_bytes = ?, provider_ref = ?, metadata_json = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('requested', 'processing')",
                [$candidate['path'], $candidate['size'], 'cpanel-home:' . basename($candidate['path']), json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $row['id']]
            );
            if ($updated->rowCount() > 0) {
                $this->notifications->queue($userId, 'backup', 'notification.backup_title', 'notification.backup_done', ['target' => 'Full Backup']);
                $this->audit->record($userId, $accountId, 'backup.full_completed', 'success', 'backup', (string) $row['id'], ['remote_path' => $candidate['path'], 'size_bytes' => $candidate['size']]);
                $completed++;
            }
        }
        return ['completed' => $completed, 'failed' => $failed];
    }

    /** @return list<array{path:string,size:int,mtime:int}> */
    private function fullBackupArchives(int $userId, int $accountId, string $root): array
    {
        $archives = [];
        for ($page = 1; $page <= 10; $page++) {
            $listing = $this->files->browse($userId, $accountId, $root, $page, 100, 'mtime', 'desc', false);
            foreach ($listing['items'] as $item) {
                $name = (string) ($item['file'] ?? $item['name'] ?? basename((string) ($item['path'] ?? '')));
                if (!preg_match('/^backup-[A-Za-z0-9._-]+\.tar\.gz$/i', $name)) {
                    continue;
                }
                $path = (string) ($item['path'] ?? $root . '/' . $name);
                $archives[] = [
                    'path' => $path,
                    'size' => max(0, (int) ($item['size'] ?? 0)),
                    'mtime' => $this->itemTimestamp($item, $name),
                ];
            }
            if (!(bool) $listing['pagination']['has_more']) {
                break;
            }
        }
        usort($archives, static fn (array $left, array $right): int => $right['mtime'] <=> $left['mtime']);
        return $archives;
    }

    /** @param array<string,mixed> $item */
    private function itemTimestamp(array $item, string $name): int
    {
        foreach (['mtime', 'mtime_epoch', 'modified', 'ctime', 'date'] as $field) {
            $value = $item[$field] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
            if (is_string($value) && ($parsed = strtotime($value)) !== false) {
                return $parsed;
            }
        }
        if (preg_match('/^backup-(\d{1,2})\.(\d{1,2})\.(\d{4})_(\d{1,2})-(\d{2})-(\d{2})_/', $name, $match)) {
            return gmmktime((int) $match[4], (int) $match[5], (int) $match[6], (int) $match[1], (int) $match[2], (int) $match[3]);
        }
        return 0;
    }

    /** @return array<string,mixed> */
    public function restoreDirectory(int $userId, int $accountId, int $backupId, string $destination): array
    {
        $backup = $this->owned($userId, $accountId, $backupId);
        if ($backup['type'] !== 'directory' || !is_string($backup['remote_path']) || $backup['remote_path'] === '') {
            throw new AppException('This backup cannot be restored as a directory archive.', 422, 'backup_not_restorable', [], 'backup');
        }
        $result = $this->files->extract($userId, $accountId, (string) $backup['remote_path'], $destination);
        $this->audit->record($userId, $accountId, 'backup.restore', 'success', 'backup', (string) $backupId, ['destination' => $destination]);
        return $result;
    }

    public function deleteRecord(int $userId, int $accountId, int $backupId, bool $deleteRemote): void
    {
        $backup = $this->owned($userId, $accountId, $backupId);
        $deletedPaths = [];
        if ($deleteRemote && is_string($backup['remote_path']) && $backup['remote_path'] !== '') {
            $this->files->delete($userId, $accountId, (string) $backup['remote_path'], true);
            $deletedPaths[] = (string) $backup['remote_path'];
        }
        $metadata = json_decode((string) ($backup['metadata_json'] ?? '{}'), true);
        $rollbackPath = is_array($metadata) && is_string($metadata['rollback_path'] ?? null) ? (string) $metadata['rollback_path'] : null;
        if ($deleteRemote && $rollbackPath !== null && $rollbackPath !== '') {
            try {
                $this->files->delete($userId, $accountId, $rollbackPath, true);
                $deletedPaths[] = $rollbackPath;
            } catch (AppException $exception) {
                if ($exception->safeCode !== 'remote_path_not_found') {
                    throw $exception;
                }
            }
        }
        $this->database->execute('DELETE FROM backups WHERE id = ? AND user_id = ? AND account_id = ?', [$backupId, $userId, $accountId]);
        $this->audit->record($userId, $accountId, 'backup.delete', 'success', 'backup', (string) $backupId, ['remote_deleted' => $deleteRemote, 'deleted_paths' => $deletedPaths]);
    }

    /** @return array<string,mixed> */
    private function owned(int $userId, int $accountId, int $backupId): array
    {
        $backup = $this->database->one('SELECT * FROM backups WHERE id = ? AND user_id = ? AND account_id = ?', [$backupId, $userId, $accountId]);
        if ($backup === null) {
            throw new AppException('Backup was not found or does not belong to you.', 404, 'backup_not_found', [], 'security.idor');
        }
        return $backup;
    }
}
