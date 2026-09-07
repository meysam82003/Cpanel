<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;
use App\FileManager\FileManagerService;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use App\Security\OperationLockService;

final class DeploymentJobHandler implements JobHandler
{
    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly FileManagerService $files,
        private readonly HealthCheckService $health,
        private readonly DeploymentRollbackExecutor $rollback,
        private readonly OperationLockService $locks,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId() ?? throw new AppException('Deployment job has no owner.', 500, 'queue_owner_missing');
        $accountId = $context->accountId() ?? throw new AppException('Deployment job has no host.', 500, 'queue_account_missing');
        $deploymentId = (int) ($payload['deployment_id'] ?? 0);
        $deployment = $this->database->one('SELECT * FROM deployments WHERE id = ? AND user_id = ? AND account_id = ?', [$deploymentId, $userId, $accountId]);
        if ($deployment === null || !in_array($deployment['status'], ['queued', 'failed'], true)) {
            throw new AppException('Deployment job is missing, not owned, or cannot run in its current state.', 409, 'deployment_state_invalid');
        }
        $package = (string) ($payload['package_path'] ?? '');
        if (!is_file($package) || !is_readable($package)) {
            throw new AppException('Deployment package expired before the worker could process it.', 410, 'deployment_package_expired', [], 'deploy.package');
        }
        $destination = (string) $deployment['destination'];
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $connection = $this->accounts->connection($userId, $accountId);
        $work = $root . '/.tcm-deploy/deployment-' . $deploymentId;
        $stage = $work . '/stage';
        $backup = $root . '/.tcm-backups/deployment-' . $deploymentId . '-' . gmdate('Ymd-His') . '.zip';
        $rollbackPath = $root . '/.tcm-rollbacks/deployment-' . $deploymentId . '-' . gmdate('Ymd-His');
        $lockKey = 'deploy:' . $accountId . ':' . hash('sha256', $destination);
        $lockToken = $this->locks->acquire($lockKey, 1800);
        $destinationExisted = false;
        $destinationPermissions = '0755';
        $backupCreated = false;
        $destinationChanged = false;
        $this->database->execute("UPDATE deployments SET status = 'validating', started_at = COALESCE(started_at, CURRENT_TIMESTAMP), error_code = NULL WHERE id = ?", [$deploymentId]);
        try {
            $context->progress(10, 'validating');
            $this->event($deploymentId, 'validate', 'completed', 'deployment.validated');
            $this->ensureDirectory($connection, $work, $root);
            $this->ensureDirectory($connection, $stage, $root);
            $this->ensureDirectory($connection, dirname($backup), $root);
            $this->ensureDirectory($connection, dirname($rollbackPath), $root);

            try {
                $destinationInfo = $this->files->info($userId, $accountId, $destination);
                $destinationExisted = true;
                $destinationPermissions = $this->permissions((string) ($destinationInfo['mode'] ?? $destinationInfo['permissions'] ?? '0755'));
            } catch (AppException $exception) {
                if ($exception->safeCode !== 'remote_path_not_found') {
                    throw $exception;
                }
            }
            $this->database->execute('UPDATE deployments SET destination_existed = ? WHERE id = ?', [(int) $destinationExisted, $deploymentId]);

            if ($destinationExisted) {
                $this->stage($deploymentId, 'backup', 'running', 'deployment.backup_running', 25, $context);
                $this->files->compress($userId, $accountId, [$destination], $backup, 'zip');
                $backupCreated = true;
                $this->database->execute('UPDATE deployments SET backup_ref = ? WHERE id = ?', [$backup, $deploymentId]);
                $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, remote_path, status, metadata_json, completed_at) VALUES (?, ?, 'deployment', ?, ?, 'completed', ?, CURRENT_TIMESTAMP)", [$userId, $accountId, $destination, $backup, json_encode(['deployment_id' => $deploymentId, 'rollback_path' => $rollbackPath], JSON_THROW_ON_ERROR)]);
                $this->event($deploymentId, 'backup', 'completed', 'deployment.backup_completed', ['backup_ref' => $backup]);
            } else {
                $this->event($deploymentId, 'backup', 'skipped', 'deployment.backup_skipped', ['destination_existed' => $destinationExisted]);
            }

            $this->stage($deploymentId, 'upload', 'running', 'deployment.upload_running', 38, $context);
            $this->files->upload($userId, $accountId, $work, [['path' => $package, 'name' => 'package.zip']], 'overwrite');
            $remotePackage = $work . '/package.zip';
            $this->event($deploymentId, 'upload', 'completed', 'deployment.upload_completed');

            $this->stage($deploymentId, 'extract', 'running', 'deployment.extract_running', 52, $context);
            $this->files->extract($userId, $accountId, $remotePackage, $stage);
            $this->event($deploymentId, 'extract', 'completed', 'deployment.extract_completed');

            $this->stage($deploymentId, 'deploy', 'running', 'deployment.deploy_running', 68, $context);
            if ($destinationExisted) {
                $this->files->renameOrMove($userId, $accountId, $destination, $rollbackPath);
                $this->database->execute('UPDATE deployments SET rollback_path = ? WHERE id = ?', [$rollbackPath, $deploymentId]);
                $this->event($deploymentId, 'deploy', 'running', 'deployment.destination_preserved', ['rollback_path' => $rollbackPath]);
                $destinationChanged = true;
            }
            $this->ensureDestinationDirectory($connection, $destination, $root, $destinationPermissions);
            $destinationChanged = true;
            $topLevel = is_array($payload['top_level'] ?? null) ? array_values(array_map('strval', $payload['top_level'])) : [];
            if ($topLevel === []) {
                throw new AppException('Deployment package has no deployable entries.', 422, 'deployment_empty_package');
            }
            foreach ($topLevel as $name) {
                if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') {
                    throw new AppException('Deployment package top-level path is invalid.', 422, 'invalid_zip_path');
                }
                $source = $stage . '/' . $name;
                $target = $destination . '/' . $name;
                $this->files->copy($userId, $accountId, $source, $target);
                $this->database->execute('INSERT INTO deployment_files (deployment_id, remote_path, action) VALUES (?, ?, \'copy\')', [$deploymentId, $target]);
            }
            $this->event($deploymentId, 'deploy', 'completed', 'deployment.deploy_completed', ['entries' => count($topLevel)]);

            $healthResult = null;
            $healthUrl = is_string($deployment['health_check_url']) ? $deployment['health_check_url'] : null;
            if ($healthUrl !== null && $healthUrl !== '') {
                $this->stage($deploymentId, 'health', 'running', 'deployment.health_running', 88, $context);
                $healthResult = $this->health->check($healthUrl, (string) ($account['main_domain'] ?? ''));
                $this->database->execute('UPDATE deployments SET health_status = ? WHERE id = ?', [$healthResult['status'], $deploymentId]);
                if (!$healthResult['healthy']) {
                    throw new AppException('The deployed site failed its health check.', 502, 'deployment_health_failed', ['status' => $healthResult['status']], 'deployment.rollback');
                }
                $this->event($deploymentId, 'health', 'completed', 'deployment.health_passed', $healthResult);
            } else {
                $this->event($deploymentId, 'health', 'skipped', 'deployment.health_skipped');
            }
            $context->progress(98, 'completing');
            $this->database->execute("UPDATE deployments SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
            $this->event($deploymentId, 'complete', 'completed', 'deployment.completed');
            $this->database->execute("INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, 'deployment', 'notification.deployment_title', 'notification.deployment_done', ?)", [$userId, json_encode(['id' => $deploymentId], JSON_THROW_ON_ERROR)]);
            $this->audit->record($userId, $accountId, 'deployment.complete', 'success', 'deployment', (string) $deploymentId, ['destination' => $destination, 'backup_ref' => $backupCreated ? $backup : null, 'health' => $healthResult]);
            return ['deployment_id' => $deploymentId, 'destination' => $destination, 'backup_ref' => $backupCreated ? $backup : null, 'health' => $healthResult, 'rolled_back' => false];
        } catch (\Throwable $exception) {
            $safeCode = $exception instanceof AppException ? $exception->safeCode : 'deployment_failed';
            $this->event($deploymentId, 'failed', 'failed', 'deployment.failed', ['error_code' => $safeCode]);
            $this->database->execute("UPDATE deployments SET status = 'failed', error_code = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?", [$safeCode, $deploymentId]);
            if ($destinationChanged) {
                try {
                    $fresh = $this->database->one('SELECT * FROM deployments WHERE id = ?', [$deploymentId]) ?? $deployment;
                    $this->event($deploymentId, 'rollback', 'running', 'deployment.rollback_running');
                    $this->rollback->restore($userId, $accountId, $fresh);
                    $this->database->execute("UPDATE deployments SET status = 'rolled_back', rollback_path = NULL, rolled_back_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
                    $this->event($deploymentId, 'rollback', 'completed', 'deployment.rollback_completed');
                } catch (\Throwable $rollbackError) {
                    $this->database->execute("UPDATE deployments SET status = 'rollback_failed' WHERE id = ?", [$deploymentId]);
                    $this->event($deploymentId, 'rollback', 'failed', 'deployment.rollback_failed', ['error_code' => $rollbackError instanceof AppException ? $rollbackError->safeCode : 'rollback_failed']);
                }
            } else {
                $this->event($deploymentId, 'rollback', 'skipped', 'deployment.rollback_not_required');
            }
            $this->audit->record($userId, $accountId, 'deployment.complete', 'failed', 'deployment', (string) $deploymentId, ['error_code' => $safeCode]);
            $this->database->execute("INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, 'deployment', 'notification.deployment_title', 'notification.deployment_failed', ?)", [$userId, json_encode(['id' => $deploymentId, 'code' => $safeCode], JSON_THROW_ON_ERROR)]);
            throw $exception;
        } finally {
            try {
                $this->files->delete($userId, $accountId, $work, true);
            } catch (\Throwable) {
                // Cleanup service also removes expired deployment work directories.
            }
            @unlink($package);
            $this->locks->release($lockKey, $lockToken);
        }
    }

    /** @param array{base_url:string,username:string,token:string} $connection */
    private function ensureDirectory(array $connection, string $directory, string $root): void
    {
        $relative = trim(substr($directory, strlen($root)), '/');
        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            try {
                $this->cpanel->callLegacyApi2($connection, 'Fileman', 'mkdir', ['path' => $current, 'name' => $segment, 'permissions' => '0700'], false);
            } catch (CpanelApiException $exception) {
                $check = $this->cpanel->call($connection, 'Fileman', 'get_file_information', ['path' => $current . '/' . $segment]);
                $item = is_array($check['data']) && array_is_list($check['data']) ? ($check['data'][0] ?? []) : $check['data'];
                if (!is_array($item) || (int) ($item['exists'] ?? 0) !== 1 || !in_array(strtolower((string) ($item['type'] ?? '')), ['dir', 'directory'], true)) {
                    throw $exception;
                }
            }
            $current .= '/' . $segment;
        }
    }

    /** @param array{base_url:string,username:string,token:string} $connection */
    private function ensureDestinationDirectory(array $connection, string $directory, string $root, string $permissions): void
    {
        $parent = dirname($directory);
        $this->ensureDirectory($connection, $parent, $root);
        try {
            $this->cpanel->callLegacyApi2($connection, 'Fileman', 'mkdir', ['path' => $parent, 'name' => basename($directory), 'permissions' => $permissions], false);
        } catch (CpanelApiException $exception) {
            $check = $this->cpanel->call($connection, 'Fileman', 'get_file_information', ['path' => $directory]);
            $item = is_array($check['data']) && array_is_list($check['data']) ? ($check['data'][0] ?? []) : $check['data'];
            if (!is_array($item) || (int) ($item['exists'] ?? 0) !== 1) {
                throw $exception;
            }
        }
    }

    private function permissions(string $value): string
    {
        if (preg_match('/0?([0-7]{3})$/', trim($value), $match)) {
            return '0' . $match[1];
        }
        return '0755';
    }

    private function stage(int $deploymentId, string $stage, string $status, string $message, int $progress, JobContext $context): void
    {
        $this->database->execute('UPDATE deployments SET status = ? WHERE id = ?', [$stage, $deploymentId]);
        $this->event($deploymentId, $stage, $status, $message);
        $context->progress($progress, $message);
    }

    /** @param array<string,mixed> $metadata */
    private function event(int $deploymentId, string $stage, string $status, string $message, array $metadata = []): void
    {
        $this->database->execute('INSERT INTO deployment_events (deployment_id, stage, status, message_key, metadata_json) VALUES (?, ?, ?, ?, ?)', [$deploymentId, $stage, $status, $message, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
