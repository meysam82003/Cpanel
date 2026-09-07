<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Cpanel\CpanelApiException;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use App\Security\OperationLockService;
use App\Security\PathGuard;
use Throwable;

final class DeploymentJobHandler implements JobHandler
{
    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly DeploymentFilesystem $filesystem,
        private readonly ZipPackageValidator $packages,
        private readonly DeploymentBackupVerifier $backups,
        private readonly HealthCheckService $health,
        private readonly DeploymentRollbackExecutor $rollback,
        private readonly OperationLockService $locks,
        private readonly PathGuard $paths,
        private readonly AuditLogger $audit,
        private readonly string $uploadRoot,
        private readonly string $tempRoot,
    ) {
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId() ?? throw new AppException('Deployment job has no owner.', 500, 'queue_owner_missing');
        $accountId = $context->accountId() ?? throw new AppException('Deployment job has no host.', 500, 'queue_account_missing');
        $deploymentId = (int) ($payload['deployment_id'] ?? 0);
        $deployment = $this->owned($deploymentId, $userId, $accountId);
        if ((string) $deployment['status'] === 'completed') {
            return $this->result($deployment, false);
        }
        if ((string) $deployment['status'] === 'rolled_back') {
            return $this->result($deployment, true);
        }
        if (in_array((string) $deployment['status'], ['failed', 'rollback_failed'], true)) {
            throw new AppException('This deployment is final and cannot be replayed automatically.', 409, 'deployment_state_invalid', [], 'deployment.rollback');
        }

        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $destination = $this->paths->normalize((string) $deployment['destination'], $root);
        if ($destination === $root) {
            throw new AppException('Deployment over the account root is blocked.', 403, 'deployment_root_blocked', [], 'security.path');
        }
        $work = $root . '/.tcm-deploy/deployment-' . $deploymentId;
        $stage = $work . '/release-' . substr((string) $deployment['package_checksum'], 0, 16);
        $remotePackage = $work . '/package.zip';
        $backup = $root . '/.tcm-backups/deployment-' . $deploymentId . '.zip';
        $rollbackPath = $root . '/.tcm-rollbacks/deployment-' . $deploymentId . '-previous';
        $this->assertPersistedPath($deployment['stage_path'] ?? null, $stage, 'stage_path');
        $this->database->execute('UPDATE deployments SET stage_path = COALESCE(stage_path, ?), updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND account_id = ?', [$stage, $deploymentId, $userId, $accountId]);

        $lockKey = 'deploy:' . $accountId . ':' . hash('sha256', $destination);
        $lockToken = $this->locks->acquire($lockKey, 1800);
        $preserveArtifacts = false;
        $localPackage = null;
        try {
            $this->heartbeat($context, $lockKey, $lockToken, 3, 'deployment_preparing');
            $package = $this->validatedPackageContract($deployment, $payload, $userId, $accountId);
            $metadata = $package['metadata'];
            $deployment = $this->owned($deploymentId, $userId, $accountId);
            $deployment = $this->recoverInterrupted($context, $deployment, $metadata, $destination, $stage, $remotePackage, $backup, $rollbackPath, $lockKey, $lockToken);
            if ((string) $deployment['status'] === 'rolled_back') {
                return $this->result($deployment, true);
            }
            if ($this->requiresLocalPackage($deployment)) {
                $localPackage = $this->validatedLocalPackage($package['record'], $payload, $deployment, $metadata);
            }

            if (in_array((string) $deployment['status'], ['queued', 'validating'], true)) {
                $this->validateWorkspace($context, $deploymentId, $userId, $accountId, $root, $destination, $work, $stage, $metadata, $lockKey, $lockToken);
                $deployment = $this->owned($deploymentId, $userId, $accountId);
            }

            if ((string) $deployment['status'] === 'validated') {
                if ((bool) $deployment['destination_existed']) {
                    $this->backup($context, $deploymentId, $userId, $accountId, $destination, $backup, $rollbackPath, $lockKey, $lockToken);
                } else {
                    $this->database->execute("UPDATE deployments SET status = 'backup_completed', switch_state = 'none', updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
                    $this->eventOnce($deploymentId, 'backup', 'skipped', 'deployment.backup_skipped', ['destination_existed' => false]);
                }
                $deployment = $this->owned($deploymentId, $userId, $accountId);
            }

            if ((string) $deployment['status'] === 'backup_completed') {
                if ($localPackage === null) {
                    throw new AppException('The immutable deployment package is unavailable before upload.', 410, 'deployment_package_expired', [], 'deploy.package');
                }
                $this->upload($context, $deploymentId, $userId, $accountId, $localPackage, (string) $deployment['package_checksum'], $work, $remotePackage, $lockKey, $lockToken);
                $deployment = $this->owned($deploymentId, $userId, $accountId);
            }

            if ((string) $deployment['status'] === 'upload_completed') {
                $this->extract($context, $deploymentId, $userId, $accountId, $remotePackage, $stage, $metadata, $lockKey, $lockToken);
                $deployment = $this->owned($deploymentId, $userId, $accountId);
            }

            if ((string) $deployment['status'] === 'extract_completed') {
                $this->activate($context, $deployment, $userId, $accountId, $destination, $stage, $rollbackPath, $metadata, $lockKey, $lockToken);
                $deployment = $this->owned($deploymentId, $userId, $accountId);
            }

            if ((string) $deployment['switch_state'] !== 'activated' || !in_array((string) $deployment['status'], ['health_check', 'deploying'], true)) {
                throw new AppException('Deployment did not reach a verified activated state.', 409, 'deployment_state_invalid', [], 'deployment.rollback');
            }
            $this->runHealthCheck($context, $deployment, $account, $lockKey, $lockToken);
            $this->database->execute("UPDATE deployments SET status = 'completed', switch_state = 'activated', reconciliation_json = NULL, error_code = NULL, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
            $this->eventOnce($deploymentId, 'complete', 'completed', 'deployment.completed');
            $this->notifyOnce($deploymentId, $userId, 'notification.deployment_done', ['id' => $deploymentId]);
            $this->audit->record($userId, $accountId, 'deployment.complete', 'success', 'deployment', (string) $deploymentId, ['destination' => $destination, 'backup_ref' => (bool) $deployment['destination_existed'] ? $backup : null, 'health_status' => $this->owned($deploymentId, $userId, $accountId)['health_status']]);
            return $this->result($this->owned($deploymentId, $userId, $accountId), false);
        } catch (Throwable $exception) {
            $fresh = $this->owned($deploymentId, $userId, $accountId);
            $safeCode = $this->safeCode($exception, 'deployment_failed');
            if ((string) $fresh['status'] === 'rollback_failed') {
                $preserveArtifacts = true;
                $this->audit->record($userId, $accountId, 'deployment.complete', 'failed', 'deployment', (string) $deploymentId, ['error_code' => $safeCode, 'final_status' => 'rollback_failed']);
                $this->notifyOnce($deploymentId, $userId, 'notification.deployment_failed', ['id' => $deploymentId, 'code' => 'rollback_failed']);
                throw $exception;
            }
            if ((string) $fresh['status'] === 'reconciliation_required' || $safeCode === 'deployment_reconciliation_required') {
                $preserveArtifacts = true;
                $this->audit->record($userId, $accountId, 'deployment.complete', 'failed', 'deployment', (string) $deploymentId, ['error_code' => 'deployment_reconciliation_required', 'reconciliation' => true]);
                $this->notifyOnce($deploymentId, $userId, 'notification.deployment_failed', ['id' => $deploymentId, 'code' => 'deployment_reconciliation_required']);
                throw $exception;
            }
            if ((string) $fresh['status'] !== 'rolled_back') {
                $this->failOrRollback($context, $deploymentId, $userId, $accountId, $fresh, $safeCode, $lockKey, $lockToken);
                $fresh = $this->owned($deploymentId, $userId, $accountId);
            }
            $preserveArtifacts = in_array((string) $fresh['status'], ['rollback_failed', 'reconciliation_required'], true);
            $this->audit->record($userId, $accountId, 'deployment.complete', 'failed', 'deployment', (string) $deploymentId, ['error_code' => $safeCode, 'final_status' => $fresh['status']]);
            $notification = (string) $fresh['status'] === 'rolled_back' ? 'notification.deployment_rolled_back' : 'notification.deployment_failed';
            $this->notifyOnce($deploymentId, $userId, $notification, ['id' => $deploymentId, 'code' => $safeCode]);
            throw $exception;
        } finally {
            if (!$preserveArtifacts) {
                $this->deleteRemoteIfPresent($userId, $accountId, $work);
                if (is_string($localPackage) && is_file($localPackage) && !is_link($localPackage)) {
                    @unlink($localPackage);
                }
            }
            $this->locks->release($lockKey, $lockToken);
        }
    }

    /** @param array<string,mixed> $deployment
     *  @param array<string,mixed> $payload
     *  @return array{record:array<string,mixed>,metadata:array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string}}
     */
    private function validatedPackageContract(array $deployment, array $payload, int $userId, int $accountId): array
    {
        $packageId = (int) ($deployment['package_id'] ?? 0);
        if ($packageId < 1 || $packageId !== (int) ($payload['package_id'] ?? 0) || (int) ($payload['deployment_id'] ?? 0) !== (int) $deployment['id']) {
            throw new AppException('Deployment queue payload does not match its persisted package.', 409, 'deployment_payload_mismatch', [], 'security.idor');
        }
        $package = $this->database->one('SELECT * FROM deployment_packages WHERE id = ? AND user_id = ? AND account_id = ?', [$packageId, $userId, $accountId]);
        if ($package === null || $package['consumed_at'] === null) {
            throw new AppException('Deployment package ownership or consumption state is invalid.', 409, 'deployment_package_unavailable', [], 'security.idor');
        }
        $expected = (string) ($deployment['package_checksum'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !hash_equals($expected, (string) $package['checksum_sha256']) || !hash_equals($expected, (string) ($payload['package_sha256'] ?? ''))) {
            throw new AppException('Deployment package checksum metadata does not match.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
        }
        $stored = $this->packageMetadata($this->decoded((string) ($deployment['package_metadata_json'] ?? '')), $expected);
        $packageStored = $this->packageMetadata($this->decoded((string) ($package['metadata_json'] ?? '')), $expected);
        $queued = $this->packageMetadata($payload['package_metadata'] ?? null, $expected);
        if ($stored !== $packageStored || $stored !== $queued) {
            throw new AppException('Deployment package metadata does not match its immutable queue contract.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
        }
        return ['record' => $package, 'metadata' => $stored];
    }

    /** @param array<string,mixed> $package
     *  @param array<string,mixed> $payload
     *  @param array<string,mixed> $deployment
     *  @param array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} $metadata
     */
    private function validatedLocalPackage(array $package, array $payload, array $deployment, array $metadata): string
    {
        $path = (string) ($payload['package_path'] ?? '');
        $real = realpath($path);
        $root = realpath($this->uploadRoot);
        if ($real === false || $root === false || $real !== $path || $real !== (string) $package['local_path'] || is_link($path) || !is_file($real) || !is_readable($real) || !str_starts_with($real . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new AppException('Deployment package is missing from secure upload storage.', 410, 'deployment_package_expired', [], 'deploy.package');
        }
        $validated = $this->packageMetadata($this->packages->validate($real), (string) $deployment['package_checksum']);
        if ($validated !== $metadata) {
            throw new AppException('Deployment package changed after validation.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
        }
        return $real;
    }

    /** @return array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} */
    private function packageMetadata(mixed $value, string $expectedChecksum): array
    {
        if (!is_array($value) || !is_array($value['top_level'] ?? null) || !array_is_list($value['top_level'])) {
            throw new AppException('Deployment package metadata is invalid.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
        }
        $files = filter_var($value['files'] ?? null, FILTER_VALIDATE_INT);
        $compressed = filter_var($value['compressed_bytes'] ?? null, FILTER_VALIDATE_INT);
        $uncompressed = filter_var($value['uncompressed_bytes'] ?? null, FILTER_VALIDATE_INT);
        $checksum = (string) ($value['sha256'] ?? '');
        $topLevel = array_values(array_map(static fn (mixed $entry): string => is_string($entry) ? $entry : '', $value['top_level']));
        if ($files === false || $files < 1 || $files > 10_000 || $compressed === false || $compressed < 1 || $compressed > 1_073_741_824 || $uncompressed === false || $uncompressed < 0 || $uncompressed > 1_073_741_824 || $topLevel === [] || count($topLevel) > 10_000 || !preg_match('/^[a-f0-9]{64}$/', $checksum) || !hash_equals($expectedChecksum, $checksum)) {
            throw new AppException('Deployment package metadata failed its safety policy.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
        }
        foreach ($topLevel as $entry) {
            if ($entry === '' || strlen($entry) > 255 || str_contains($entry, '/') || str_contains($entry, '\\') || in_array($entry, ['.', '..'], true) || preg_match('/[\x00-\x1F\x7F]/u', $entry)) {
                throw new AppException('Deployment top-level package metadata is unsafe.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
            }
        }
        if (count(array_unique($topLevel)) !== count($topLevel)) {
            throw new AppException('Deployment package metadata contains duplicate outputs.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
        }
        return ['files' => $files, 'compressed_bytes' => $compressed, 'uncompressed_bytes' => $uncompressed, 'top_level' => $topLevel, 'sha256' => $checksum];
    }

    /** @param array<string,mixed> $deployment */
    private function requiresLocalPackage(array $deployment): bool
    {
        return in_array((string) $deployment['status'], ['queued', 'validating', 'validated', 'backup_started', 'backup_completed'], true);
    }

    /** @param array<string,mixed> $deployment
     *  @param array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} $metadata
     *  @return array<string,mixed>
     */
    private function recoverInterrupted(JobContext $context, array $deployment, array $metadata, string $destination, string $stage, string $remotePackage, string $backup, string $rollbackPath, string $lockKey, string $lockToken): array
    {
        $status = (string) $deployment['status'];
        $phase = (string) ($this->decoded((string) ($deployment['reconciliation_json'] ?? ''))['phase'] ?? '');
        $userId = (int) $deployment['user_id'];
        $accountId = (int) $deployment['account_id'];
        $deploymentId = (int) $deployment['id'];
        if ($status === 'reconciliation_required') {
            $status = match ($phase) {
                'backup' => 'backup_started',
                'upload' => 'upload_started',
                'extract' => 'extract_started',
                'destination_preserve', 'stage_activate' => 'deploying',
                default => throw new AppException('Deployment reconciliation phase is unknown.', 409, 'deployment_reconciliation_required', [], 'deployment.rollback'),
            };
        }
        if ($status === 'backup_started') {
            try {
                $info = $this->backups->verify($userId, $accountId, $backup, $destination, $this->nullableChecksum($deployment['backup_checksum'] ?? null));
            } catch (Throwable $exception) {
                $this->requireReconciliation($deploymentId, 'backup', $this->safeCode($exception, 'deployment_backup_ambiguous'), ['backup_ref' => $backup]);
            }
            $this->completeBackupState($deploymentId, $userId, $accountId, $destination, $backup, $rollbackPath, $info, true);
        } elseif ($status === 'upload_started') {
            if (!$this->verifyRemotePackage($userId, $accountId, $remotePackage, (string) $deployment['package_checksum'], (int) $metadata['compressed_bytes'])) {
                $this->requireReconciliation($deploymentId, 'upload', 'deployment_upload_ambiguous', ['remote_package' => $remotePackage]);
            }
            $this->database->execute("UPDATE deployments SET status = 'upload_completed', reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
            $this->eventOnce($deploymentId, 'upload', 'completed', 'deployment.upload_completed', ['reconciled' => true]);
        } elseif ($status === 'extract_started') {
            $presence = $this->outputPresence($userId, $accountId, $stage, $metadata['top_level']);
            if ($presence['missing'] !== []) {
                $this->requireReconciliation($deploymentId, 'extract', 'deployment_extract_ambiguous', ['present' => $presence['present'], 'missing' => $presence['missing']]);
            }
            $this->database->execute("UPDATE deployments SET status = 'extract_completed', reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
            $this->eventOnce($deploymentId, 'extract', 'completed', 'deployment.extract_completed', ['reconciled' => true]);
        } elseif ($status === 'deploying' || $status === 'health_check') {
            $this->reconcileSwitch($context, $deployment, $userId, $accountId, $destination, $stage, $rollbackPath, $metadata['top_level'], $lockKey, $lockToken);
        } elseif ($status === 'rolling_back' || (string) $deployment['switch_state'] === 'rollback_pending') {
            $this->rollbackAfterFailure($context, $deploymentId, $userId, $accountId, (string) ($deployment['error_code'] ?: 'deployment_interrupted'), $lockKey, $lockToken);
        }
        $this->heartbeat($context, $lockKey, $lockToken, 5, 'deployment_reconciled');
        return $this->owned($deploymentId, $userId, $accountId);
    }

    /** @param list<string> $topLevel */
    private function reconcileSwitch(JobContext $context, array $deployment, int $userId, int $accountId, string $destination, string $stage, string $rollbackPath, array $topLevel, string $lockKey, string $lockToken): void
    {
        $deploymentId = (int) $deployment['id'];
        $switchState = (string) $deployment['switch_state'];
        if ($switchState === 'destination_preserve_pending') {
            $source = $this->filesystem->info($userId, $accountId, $destination);
            $preserved = $this->filesystem->info($userId, $accountId, $rollbackPath);
            if ($source === null && $this->isDirectory($preserved)) {
                $this->database->execute("UPDATE deployments SET status = 'deploying', switch_state = 'destination_preserved', reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
                $this->eventOnce($deploymentId, 'deploy', 'running', 'deployment.destination_preserved', ['rollback_path' => $rollbackPath, 'reconciled' => true]);
                return;
            }
            $this->requireReconciliation($deploymentId, 'destination_preserve', 'deployment_switch_ambiguous', ['destination_present' => $source !== null, 'rollback_present' => $preserved !== null]);
        }
        if ($switchState === 'stage_activate_pending') {
            $stageInfo = $this->filesystem->info($userId, $accountId, $stage);
            $destinationInfo = $this->filesystem->info($userId, $accountId, $destination);
            $presence = $destinationInfo === null ? ['present' => [], 'missing' => $topLevel] : $this->outputPresence($userId, $accountId, $destination, $topLevel);
            if ($stageInfo === null && $this->isDirectory($destinationInfo) && $presence['missing'] === []) {
                $this->database->execute("UPDATE deployments SET status = 'health_check', switch_state = 'activated', reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
                $this->eventOnce($deploymentId, 'deploy', 'completed', 'deployment.deploy_completed', ['entries' => count($topLevel), 'reconciled' => true]);
                return;
            }
            if ((bool) $deployment['destination_existed'] && $this->isDirectory($this->filesystem->info($userId, $accountId, $rollbackPath))) {
                $this->rollbackAfterFailure($context, $deploymentId, $userId, $accountId, 'deployment_switch_ambiguous', $lockKey, $lockToken);
                return;
            }
            $this->requireReconciliation($deploymentId, 'stage_activate', 'deployment_switch_ambiguous', ['stage_present' => $stageInfo !== null, 'destination_present' => $destinationInfo !== null, 'missing' => $presence['missing']]);
        }
        if ($switchState === 'activated') {
            $presence = $this->outputPresence($userId, $accountId, $destination, $topLevel);
            if ($presence['missing'] !== []) {
                $this->rollbackAfterFailure($context, $deploymentId, $userId, $accountId, 'deployment_output_missing', $lockKey, $lockToken);
            }
            return;
        }
        if ($switchState === 'destination_preserved') {
            return;
        }
        $this->requireReconciliation($deploymentId, 'stage_activate', 'deployment_switch_state_invalid', ['switch_state' => $switchState]);
    }

    /** @param array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} $metadata */
    private function validateWorkspace(JobContext $context, int $deploymentId, int $userId, int $accountId, string $root, string $destination, string $work, string $stage, array $metadata, string $lockKey, string $lockToken): void
    {
        $this->database->execute("UPDATE deployments SET status = 'validating', error_code = NULL, started_at = COALESCE(started_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        $this->eventOnce($deploymentId, 'validate', 'running', 'deployment.validating');
        $this->heartbeat($context, $lockKey, $lockToken, 10, 'validating');
        $this->filesystem->ensureDirectory($userId, $accountId, $root . '/.tcm-deploy');
        $this->filesystem->ensureDirectory($userId, $accountId, $work);
        $this->filesystem->ensureDirectory($userId, $accountId, $stage, '0755');
        $this->filesystem->ensureDirectory($userId, $accountId, $root . '/.tcm-backups');
        $this->filesystem->ensureDirectory($userId, $accountId, $root . '/.tcm-rollbacks');
        $this->filesystem->ensureDirectory($userId, $accountId, dirname($destination), '0755');
        $stageInfo = $this->filesystem->info($userId, $accountId, $stage);
        if (!$this->isDirectory($stageInfo)) {
            throw new AppException('Deployment staging directory is invalid.', 409, 'deployment_stage_invalid', [], 'security.path');
        }
        $presence = $this->outputPresence($userId, $accountId, $stage, $metadata['top_level']);
        if ($presence['present'] !== []) {
            throw new AppException('Deployment staging directory is not empty.', 409, 'deployment_stage_not_empty', [], 'deployment.rollback');
        }
        $destinationInfo = $this->filesystem->info($userId, $accountId, $destination);
        if ($destinationInfo !== null && !$this->isDirectory($destinationInfo)) {
            throw new AppException('Deployment destination must be a regular directory.', 422, 'deployment_destination_invalid', [], 'deploy.start');
        }
        $this->database->execute("UPDATE deployments SET status = 'validated', destination_existed = ?, reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [(int) ($destinationInfo !== null), $deploymentId]);
        $this->eventOnce($deploymentId, 'validate', 'completed', 'deployment.validated', ['files' => $metadata['files'], 'sha256' => $metadata['sha256'], 'destination_existed' => $destinationInfo !== null]);
    }

    private function backup(JobContext $context, int $deploymentId, int $userId, int $accountId, string $destination, string $backup, string $rollbackPath, string $lockKey, string $lockToken): void
    {
        if ($this->filesystem->info($userId, $accountId, $backup) !== null) {
            throw new AppException('The deterministic deployment backup path already exists.', 409, 'deployment_backup_collision', ['backup_ref' => $backup], 'deployment.rollback');
        }
        $this->database->execute("UPDATE deployments SET status = 'backup_started', backup_ref = ?, rollback_path = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$backup, $rollbackPath, $deploymentId]);
        $this->eventOnce($deploymentId, 'backup', 'running', 'deployment.backup_running');
        $this->heartbeat($context, $lockKey, $lockToken, 25, 'backup_started');
        $reconciled = false;
        try {
            $this->filesystem->compressDirectory($userId, $accountId, $destination, $backup);
            $this->heartbeat($context, $lockKey, $lockToken, 31, 'backup_verifying');
        } catch (Throwable $exception) {
            if (!$this->isAmbiguous($exception)) {
                throw $exception;
            }
            try {
                $this->heartbeat($context, $lockKey, $lockToken, 31, 'backup_reconciling');
                $info = $this->backups->verify($userId, $accountId, $backup, $destination);
                $reconciled = true;
            } catch (Throwable $verification) {
                $this->requireReconciliation($deploymentId, 'backup', $this->safeCode($exception, 'deployment_backup_failed'), ['backup_ref' => $backup, 'verification_error' => $this->safeCode($verification, 'deployment_backup_integrity_failed')]);
            }
        }
        if (!isset($info)) {
            $info = $this->backups->verify($userId, $accountId, $backup, $destination);
        }
        $this->completeBackupState($deploymentId, $userId, $accountId, $destination, $backup, $rollbackPath, $info, $reconciled);
    }

    /** @param array<string,mixed> $info */
    private function completeBackupState(int $deploymentId, int $userId, int $accountId, string $destination, string $backup, string $rollbackPath, array $info, bool $reconciled): void
    {
        $size = (int) ($info['size'] ?? 0);
        $checksum = (string) ($info['sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new AppException('Verified deployment backup has no valid checksum.', 502, 'deployment_backup_integrity_failed', [], 'deployment.rollback');
        }
        $this->database->transaction(function (Database $database) use ($deploymentId, $userId, $accountId, $destination, $backup, $rollbackPath, $size, $checksum): void {
            $database->execute("UPDATE deployments SET status = 'backup_completed', backup_ref = ?, backup_size = ?, backup_checksum = ?, rollback_path = ?, reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$backup, $size, $checksum, $rollbackPath, $deploymentId]);
            $exists = $database->one("SELECT id FROM backups WHERE user_id = ? AND account_id = ? AND type = 'deployment' AND remote_path = ? LIMIT 1", [$userId, $accountId, $backup]);
            if ($exists === null) {
                $database->execute("INSERT INTO backups (user_id, account_id, type, target, remote_path, size_bytes, status, metadata_json, completed_at) VALUES (?, ?, 'deployment', ?, ?, ?, 'completed', ?, CURRENT_TIMESTAMP)", [$userId, $accountId, $destination, $backup, $size, json_encode(['deployment_id' => $deploymentId, 'rollback_path' => $rollbackPath, 'sha256' => $checksum], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            }
        });
        $this->eventOnce($deploymentId, 'backup', 'completed', 'deployment.backup_completed', ['backup_ref' => $backup, 'size' => $size, 'sha256' => $checksum, 'reconciled' => $reconciled]);
    }

    private function upload(JobContext $context, int $deploymentId, int $userId, int $accountId, string $localPackage, string $checksum, string $work, string $remotePackage, string $lockKey, string $lockToken): void
    {
        if ($this->filesystem->info($userId, $accountId, $remotePackage) !== null) {
            if ($this->verifyRemotePackage($userId, $accountId, $remotePackage, $checksum, (int) filesize($localPackage))) {
                $this->database->execute("UPDATE deployments SET status = 'upload_completed', reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
                $this->eventOnce($deploymentId, 'upload', 'completed', 'deployment.upload_completed', ['reconciled' => true]);
                return;
            }
            throw new AppException('Remote deployment package conflicts with the immutable upload.', 409, 'deployment_remote_package_conflict', [], 'deploy.package');
        }
        $this->database->execute("UPDATE deployments SET status = 'upload_started', updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        $this->eventOnce($deploymentId, 'upload', 'running', 'deployment.upload_running');
        $this->heartbeat($context, $lockKey, $lockToken, 40, 'upload_started');
        $reconciled = false;
        try {
            $this->filesystem->uploadPackage($userId, $accountId, $work, $localPackage, basename($remotePackage));
        } catch (Throwable $exception) {
            try {
                if (!$this->verifyRemotePackage($userId, $accountId, $remotePackage, $checksum, (int) filesize($localPackage))) {
                    if ($this->isAmbiguous($exception)) {
                        $this->requireReconciliation($deploymentId, 'upload', $this->safeCode($exception, 'deployment_upload_failed'), ['remote_package' => $remotePackage]);
                    }
                    throw $exception;
                }
                $reconciled = true;
            } catch (AppException $verification) {
                if ($verification->safeCode === 'deployment_reconciliation_required') {
                    throw $verification;
                }
                $this->requireReconciliation($deploymentId, 'upload', $this->safeCode($exception, 'deployment_upload_failed'), ['remote_package' => $remotePackage, 'verification_error' => $verification->safeCode]);
            }
        }
        if (!$this->verifyRemotePackage($userId, $accountId, $remotePackage, $checksum, (int) filesize($localPackage))) {
            $this->requireReconciliation($deploymentId, 'upload', 'deployment_remote_package_missing', ['remote_package' => $remotePackage]);
        }
        $this->database->execute("UPDATE deployments SET status = 'upload_completed', reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        $this->eventOnce($deploymentId, 'upload', 'completed', 'deployment.upload_completed', ['sha256' => $checksum, 'reconciled' => $reconciled]);
    }

    /** @param array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} $metadata */
    private function extract(JobContext $context, int $deploymentId, int $userId, int $accountId, string $remotePackage, string $stage, array $metadata, string $lockKey, string $lockToken): void
    {
        $preexisting = $this->outputPresence($userId, $accountId, $stage, $metadata['top_level']);
        if ($preexisting['present'] !== []) {
            throw new AppException('Deployment stage contains unexpected output before extraction.', 409, 'deployment_stage_not_empty', [], 'deployment.rollback');
        }
        $this->database->execute("UPDATE deployments SET status = 'extract_started', updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        $this->eventOnce($deploymentId, 'extract', 'running', 'deployment.extract_running');
        $this->heartbeat($context, $lockKey, $lockToken, 58, 'extract_started');
        $reconciled = false;
        try {
            $this->filesystem->extract($userId, $accountId, $remotePackage, $stage);
        } catch (Throwable $exception) {
            $presence = $this->outputPresence($userId, $accountId, $stage, $metadata['top_level']);
            if ($presence['missing'] === []) {
                $reconciled = true;
            } elseif ($presence['present'] !== [] || $this->isAmbiguous($exception)) {
                $this->requireReconciliation($deploymentId, 'extract', $this->safeCode($exception, 'deployment_extract_failed'), ['present' => $presence['present'], 'missing' => $presence['missing']]);
            } else {
                throw $exception;
            }
        }
        $presence = $this->outputPresence($userId, $accountId, $stage, $metadata['top_level']);
        if ($presence['missing'] !== []) {
            $this->requireReconciliation($deploymentId, 'extract', 'deployment_output_missing', ['present' => $presence['present'], 'missing' => $presence['missing']]);
        }
        $this->database->execute("UPDATE deployments SET status = 'extract_completed', reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        $this->eventOnce($deploymentId, 'extract', 'completed', 'deployment.extract_completed', ['entries' => count($metadata['top_level']), 'reconciled' => $reconciled]);
    }

    /** @param array<string,mixed> $deployment
     *  @param array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} $metadata
     */
    private function activate(JobContext $context, array $deployment, int $userId, int $accountId, string $destination, string $stage, string $rollbackPath, array $metadata, string $lockKey, string $lockToken): void
    {
        $deploymentId = (int) $deployment['id'];
        $presence = $this->outputPresence($userId, $accountId, $stage, $metadata['top_level']);
        if ($presence['missing'] !== []) {
            throw new AppException('Staged deployment outputs are incomplete.', 409, 'deployment_stage_incomplete', ['missing' => $presence['missing']], 'deployment.rollback');
        }
        $this->database->execute("UPDATE deployments SET status = 'deploying', updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        $this->eventOnce($deploymentId, 'deploy', 'running', 'deployment.deploy_running');
        if ((bool) $deployment['destination_existed']) {
            if ($this->filesystem->info($userId, $accountId, $rollbackPath) !== null) {
                throw new AppException('Rollback directory already exists before deployment switching.', 409, 'deployment_rollback_collision', [], 'deployment.rollback');
            }
            $this->database->execute("UPDATE deployments SET switch_state = 'destination_preserve_pending', rollback_path = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$rollbackPath, $deploymentId]);
            $this->heartbeat($context, $lockKey, $lockToken, 72, 'destination_preserve_started');
            try {
                $this->filesystem->moveDirectory($userId, $accountId, $destination, $rollbackPath);
            } catch (Throwable $exception) {
                $source = $this->filesystem->info($userId, $accountId, $destination);
                $preserved = $this->filesystem->info($userId, $accountId, $rollbackPath);
                if (!($source === null && $this->isDirectory($preserved))) {
                    $this->requireReconciliation($deploymentId, 'destination_preserve', $this->safeCode($exception, 'deployment_switch_failed'), ['destination_present' => $source !== null, 'rollback_present' => $preserved !== null]);
                }
            }
            if ($this->filesystem->info($userId, $accountId, $destination) !== null || !$this->isDirectory($this->filesystem->info($userId, $accountId, $rollbackPath))) {
                $this->requireReconciliation($deploymentId, 'destination_preserve', 'deployment_switch_verification_failed', []);
            }
            $this->database->execute("UPDATE deployments SET switch_state = 'destination_preserved', updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
            $this->eventOnce($deploymentId, 'deploy', 'running', 'deployment.destination_preserved', ['rollback_path' => $rollbackPath]);
        } else {
            if ($this->filesystem->info($userId, $accountId, $destination) !== null) {
                throw new AppException('Deployment destination appeared after validation.', 409, 'deployment_switch_collision', [], 'deployment.rollback');
            }
            $this->database->execute("UPDATE deployments SET switch_state = 'destination_preserved', updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        }

        $this->database->execute("UPDATE deployments SET switch_state = 'stage_activate_pending', updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        $this->heartbeat($context, $lockKey, $lockToken, 80, 'stage_activate_started');
        try {
            $this->filesystem->moveDirectory($userId, $accountId, $stage, $destination);
        } catch (Throwable $exception) {
            $stageInfo = $this->filesystem->info($userId, $accountId, $stage);
            $destinationInfo = $this->filesystem->info($userId, $accountId, $destination);
            $outputs = $destinationInfo === null ? ['present' => [], 'missing' => $metadata['top_level']] : $this->outputPresence($userId, $accountId, $destination, $metadata['top_level']);
            if (!($stageInfo === null && $this->isDirectory($destinationInfo) && $outputs['missing'] === [])) {
                throw new AppException('Atomic release activation could not be verified.', 409, 'deployment_switch_failed', ['stage_present' => $stageInfo !== null, 'destination_present' => $destinationInfo !== null, 'provider_error' => $this->safeCode($exception, 'provider_error')], 'deployment.rollback');
            }
        }
        $outputs = $this->outputPresence($userId, $accountId, $destination, $metadata['top_level']);
        if ($this->filesystem->info($userId, $accountId, $stage) !== null || !$this->isDirectory($this->filesystem->info($userId, $accountId, $destination)) || $outputs['missing'] !== []) {
            throw new AppException('Activated release did not pass filesystem verification.', 502, 'deployment_output_missing', ['missing' => $outputs['missing']], 'deployment.rollback');
        }
        $this->heartbeat($context, $lockKey, $lockToken, 88, 'stage_activate_verified');
        $this->database->execute("UPDATE deployments SET status = 'health_check', switch_state = 'activated', reconciliation_json = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
        $this->eventOnce($deploymentId, 'deploy', 'completed', 'deployment.deploy_completed', ['entries' => count($metadata['top_level'])]);
    }

    /** @param array<string,mixed> $deployment
     *  @param array<string,mixed> $account
     */
    private function runHealthCheck(JobContext $context, array $deployment, array $account, string $lockKey, string $lockToken): void
    {
        $deploymentId = (int) $deployment['id'];
        $healthUrl = is_string($deployment['health_check_url']) && $deployment['health_check_url'] !== '' ? $deployment['health_check_url'] : null;
        if ($healthUrl === null) {
            $this->eventOnce($deploymentId, 'health', 'skipped', 'deployment.health_skipped');
            return;
        }
        $this->eventOnce($deploymentId, 'health', 'running', 'deployment.health_running');
        $this->heartbeat($context, $lockKey, $lockToken, 90, 'health_check');
        $result = $this->health->check($healthUrl, (string) ($account['main_domain'] ?? ''));
        $this->database->execute('UPDATE deployments SET health_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$result['status'], $deploymentId]);
        if (!$result['healthy']) {
            throw new AppException('The deployed site failed its health check.', 502, 'deployment_health_failed', ['status' => $result['status']], 'deployment.rollback');
        }
        $this->eventOnce($deploymentId, 'health', 'completed', 'deployment.health_passed', $result);
        $this->heartbeat($context, $lockKey, $lockToken, 98, 'deployment_completing');
    }

    /** @param array<string,mixed> $deployment */
    private function failOrRollback(JobContext $context, int $deploymentId, int $userId, int $accountId, array $deployment, string $safeCode, string $lockKey, string $lockToken): void
    {
        $switchState = (string) $deployment['switch_state'];
        if (!in_array($switchState, ['destination_preserved', 'stage_activate_pending', 'activated'], true) && (string) $deployment['status'] !== 'health_check') {
            $this->database->execute("UPDATE deployments SET status = 'failed', error_code = ?, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$safeCode, $deploymentId]);
            $this->eventOnce($deploymentId, 'failed', 'failed', 'deployment.failed', ['error_code' => $safeCode]);
            return;
        }
        $this->rollbackAfterFailure($context, $deploymentId, $userId, $accountId, $safeCode, $lockKey, $lockToken);
    }

    private function rollbackAfterFailure(JobContext $context, int $deploymentId, int $userId, int $accountId, string $safeCode, string $lockKey, string $lockToken): void
    {
        $current = $this->owned($deploymentId, $userId, $accountId);
        $switchState = (string) ($current['switch_state'] ?? '');
        if (!str_starts_with($switchState, 'rollback_') || $switchState === 'rollback_complete') {
            $switchState = 'rollback_pending';
        }
        $this->database->execute("UPDATE deployments SET status = 'rolling_back', switch_state = ?, error_code = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$switchState, $safeCode, $deploymentId]);
        $this->eventOnce($deploymentId, 'rollback', 'running', 'deployment.rollback_running', ['reason' => $safeCode]);
        try {
            $result = $this->rollback->restore(
                $userId,
                $accountId,
                $this->owned($deploymentId, $userId, $accountId),
                function (string $state, array $metadata) use ($context, $deploymentId, $userId, $accountId, $lockKey, $lockToken): void {
                    $this->rollbackCheckpoint($context, $deploymentId, $userId, $accountId, $lockKey, $lockToken, $state, $metadata);
                },
            );
            $this->database->execute("UPDATE deployments SET status = 'rolled_back', switch_state = 'rollback_complete', reconciliation_json = NULL, rolled_back_at = CURRENT_TIMESTAMP, rollback_verified_at = CURRENT_TIMESTAMP, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
            $this->eventOnce($deploymentId, 'rollback', 'completed', 'deployment.rollback_completed', $result);
        } catch (Throwable $rollbackError) {
            $metadata = ['phase' => 'rollback', 'error_code' => $this->safeCode($rollbackError, 'rollback_failed'), 'original_error' => $safeCode];
            $this->database->execute("UPDATE deployments SET status = 'rollback_failed', reconciliation_json = ?, error_code = ?, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $metadata['error_code'], $deploymentId]);
            $this->eventOnce($deploymentId, 'rollback', 'failed', 'deployment.rollback_failed', $metadata);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function rollbackCheckpoint(JobContext $context, int $deploymentId, int $userId, int $accountId, string $lockKey, string $lockToken, string $state, array $metadata): void
    {
        if (!preg_match('/^rollback_(?:dir|archive|remove)_[a-z_]{3,28}$/', $state) || strlen($state) > 40) {
            throw new AppException('Rollback checkpoint is invalid.', 500, 'rollback_state_invalid');
        }
        $this->database->execute("UPDATE deployments SET status = 'rolling_back', switch_state = ?, reconciliation_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND account_id = ?", [$state, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $deploymentId, $userId, $accountId]);
        $this->locks->renew($lockKey, $lockToken, 1800);
        $progress = str_contains($state, 'restored') || str_contains($state, 'complete') ? 94 : (str_contains($state, 'quarantined') ? 90 : 86);
        try {
            $context->progress($progress, $state);
        } catch (AppException $exception) {
            if ($exception->safeCode !== 'queue_lease_lost') {
                throw $exception;
            }
        }
    }

    private function nullableChecksum(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new AppException('Persisted deployment backup checksum is invalid.', 409, 'deployment_state_tampered', [], 'security.path');
        }
        return $value;
    }

    private function verifyRemotePackage(int $userId, int $accountId, string $remotePackage, string $checksum, int $expectedBytes): bool
    {
        $info = $this->filesystem->info($userId, $accountId, $remotePackage);
        if ($info === null) {
            return false;
        }
        $type = strtolower((string) ($info['type'] ?? 'file'));
        if (in_array($type, ['dir', 'directory', 'link', 'symlink'], true) || (bool) ($info['is_symlink'] ?? false) || (int) ($info['size'] ?? -1) !== $expectedBytes) {
            throw new AppException('Remote deployment package metadata is invalid.', 409, 'deployment_remote_package_integrity_failed', [], 'deploy.package');
        }
        $temporary = $this->temporaryPath('deployment-package-verify', '.zip');
        try {
            $download = $this->filesystem->download($userId, $accountId, $remotePackage, $temporary, min(1_073_741_824, max(1_048_576, $expectedBytes + 1)));
            if ((int) $download['bytes'] !== $expectedBytes || !hash_equals($checksum, (string) $download['sha256'])) {
                throw new AppException('Remote deployment package differs from the validated upload.', 409, 'deployment_remote_package_integrity_failed', [], 'deploy.package');
            }
            return true;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @param list<string> $topLevel
     *  @return array{present:list<string>,missing:list<string>}
     */
    private function outputPresence(int $userId, int $accountId, string $directory, array $topLevel): array
    {
        $present = [];
        $missing = [];
        foreach ($topLevel as $entry) {
            if ($entry === '' || str_contains($entry, '/') || str_contains($entry, '\\') || in_array($entry, ['.', '..'], true)) {
                throw new AppException('Deployment top-level package metadata is unsafe.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
            }
            $info = $this->filesystem->info($userId, $accountId, rtrim($directory, '/') . '/' . $entry);
            if ($info === null) {
                $missing[] = $entry;
                continue;
            }
            $type = strtolower((string) ($info['type'] ?? ''));
            if (in_array($type, ['link', 'symlink'], true) || (bool) ($info['is_symlink'] ?? false)) {
                throw new AppException('A deployed output resolved to a symbolic link.', 403, 'deployment_symlink_blocked', ['entry' => $entry], 'security.path');
            }
            $present[] = $entry;
        }
        return ['present' => $present, 'missing' => $missing];
    }

    private function requireReconciliation(int $deploymentId, string $phase, string $providerError, array $metadata): never
    {
        $details = ['phase' => $phase, 'provider_error' => $providerError] + $metadata;
        $this->database->execute("UPDATE deployments SET status = 'reconciliation_required', error_code = 'deployment_reconciliation_required', reconciliation_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $deploymentId]);
        $this->eventOnce($deploymentId, $phase, 'failed', 'deployment.reconciliation_required', $details);
        throw new AppException('Deployment provider outcome is ambiguous and automatic replay was blocked.', 409, 'deployment_reconciliation_required', ['phase' => $phase], 'deployment.rollback');
    }

    private function notifyOnce(int $deploymentId, int $userId, string $bodyKey, array $parameters): void
    {
        $this->database->transaction(function (Database $database) use ($deploymentId, $userId, $bodyKey, $parameters): void {
            $row = $database->one('SELECT d.notification_id, n.body_key, n.parameters_json, n.sent_at FROM deployments d LEFT JOIN notifications n ON n.id = d.notification_id AND n.user_id = d.user_id WHERE d.id = ? AND d.user_id = ? FOR UPDATE', [$deploymentId, $userId]);
            if ($row === null) {
                return;
            }
            $encoded = json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($row['notification_id'] !== null && (string) ($row['body_key'] ?? '') === $bodyKey && (string) ($row['parameters_json'] ?? '') === $encoded) {
                return;
            }
            $database->execute("INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, 'deployment', 'notification.deployment_title', ?, ?)", [$userId, $bodyKey, $encoded]);
            $database->execute('UPDATE deployments SET notification_id = ? WHERE id = ? AND user_id = ?', [$database->lastInsertId(), $deploymentId, $userId]);
        });
    }

    private function eventOnce(int $deploymentId, string $stage, string $status, string $message, array $metadata = []): void
    {
        $existing = $this->database->one('SELECT id FROM deployment_events WHERE deployment_id = ? AND stage = ? AND status = ? AND message_key = ? LIMIT 1', [$deploymentId, $stage, $status, $message]);
        if ($existing === null) {
            $this->database->execute('INSERT INTO deployment_events (deployment_id, stage, status, message_key, metadata_json) VALUES (?, ?, ?, ?, ?)', [$deploymentId, $stage, $status, $message, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
    }

    private function heartbeat(JobContext $context, string $lockKey, string $lockToken, int $progress, string $message): void
    {
        $this->locks->renew($lockKey, $lockToken, 1800);
        $context->progress($progress, $message);
    }

    private function deleteRemoteIfPresent(int $userId, int $accountId, string $path): void
    {
        try {
            if ($this->filesystem->info($userId, $accountId, $path) !== null) {
                $this->filesystem->deleteTree($userId, $accountId, $path);
            }
        } catch (Throwable) {
            // Cron cleanup can retry managed work-directory removal.
        }
    }

    private function temporaryPath(string $prefix, string $suffix): string
    {
        $directory = rtrim($this->tempRoot, '/');
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new AppException('Secure deployment verification storage is unavailable.', 500, 'deployment_storage_unavailable');
        }
        @chmod($directory, 0700);
        $real = realpath($directory);
        if ($real === false || !is_writable($real)) {
            throw new AppException('Secure deployment verification storage is unavailable.', 500, 'deployment_storage_unavailable');
        }
        return $real . '/' . $prefix . '-' . bin2hex(random_bytes(16)) . $suffix;
    }

    /** @return array<string,mixed> */
    private function owned(int $deploymentId, int $userId, int $accountId): array
    {
        $deployment = $this->database->one('SELECT * FROM deployments WHERE id = ? AND user_id = ? AND account_id = ?', [$deploymentId, $userId, $accountId]);
        if ($deployment === null) {
            throw new AppException('Deployment job is missing or is not owned by this account.', 404, 'deployment_not_found', [], 'security.idor');
        }
        return $deployment;
    }

    /** @param array<string,mixed> $deployment
     *  @return array<string,mixed>
     */
    private function result(array $deployment, bool $rolledBack): array
    {
        return [
            'deployment_id' => (int) $deployment['id'],
            'destination' => (string) $deployment['destination'],
            'backup_ref' => $deployment['backup_ref'],
            'health_status' => $deployment['health_status'],
            'status' => (string) $deployment['status'],
            'rolled_back' => $rolledBack,
        ];
    }

    private function assertPersistedPath(mixed $stored, string $expected, string $field): void
    {
        if ($stored !== null && (!is_string($stored) || !hash_equals($expected, $stored))) {
            throw new AppException('Persisted deployment recovery path is inconsistent.', 409, 'deployment_state_tampered', ['field' => $field], 'security.path');
        }
    }

    /** @param array<string,mixed>|null $info */
    private function isDirectory(?array $info): bool
    {
        return $info !== null && in_array(strtolower((string) ($info['type'] ?? '')), ['dir', 'directory'], true) && !(bool) ($info['is_symlink'] ?? false);
    }

    private function isAmbiguous(Throwable $exception): bool
    {
        return !($exception instanceof AppException) || ($exception instanceof CpanelApiException && in_array($exception->safeCode, ['cpanel_timeout', 'cpanel_network_error', 'cpanel_http_error', 'cpanel_invalid_json', 'cpanel_invalid_response', 'cpanel_response_too_large'], true));
    }

    private function safeCode(Throwable $exception, string $fallback): string
    {
        return $exception instanceof AppException ? $exception->safeCode : $fallback;
    }

    /** @return array<string,mixed> */
    private function decoded(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }
}
