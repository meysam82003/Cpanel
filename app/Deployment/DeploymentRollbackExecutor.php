<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Core\AppException;
use Throwable;

final class DeploymentRollbackExecutor
{
    public function __construct(
        private readonly DeploymentFilesystem $filesystem,
        private readonly DeploymentBackupVerifier $backups,
    ) {
    }

    /**
     * @param array<string,mixed> $deployment
     * @param null|callable(string,array<string,mixed>):void $checkpoint
     * @return array{destination:string,backup_ref:?string,method:string,reconciled:bool,cleanup_pending:bool}
     */
    public function restore(int $userId, int $accountId, array $deployment, ?callable $checkpoint = null): array
    {
        $deploymentId = (int) ($deployment['id'] ?? 0);
        $destination = (string) ($deployment['destination'] ?? '');
        if ($deploymentId < 1 || $destination === '' || basename($destination) === '' || $destination === '/') {
            throw new AppException('Deployment rollback destination is invalid.', 500, 'rollback_destination_invalid');
        }
        $root = $this->managedRoot($deployment);
        $recoveryDirectory = $root . '/.tcm-rollbacks';
        $quarantine = $recoveryDirectory . '/deployment-' . $deploymentId . '-current';
        $this->filesystem->ensureDirectory($userId, $accountId, $recoveryDirectory);

        $state = (string) ($deployment['switch_state'] ?? 'rollback_pending');
        $rollbackPath = $this->nullablePath($deployment['rollback_path'] ?? null);
        $backup = $this->nullablePath($deployment['backup_ref'] ?? null);

        if (str_starts_with($state, 'rollback_dir_')) {
            if ($rollbackPath === null) {
                throw new AppException('The durable directory rollback path is invalid.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
            }
            return $this->restoreDirectory($userId, $accountId, $destination, $rollbackPath, $quarantine, $state, $checkpoint);
        }
        if (str_starts_with($state, 'rollback_remove_')) {
            return $this->removeNewDestination($userId, $accountId, $destination, $quarantine, $state, $checkpoint);
        }
        if (str_starts_with($state, 'rollback_archive_')) {
            if ($backup === null) {
                throw new AppException('The durable archive rollback path is invalid.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
            }
            $this->backups->verify($userId, $accountId, $backup, $destination, $this->nullableChecksum($deployment['backup_checksum'] ?? null));
            return $this->restoreArchive($userId, $accountId, $deploymentId, $destination, $backup, $quarantine, $recoveryDirectory, $state, $checkpoint);
        }

        if ($rollbackPath !== null) {
            $rollbackInfo = $this->filesystem->info($userId, $accountId, $rollbackPath);
            if ($rollbackInfo !== null) {
                $this->assertDirectory($rollbackInfo, 'rollback_point_invalid');
                return $this->restoreDirectory($userId, $accountId, $destination, $rollbackPath, $quarantine, 'rollback_pending', $checkpoint);
            }
        }
        if (!(bool) ($deployment['destination_existed'] ?? true)) {
            return $this->removeNewDestination($userId, $accountId, $destination, $quarantine, 'rollback_pending', $checkpoint);
        }
        if ($backup === null) {
            throw new AppException('No verified rollback directory or archive is available.', 422, 'rollback_unavailable', [], 'deployment.rollback');
        }
        $this->backups->verify($userId, $accountId, $backup, $destination, $this->nullableChecksum($deployment['backup_checksum'] ?? null));
        return $this->restoreArchive($userId, $accountId, $deploymentId, $destination, $backup, $quarantine, $recoveryDirectory, 'rollback_pending', $checkpoint);
    }

    /**
     * @param null|callable(string,array<string,mixed>):void $checkpoint
     * @return array{destination:string,backup_ref:string,method:string,reconciled:bool,cleanup_pending:bool}
     */
    private function restoreDirectory(int $userId, int $accountId, string $destination, string $rollbackPath, string $quarantine, string $state, ?callable $checkpoint): array
    {
        [$state, $reconciled] = $this->quarantineLiveRelease($userId, $accountId, $destination, $quarantine, 'rollback_dir', $state, $checkpoint);
        try {
            [$state, $restoreReconciled] = $this->activateRollbackSource($userId, $accountId, $rollbackPath, $destination, 'rollback_dir', $state, $checkpoint);
            $reconciled = $reconciled || $restoreReconciled;
        } catch (Throwable $exception) {
            if ($this->restoreQuarantinedRelease($userId, $accountId, $destination, $quarantine)) {
                $this->checkpoint($checkpoint, 'rollback_dir_ready', ['method' => 'atomic_directory_restore', 'release_recovered' => true]);
            }
            throw $exception;
        }
        $this->assertDirectory($this->filesystem->info($userId, $accountId, $destination), 'rollback_verification_failed');
        $cleanupPending = !$this->deleteIfPresent($userId, $accountId, $quarantine);
        return ['destination' => $destination, 'backup_ref' => $rollbackPath, 'method' => 'atomic_directory_restore', 'reconciled' => $reconciled, 'cleanup_pending' => $cleanupPending];
    }

    /**
     * @param null|callable(string,array<string,mixed>):void $checkpoint
     * @return array{destination:string,backup_ref:null,method:string,reconciled:bool,cleanup_pending:bool}
     */
    private function removeNewDestination(int $userId, int $accountId, string $destination, string $quarantine, string $state, ?callable $checkpoint): array
    {
        $pending = 'rollback_remove_pending';
        $removed = 'rollback_remove_complete';
        $destinationInfo = $this->filesystem->info($userId, $accountId, $destination);
        $quarantineInfo = $this->filesystem->info($userId, $accountId, $quarantine);
        if ($destinationInfo !== null) {
            $this->assertDirectory($destinationInfo, 'rollback_destination_invalid');
        }
        if ($quarantineInfo !== null) {
            $this->assertDirectory($quarantineInfo, 'rollback_quarantine_invalid');
        }
        if ($destinationInfo !== null && $quarantineInfo !== null) {
            throw new AppException('Rollback cannot choose between the live and quarantined new release.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
        }
        $reconciled = $state === $pending && $destinationInfo === null && $quarantineInfo !== null;
        if ($state !== $removed) {
            if ($destinationInfo !== null) {
                $this->checkpoint($checkpoint, $pending, ['method' => 'remove_new_destination']);
                $reconciled = $this->moveVerified($userId, $accountId, $destination, $quarantine, 'rollback_quarantine_failed') || $reconciled;
            }
            if ($this->filesystem->info($userId, $accountId, $destination) !== null) {
                throw new AppException('The newly deployed destination is still present after rollback.', 502, 'rollback_verification_failed', [], 'deployment.rollback');
            }
            $this->checkpoint($checkpoint, $removed, ['method' => 'remove_new_destination']);
        } elseif ($destinationInfo !== null) {
            throw new AppException('A completed new-destination rollback still has a live destination.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
        }
        $cleanupPending = !$this->deleteIfPresent($userId, $accountId, $quarantine);
        return ['destination' => $destination, 'backup_ref' => null, 'method' => 'remove_new_destination', 'reconciled' => $reconciled, 'cleanup_pending' => $cleanupPending];
    }

    /**
     * @param null|callable(string,array<string,mixed>):void $checkpoint
     * @return array{destination:string,backup_ref:string,method:string,reconciled:bool,cleanup_pending:bool}
     */
    private function restoreArchive(int $userId, int $accountId, int $deploymentId, string $destination, string $backup, string $quarantine, string $recoveryDirectory, string $state, ?callable $checkpoint): array
    {
        $restoreRoot = $recoveryDirectory . '/deployment-' . $deploymentId . '-archive-restore';
        $candidate = $restoreRoot . '/' . basename($destination);
        $extractPending = 'rollback_archive_extract_pending';
        $extracted = 'rollback_archive_extracted';
        $reconciled = false;

        if (!str_starts_with($state, 'rollback_archive_')) {
            if ($this->filesystem->info($userId, $accountId, $restoreRoot) !== null) {
                throw new AppException('Archive rollback staging already exists without a durable checkpoint.', 409, 'rollback_reconciliation_required', ['phase' => 'archive_extract'], 'deployment.rollback');
            }
            $state = 'rollback_archive_ready';
            $this->checkpoint($checkpoint, $state, ['method' => 'staged_archive_restore']);
        }

        if (in_array($state, ['rollback_archive_ready', $extractPending], true)) {
            $rootInfo = $this->filesystem->info($userId, $accountId, $restoreRoot);
            $candidateInfo = $this->filesystem->info($userId, $accountId, $candidate);
            if ($state === $extractPending && $candidateInfo !== null) {
                $this->assertDirectory($candidateInfo, 'rollback_backup_layout_invalid');
                $reconciled = true;
            } else {
                if ($rootInfo !== null) {
                    throw new AppException('Interrupted archive extraction has no complete staged destination.', 409, 'rollback_reconciliation_required', ['phase' => 'archive_extract'], 'deployment.rollback');
                }
                $this->checkpoint($checkpoint, $extractPending, ['method' => 'staged_archive_restore']);
                $this->filesystem->ensureDirectory($userId, $accountId, $restoreRoot);
                try {
                    $this->filesystem->extract($userId, $accountId, $backup, $restoreRoot);
                } catch (Throwable $exception) {
                    $candidateInfo = $this->filesystem->info($userId, $accountId, $candidate);
                    if ($candidateInfo === null) {
                        throw new AppException('Rollback archive extraction has an ambiguous provider outcome.', 409, 'rollback_reconciliation_required', ['phase' => 'archive_extract', 'provider_error' => $this->safeCode($exception)], 'deployment.rollback');
                    }
                    $reconciled = true;
                }
                $candidateInfo = $this->filesystem->info($userId, $accountId, $candidate);
                $this->assertDirectory($candidateInfo, 'rollback_backup_layout_invalid');
            }
            $state = $extracted;
            $this->checkpoint($checkpoint, $state, ['method' => 'staged_archive_restore']);
        }

        if (in_array($state, [$extracted, 'rollback_archive_quarantine_pending', 'rollback_archive_quarantined'], true)) {
            $this->assertDirectory($this->filesystem->info($userId, $accountId, $candidate), 'rollback_backup_layout_invalid');
        }
        [$state, $quarantineReconciled] = $this->quarantineLiveRelease($userId, $accountId, $destination, $quarantine, 'rollback_archive', $state, $checkpoint);
        $reconciled = $reconciled || $quarantineReconciled;
        try {
            [$state, $restoreReconciled] = $this->activateRollbackSource($userId, $accountId, $candidate, $destination, 'rollback_archive', $state, $checkpoint);
            $reconciled = $reconciled || $restoreReconciled;
        } catch (Throwable $exception) {
            if ($this->restoreQuarantinedRelease($userId, $accountId, $destination, $quarantine)) {
                $this->checkpoint($checkpoint, $extracted, ['method' => 'staged_archive_restore', 'release_recovered' => true]);
            }
            throw $exception;
        }
        $this->assertDirectory($this->filesystem->info($userId, $accountId, $destination), 'rollback_verification_failed');
        $cleanupPending = !$this->deleteIfPresent($userId, $accountId, $restoreRoot);
        $cleanupPending = !$this->deleteIfPresent($userId, $accountId, $quarantine) || $cleanupPending;
        return ['destination' => $destination, 'backup_ref' => $backup, 'method' => 'staged_archive_restore', 'reconciled' => $reconciled, 'cleanup_pending' => $cleanupPending];
    }

    /**
     * @param null|callable(string,array<string,mixed>):void $checkpoint
     * @return array{0:string,1:bool}
     */
    private function quarantineLiveRelease(int $userId, int $accountId, string $destination, string $quarantine, string $prefix, string $state, ?callable $checkpoint): array
    {
        $pending = $prefix . '_quarantine_pending';
        $quarantined = $prefix . '_quarantined';
        $restorePending = $prefix . '_restore_pending';
        $restored = $prefix . '_restored';
        if (in_array($state, [$restorePending, $restored], true)) {
            return [$state, false];
        }
        $destinationInfo = $this->filesystem->info($userId, $accountId, $destination);
        $quarantineInfo = $this->filesystem->info($userId, $accountId, $quarantine);
        if ($destinationInfo !== null) {
            $this->assertDirectory($destinationInfo, 'rollback_destination_invalid');
        }
        if ($quarantineInfo !== null) {
            $this->assertDirectory($quarantineInfo, 'rollback_quarantine_invalid');
        }
        if ($destinationInfo !== null && $quarantineInfo !== null) {
            throw new AppException('Rollback found both a live destination and an earlier quarantine directory.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
        }
        $reconciled = $state === $pending && $destinationInfo === null && $quarantineInfo !== null;
        if ($state !== $quarantined) {
            if ($destinationInfo !== null) {
                $this->checkpoint($checkpoint, $pending, ['source' => $destination, 'destination' => $quarantine]);
                $reconciled = $this->moveVerified($userId, $accountId, $destination, $quarantine, 'rollback_quarantine_failed') || $reconciled;
            }
            if ($this->filesystem->info($userId, $accountId, $destination) !== null) {
                throw new AppException('Live release quarantine could not be verified.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
            }
            $state = $quarantined;
            $this->checkpoint($checkpoint, $state, ['quarantine' => $quarantine]);
        } elseif ($destinationInfo !== null) {
            throw new AppException('A quarantined rollback unexpectedly still has a live destination.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
        }
        return [$state, $reconciled];
    }

    /**
     * @param null|callable(string,array<string,mixed>):void $checkpoint
     * @return array{0:string,1:bool}
     */
    private function activateRollbackSource(int $userId, int $accountId, string $source, string $destination, string $prefix, string $state, ?callable $checkpoint): array
    {
        $pending = $prefix . '_restore_pending';
        $restored = $prefix . '_restored';
        $sourceInfo = $this->filesystem->info($userId, $accountId, $source);
        $destinationInfo = $this->filesystem->info($userId, $accountId, $destination);
        if ($sourceInfo !== null) {
            $this->assertDirectory($sourceInfo, 'rollback_point_invalid');
        }
        if ($destinationInfo !== null) {
            $this->assertDirectory($destinationInfo, 'rollback_destination_invalid');
        }
        if ($state === $restored) {
            if ($sourceInfo !== null || $destinationInfo === null) {
                throw new AppException('Durable rollback completion does not match the filesystem.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
            }
            return [$state, true];
        }
        if ($sourceInfo !== null && $destinationInfo !== null) {
            throw new AppException('Rollback source and destination are both present before activation.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
        }
        $reconciled = $state === $pending && $sourceInfo === null && $destinationInfo !== null;
        if ($sourceInfo !== null && $destinationInfo === null) {
            $this->checkpoint($checkpoint, $pending, ['source' => $source, 'destination' => $destination]);
            $reconciled = $this->moveVerified($userId, $accountId, $source, $destination, 'rollback_restore_failed') || $reconciled;
        } elseif (!($sourceInfo === null && $destinationInfo !== null && $state === $pending)) {
            throw new AppException('Rollback activation state cannot be reconciled safely.', 409, 'rollback_reconciliation_required', ['source_present' => $sourceInfo !== null, 'destination_present' => $destinationInfo !== null, 'switch_state' => $state], 'deployment.rollback');
        }
        $this->assertDirectory($this->filesystem->info($userId, $accountId, $destination), 'rollback_verification_failed');
        if ($this->filesystem->info($userId, $accountId, $source) !== null) {
            throw new AppException('Rollback source remains after activation.', 409, 'rollback_reconciliation_required', [], 'deployment.rollback');
        }
        $this->checkpoint($checkpoint, $restored, ['source' => $source, 'destination' => $destination]);
        return [$restored, $reconciled];
    }

    private function moveVerified(int $userId, int $accountId, string $source, string $destination, string $safeCode): bool
    {
        $reconciled = false;
        try {
            $this->filesystem->moveDirectory($userId, $accountId, $source, $destination);
        } catch (Throwable $exception) {
            $sourceAfter = $this->filesystem->info($userId, $accountId, $source);
            $destinationAfter = $this->filesystem->info($userId, $accountId, $destination);
            if ($sourceAfter === null && $destinationAfter !== null) {
                $this->assertDirectory($destinationAfter, $safeCode);
                $reconciled = true;
            } else {
                throw new AppException('The rollback directory move could not be verified safely.', 409, $safeCode, ['source_present' => $sourceAfter !== null, 'destination_present' => $destinationAfter !== null, 'provider_error' => $this->safeCode($exception)], 'deployment.rollback');
            }
        }
        if ($this->filesystem->info($userId, $accountId, $source) !== null) {
            throw new AppException('Rollback source still exists after the directory move.', 502, $safeCode, [], 'deployment.rollback');
        }
        $this->assertDirectory($this->filesystem->info($userId, $accountId, $destination), $safeCode);
        return $reconciled;
    }

    private function restoreQuarantinedRelease(int $userId, int $accountId, string $destination, string $quarantine): bool
    {
        if ($this->filesystem->info($userId, $accountId, $destination) !== null || $this->filesystem->info($userId, $accountId, $quarantine) === null) {
            return false;
        }
        try {
            $this->moveVerified($userId, $accountId, $quarantine, $destination, 'rollback_release_recovery_failed');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function deleteIfPresent(int $userId, int $accountId, string $path): bool
    {
        if ($this->filesystem->info($userId, $accountId, $path) === null) {
            return true;
        }
        try {
            $this->filesystem->deleteTree($userId, $accountId, $path);
            return $this->filesystem->info($userId, $accountId, $path) === null;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed>|null $info */
    private function assertDirectory(?array $info, string $safeCode): void
    {
        $type = strtolower((string) ($info['type'] ?? ''));
        if ($info === null || !in_array($type, ['dir', 'directory'], true) || (bool) ($info['is_symlink'] ?? false)) {
            throw new AppException('A rollback artifact is missing or is not a regular directory.', 502, $safeCode, [], 'deployment.rollback');
        }
    }

    /** @param array<string,mixed> $deployment */
    private function managedRoot(array $deployment): string
    {
        foreach (['stage_path' => '/.tcm-deploy/', 'rollback_path' => '/.tcm-rollbacks/', 'backup_ref' => '/.tcm-backups/'] as $field => $marker) {
            $value = $this->nullablePath($deployment[$field] ?? null);
            if ($value !== null && ($offset = strpos($value, $marker)) !== false && $offset > 0) {
                return substr($value, 0, $offset);
            }
        }
        throw new AppException('Rollback managed storage root cannot be derived safely.', 500, 'rollback_storage_invalid', [], 'deployment.rollback');
    }

    /** @param null|callable(string,array<string,mixed>):void $checkpoint */
    private function checkpoint(?callable $checkpoint, string $state, array $metadata): void
    {
        if ($checkpoint !== null) {
            $checkpoint($state, ['phase' => $state] + $metadata);
        }
    }

    private function nullablePath(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && str_starts_with($value, '/') && !str_contains($value, "\0") ? rtrim($value, '/') : null;
    }

    private function nullableChecksum(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new AppException('Persisted rollback checksum is invalid.', 409, 'deployment_state_tampered', [], 'security.path');
        }
        return $value;
    }

    private function safeCode(Throwable $exception): string
    {
        return $exception instanceof AppException ? $exception->safeCode : 'provider_error';
    }
}
