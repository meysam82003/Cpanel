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
use App\Deployment\DeploymentService;
use App\FileManager\ArchiveService;
use App\FileManager\FileManagerService;
use App\Notifications\NotificationService;

final class BackupService
{
    /** @param list<string> $localBackupRoots */
    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly FileManagerService $files,
        private readonly ArchiveService $archives,
        private readonly SqlTransferService $sqlTransfers,
        private readonly DeploymentService $deployments,
        private readonly BackupJobTracker $jobs,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly array $localBackupRoots,
    ) {
    }

    /** @return array<string,mixed> */
    public function list(int $userId, int $accountId): array
    {
        $this->accounts->getOwned($userId, $accountId);
        $this->jobs->reconcile($userId, $accountId);
        $this->reconcileAccount($userId, $accountId);
        $deploymentRows = $this->database->all('SELECT id, status, backup_ref, rollback_path, destination_existed FROM deployments WHERE user_id = ? AND account_id = ?', [$userId, $accountId]);
        $deploymentMap = [];
        foreach ($deploymentRows as $deployment) {
            $deploymentMap[(int) $deployment['id']] = $deployment;
        }
        $managed = [];
        foreach ($this->database->all('SELECT id, type, target, remote_path, size_bytes, status, provider_ref, metadata_json, created_at, completed_at FROM backups WHERE user_id = ? AND account_id = ? ORDER BY id DESC LIMIT 200', [$userId, $accountId]) as $row) {
            $managed[] = $this->decorateManaged($row, $deploymentMap);
        }
        $fileBackups = [];
        foreach ($this->database->all(
            'SELECT v.id, v.remote_path, v.version_number, v.backup_path, v.size_bytes, v.checksum_sha256, v.created_at, b.reason FROM file_versions v LEFT JOIN file_backups b ON b.user_id = v.user_id AND b.account_id = v.account_id AND b.remote_path = v.remote_path AND b.backup_path = v.backup_path WHERE v.user_id = ? AND v.account_id = ? ORDER BY v.id DESC LIMIT 200',
            [$userId, $accountId]
        ) as $row) {
            $fileBackups[] = $this->decorateFileVersion($row);
        }
        $groups = [
            'file_backups' => array_values(array_merge($fileBackups, array_filter($managed, static fn (array $backup): bool => $backup['type'] === 'directory'))),
            'database_backups' => array_values(array_filter($managed, static fn (array $backup): bool => $backup['type'] === 'database')),
            'deployment_backups' => array_values(array_filter($managed, static fn (array $backup): bool => $backup['type'] === 'deployment')),
            'full_backups' => array_values(array_filter($managed, static fn (array $backup): bool => $backup['type'] === 'full')),
        ];
        foreach ($groups as &$items) {
            usort($items, static fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']));
        }
        unset($items);
        try {
            $provider = $this->cpanel->call($this->accounts->connection($userId, $accountId), 'Backup', 'list_backups')['data'];
        } catch (CpanelApiException $exception) {
            $provider = ['available' => false, 'error_code' => $exception->safeCode];
        }
        return ['managed' => $managed, 'groups' => $groups, 'provider' => $provider];
    }

    /** @return array{id:int,cpanel:mixed,pending?:bool,ambiguous?:bool} */
    public function generateFull(int $userId, int $accountId): array
    {
        $this->accounts->getOwned($userId, $accountId);
        $active = $this->database->one("SELECT id FROM backups WHERE user_id = ? AND account_id = ? AND type = 'full' AND status IN ('requested', 'processing') AND created_at >= ? ORDER BY id DESC LIMIT 1", [$userId, $accountId, gmdate('Y-m-d H:i:s', time() - 172800)]);
        if ($active !== null) {
            return ['id' => (int) $active['id'], 'cpanel' => null, 'pending' => true];
        }
        // Resolve and decrypt the credential before recording a provider request;
        // failures here prove that nothing was sent to cPanel.
        $connection = $this->accounts->connection($userId, $accountId);
        $metadata = [
            'provider' => 'cpanel',
            'requested_at' => gmdate('c'),
            'provider_outcome' => 'dispatching',
        ];
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, status, metadata_json) VALUES (?, ?, 'full', 'home', 'requested', ?)", [$userId, $accountId, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $id = $this->database->lastInsertId();
        try {
            $result = $this->cpanel->call($connection, 'Backup', 'fullbackup_to_homedir', [], 'POST', [], false);
        } catch (AppException $exception) {
            $ambiguous = in_array($exception->safeCode, ['cpanel_network_error', 'cpanel_timeout', 'cpanel_http_error', 'cpanel_invalid_json', 'cpanel_invalid_response', 'cpanel_response_too_large', 'cpanel_request_failed'], true);
            $metadata['provider_outcome'] = $ambiguous ? 'ambiguous' : 'rejected';
            $metadata['provider_error'] = $exception->safeCode;
            $this->database->execute(
                "UPDATE backups SET status = ?, metadata_json = ?, completed_at = CASE WHEN ? = 'failed' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = ? AND user_id = ? AND account_id = ?",
                [$ambiguous ? 'processing' : 'failed', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $ambiguous ? 'processing' : 'failed', $id, $userId, $accountId]
            );
            $this->audit->record($userId, $accountId, $ambiguous ? 'backup.full_ambiguous' : 'backup.full_rejected', 'failed', 'backup', (string) $id, ['error_code' => $exception->safeCode, 'provider_outcome' => $metadata['provider_outcome']]);
            if ($ambiguous) {
                return ['id' => $id, 'cpanel' => null, 'pending' => true, 'ambiguous' => true];
            }
            $this->notifications->queue($userId, 'backup', 'notification.backup_title', 'notification.backup_failed', ['target' => 'Full Backup']);
            throw $exception;
        }
        $metadata['provider_outcome'] = 'accepted';
        $metadata['provider_response'] = $result['data'];
        $this->database->execute('UPDATE backups SET metadata_json = ? WHERE id = ? AND user_id = ? AND account_id = ?', [json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $id, $userId, $accountId]);
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

    /** @return array{id:int,job_id:int,status:string,remote_path:string} */
    public function directory(int $userId, int $accountId, string $directory, ?string $destination = null): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $directory = $this->normalizedRemoteDirectory($userId, $accountId, $directory);
        if ($directory === $root) {
            throw new AppException('Use cPanel Full Backup for the entire account home. Select a subdirectory for a ZIP backup.', 422, 'backup_directory_root_blocked', [], 'backup.overview');
        }
        if ($destination === null) {
            $this->ensureRemoteBackupDirectory($userId, $accountId, $root);
            $destination = $root . '/.tcm-backups/directory-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        }
        $queued = $this->archives->enqueueCreate($userId, $accountId, [$directory], $destination, 'zip');
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, remote_path, status, provider_ref, metadata_json) VALUES (?, ?, 'directory', ?, ?, 'queued', ?, ?)", [$userId, $accountId, $directory, $queued['destination'], 'job:' . $queued['job_id'], json_encode(['format' => 'zip', 'job_id' => $queued['job_id']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $id = $this->database->lastInsertId();
        $this->audit->record($userId, $accountId, 'backup.directory_queue', 'success', 'directory', $directory, ['backup_id' => $id, 'job_id' => $queued['job_id'], 'remote_path' => $queued['destination']]);
        return ['id' => $id, 'job_id' => (int) $queued['job_id'], 'status' => 'queued', 'remote_path' => $queued['destination']];
    }

    /**
     * Reconciles queued backup jobs and manual cPanel full-backup requests. A full
     * backup candidate must be observed twice with an unchanged non-zero size,
     * which prevents exposing a tarball while cPanel is still writing it.
     *
     * @return array{checked:int,completed:int,failed:int}
     */
    public function reconcilePendingFull(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->database->all(
            "SELECT DISTINCT user_id, account_id FROM backups WHERE status IN ('queued', 'requested', 'processing') ORDER BY id LIMIT " . $limit
        );
        $summary = ['checked' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $jobs = $this->jobs->reconcile((int) $row['user_id'], (int) $row['account_id']);
            $result = $this->reconcileAccount((int) $row['user_id'], (int) $row['account_id']);
            $summary['checked']++;
            $summary['completed'] += $result['completed'] + $jobs['completed'];
            $summary['failed'] += $result['failed'] + $jobs['failed'];
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
    public function restore(int $userId, int $accountId, int $backupId, string $destination): array
    {
        $backup = $this->owned($userId, $accountId, $backupId);
        if ((string) $backup['status'] !== 'completed') {
            throw new AppException('Only a completed backup can be restored.', 409, 'backup_not_ready', [], 'backup.overview');
        }
        $type = (string) $backup['type'];
        if ($type === 'directory') {
            if (!is_string($backup['remote_path']) || $backup['remote_path'] === '' || trim($destination) === '') {
                throw new AppException('This directory backup has no valid restore source or destination.', 422, 'backup_not_restorable', [], 'backup.overview');
            }
            $queued = $this->archives->enqueueExtract($userId, $accountId, (string) $backup['remote_path'], $destination, 'overwrite');
            $this->audit->record($userId, $accountId, 'backup.restore_queue', 'success', 'backup', (string) $backupId, ['type' => $type, 'job_id' => $queued['job_id'], 'destination' => $queued['destination']]);
            return ['type' => $type, 'backup_id' => $backupId] + $queued;
        }
        if ($type === 'database') {
            $database = trim($destination) !== '' ? trim($destination) : (string) $backup['target'];
            $local = $this->localBackupFile($backup);
            $metadata = $this->metadata($backup['metadata_json'] ?? null);
            $checksum = is_string($metadata['sha256'] ?? null) ? strtolower((string) $metadata['sha256']) : null;
            $jobId = $this->sqlTransfers->importBackup($userId, $accountId, $database, $local, $checksum);
            $this->audit->record($userId, $accountId, 'backup.restore_queue', 'success', 'backup', (string) $backupId, ['type' => $type, 'job_id' => $jobId, 'destination' => $database, 'pre_restore_backup' => true]);
            return ['type' => $type, 'backup_id' => $backupId, 'job_id' => $jobId, 'status' => 'queued', 'destination' => $database, 'pre_restore_backup' => true];
        }
        if ($type === 'deployment') {
            $metadata = $this->metadata($backup['metadata_json'] ?? null);
            $deploymentId = (int) ($metadata['deployment_id'] ?? 0);
            if ($deploymentId < 1) {
                throw new AppException('This deployment backup has no rollback reference.', 422, 'backup_not_restorable', [], 'deploy.rollback');
            }
            $jobId = $this->deployments->rollback($userId, $accountId, $deploymentId);
            $this->audit->record($userId, $accountId, 'backup.restore_queue', 'success', 'backup', (string) $backupId, ['type' => $type, 'job_id' => $jobId, 'deployment_id' => $deploymentId]);
            return ['type' => $type, 'backup_id' => $backupId, 'job_id' => $jobId, 'deployment_id' => $deploymentId, 'status' => 'queued'];
        }
        throw new AppException('This provider backup cannot be restored by a standard cPanel account API.', 422, 'backup_restore_provider_unsupported', [], 'backup.overview');
    }

    /** @return array<string,mixed> */
    public function fileVersion(int $userId, int $accountId, int $versionId): array
    {
        $this->accounts->getOwned($userId, $accountId);
        $version = $this->database->one('SELECT * FROM file_versions WHERE id = ? AND user_id = ? AND account_id = ?', [$versionId, $userId, $accountId]);
        if ($version === null) {
            throw new AppException('File backup was not found or does not belong to you.', 404, 'backup_not_found', [], 'security.idor');
        }
        return $version;
    }

    /** @return array{type:string,version_id:int,target:string} */
    public function restoreFileVersion(int $userId, int $accountId, int $versionId): array
    {
        $version = $this->fileVersion($userId, $accountId, $versionId);
        $this->files->restoreVersion($userId, $accountId, (string) $version['remote_path'], $versionId);
        $this->audit->record($userId, $accountId, 'backup.restore', 'success', 'file_backup', (string) $versionId, ['target' => (string) $version['remote_path'], 'version' => (int) $version['version_number']]);
        return ['type' => 'file', 'version_id' => $versionId, 'target' => (string) $version['remote_path']];
    }

    public function deleteFileVersion(int $userId, int $accountId, int $versionId, bool $deleteRemote): void
    {
        $version = $this->fileVersion($userId, $accountId, $versionId);
        $backupPath = (string) $version['backup_path'];
        if ($deleteRemote) {
            try {
                $this->files->delete($userId, $accountId, $backupPath, true);
            } catch (AppException $exception) {
                if ($exception->safeCode !== 'remote_path_not_found') {
                    throw $exception;
                }
            }
        }
        $this->database->transaction(function (Database $database) use ($userId, $accountId, $versionId, $version, $backupPath): void {
            $database->execute('DELETE FROM file_versions WHERE id = ? AND user_id = ? AND account_id = ?', [$versionId, $userId, $accountId]);
            $database->execute('DELETE FROM file_backups WHERE user_id = ? AND account_id = ? AND remote_path = ? AND backup_path = ?', [$userId, $accountId, $version['remote_path'], $backupPath]);
        });
        $this->audit->record($userId, $accountId, 'backup.delete', 'success', 'file_backup', (string) $versionId, ['remote_deleted' => $deleteRemote, 'target' => (string) $version['remote_path']]);
    }

    public function deleteRecord(int $userId, int $accountId, int $backupId, bool $deleteRemote): void
    {
        $backup = $this->owned($userId, $accountId, $backupId);
        if (in_array((string) $backup['status'], ['queued', 'processing', 'requested'], true)) {
            throw new AppException('An active backup record cannot be deleted until its operation finishes.', 409, 'backup_operation_active', [], 'backup.overview');
        }
        $this->assertBackupNotRestoring($userId, $accountId, $backup);
        $deletedPaths = [];
        if ($deleteRemote && is_string($backup['remote_path']) && $backup['remote_path'] !== '') {
            try {
                $this->files->delete($userId, $accountId, (string) $backup['remote_path'], true);
            } catch (AppException $exception) {
                if ($exception->safeCode !== 'remote_path_not_found') {
                    throw $exception;
                }
            }
            $deletedPaths[] = (string) $backup['remote_path'];
        }
        if ($deleteRemote && is_string($backup['provider_ref']) && str_starts_with($backup['provider_ref'], 'local:')) {
            $local = $this->localBackupFile($backup, false);
            if ($local !== null && is_file($local) && !unlink($local)) {
                throw new AppException('The managed backup file could not be deleted.', 500, 'backup_delete_failed', [], 'backup.overview');
            }
            if ($local !== null) {
                $deletedPaths[] = basename($local);
            }
        }
        $metadata = $this->metadata($backup['metadata_json'] ?? null);
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
        if ($deleteRemote && (string) $backup['type'] === 'deployment' && (int) ($metadata['deployment_id'] ?? 0) > 0) {
            $this->database->execute(
                'UPDATE deployments SET backup_ref = NULL, backup_size = NULL, backup_checksum = NULL, rollback_path = NULL WHERE id = ? AND user_id = ? AND account_id = ?',
                [(int) $metadata['deployment_id'], $userId, $accountId]
            );
        }
        $this->database->execute('DELETE FROM backups WHERE id = ? AND user_id = ? AND account_id = ?', [$backupId, $userId, $accountId]);
        $this->audit->record($userId, $accountId, 'backup.delete', 'success', 'backup', (string) $backupId, ['remote_deleted' => $deleteRemote, 'deleted_paths' => $deletedPaths]);
    }

    /** @param array<string,mixed> $row
     *  @param array<int,array<string,mixed>> $deploymentMap
     *  @return array<string,mixed>
     */
    private function decorateManaged(array $row, array $deploymentMap): array
    {
        $metadata = $this->metadata($row['metadata_json'] ?? null);
        $type = (string) $row['type'];
        $completed = (string) $row['status'] === 'completed';
        $local = is_string($row['provider_ref'] ?? null) && str_starts_with((string) $row['provider_ref'], 'local:');
        $remote = is_string($row['remote_path'] ?? null) && $row['remote_path'] !== '';
        $restorable = false;
        $restoreKind = null;
        if ($completed && $type === 'directory' && $remote) {
            $restorable = true;
            $restoreKind = 'directory';
        } elseif ($completed && $type === 'database' && $local) {
            $restorable = true;
            $restoreKind = 'database';
        } elseif ($completed && $type === 'deployment') {
            $deployment = $deploymentMap[(int) ($metadata['deployment_id'] ?? 0)] ?? null;
            if (is_array($deployment)) {
                $hasRollback = (is_string($deployment['rollback_path'] ?? null) && $deployment['rollback_path'] !== '')
                    || (is_string($deployment['backup_ref'] ?? null) && $deployment['backup_ref'] !== '')
                    || !(bool) ($deployment['destination_existed'] ?? true);
                $restorable = in_array((string) $deployment['status'], ['completed', 'rollback_failed'], true) && $hasRollback;
                $restoreKind = $restorable ? 'deployment' : null;
            }
        }
        $jobId = null;
        if (is_string($row['provider_ref'] ?? null) && preg_match('/^job:(\d+)$/', (string) $row['provider_ref'], $match)) {
            $jobId = (int) $match[1];
        }
        unset($row['metadata_json']);
        $row['record_kind'] = 'managed';
        $row['record_id'] = (int) $row['id'];
        $row['key'] = 'managed:' . $row['id'];
        $row['metadata'] = $metadata;
        $row['job_id'] = $jobId;
        $row['actions'] = [
            'download' => $completed && ($local || $remote),
            'restore' => $restorable,
            'restore_kind' => $restoreKind,
            'delete' => !in_array((string) $row['status'], ['queued', 'processing', 'requested'], true),
        ];
        return $row;
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function decorateFileVersion(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'record_kind' => 'file_version',
            'record_id' => (int) $row['id'],
            'key' => 'file:' . $row['id'],
            'type' => 'file',
            'target' => (string) $row['remote_path'],
            'remote_path' => (string) $row['backup_path'],
            'size_bytes' => $row['size_bytes'] === null ? null : (int) $row['size_bytes'],
            'status' => 'completed',
            'provider_ref' => null,
            'created_at' => (string) $row['created_at'],
            'completed_at' => (string) $row['created_at'],
            'metadata' => [
                'version_number' => (int) $row['version_number'],
                'reason' => (string) ($row['reason'] ?? 'editor_save'),
                'sha256' => $row['checksum_sha256'],
            ],
            'job_id' => null,
            'actions' => ['download' => true, 'restore' => true, 'restore_kind' => 'file', 'delete' => true],
        ];
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
    private function localBackupFile(array $backup, bool $required = true): ?string
    {
        $reference = is_string($backup['provider_ref'] ?? null) ? (string) $backup['provider_ref'] : '';
        $filename = str_starts_with($reference, 'local:') ? substr($reference, 6) : '';
        if ($filename === '' || basename($filename) !== $filename) {
            if (!$required) {
                return null;
            }
            throw new AppException('The managed backup storage reference is invalid.', 500, 'backup_storage_invalid', [], 'security.path');
        }
        foreach ($this->localBackupRoots as $root) {
            $candidate = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
            $realRoot = realpath($root);
            $real = realpath($candidate);
            if ($realRoot !== false && $real !== false && is_file($real) && str_starts_with($real, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        if (!$required) {
            return null;
        }
        throw new AppException('The managed backup file has expired or is unavailable.', 410, 'backup_file_unavailable', [], 'backup.overview');
    }

    private function normalizedRemoteDirectory(int $userId, int $accountId, string $directory): string
    {
        $info = $this->files->info($userId, $accountId, $directory);
        if (!in_array(strtolower((string) ($info['type'] ?? '')), ['dir', 'directory'], true) || (bool) ($info['is_symlink'] ?? false)) {
            throw new AppException('Directory backup requires a regular directory source.', 422, 'backup_directory_invalid', [], 'backup.overview');
        }
        return (string) ($info['path'] ?? $directory);
    }

    private function ensureRemoteBackupDirectory(int $userId, int $accountId, string $root): void
    {
        $path = $root . '/.tcm-backups';
        try {
            $info = $this->files->info($userId, $accountId, $path);
            if (!in_array(strtolower((string) ($info['type'] ?? '')), ['dir', 'directory'], true) || (bool) ($info['is_symlink'] ?? false)) {
                throw new AppException('Managed backup storage is not a regular directory.', 409, 'backup_remote_storage_invalid', [], 'security.path');
            }
        } catch (AppException $exception) {
            if ($exception->safeCode !== 'remote_path_not_found') {
                throw $exception;
            }
            $this->files->createFolder($userId, $accountId, $root, '.tcm-backups', '0700');
        }
    }

    /** @param array<string,mixed> $backup */
    private function assertBackupNotRestoring(int $userId, int $accountId, array $backup): void
    {
        if ((string) $backup['type'] === 'directory' && is_string($backup['remote_path']) && $backup['remote_path'] !== '') {
            $active = $this->database->one(
                "SELECT a.job_id FROM file_archive_jobs a JOIN queue_jobs q ON q.id = a.job_id WHERE a.user_id = ? AND a.account_id = ? AND a.operation = 'extract' AND a.archive_path = ? AND q.status IN ('queued', 'running') LIMIT 1",
                [$userId, $accountId, $backup['remote_path']]
            );
            if ($active !== null) {
                throw new AppException('This backup is being restored and cannot be deleted yet.', 409, 'backup_restore_active', [], 'backup.overview');
            }
        }
        if ((string) $backup['type'] === 'deployment') {
            $metadata = $this->metadata($backup['metadata_json'] ?? null);
            $deploymentId = (int) ($metadata['deployment_id'] ?? 0);
            if ($deploymentId > 0) {
                $active = $this->database->one("SELECT q.id FROM deployments d JOIN queue_jobs q ON q.id = d.rollback_job_id WHERE d.id = ? AND d.user_id = ? AND d.account_id = ? AND q.status IN ('queued', 'running')", [$deploymentId, $userId, $accountId]);
                if ($active !== null) {
                    throw new AppException('This deployment backup is being restored and cannot be deleted yet.', 409, 'backup_restore_active', [], 'backup.overview');
                }
            }
        }
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
