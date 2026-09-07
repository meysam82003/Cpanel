<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Queue\QueueService;
use App\Security\PathGuard;

final class DeploymentService
{
    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly PathGuard $paths,
        private readonly ZipPackageValidator $packages,
        private readonly QueueService $queue,
        private readonly AuditLogger $audit,
        private readonly string $uploadRoot,
    ) {
    }

    /** @return array{deployment_id:int,job_id:int,preview:array<string,mixed>} */
    public function deploy(int $userId, int $accountId, string $packagePath, string $originalName, string $destination, ?string $healthCheckUrl, bool $backupEnabled = true): array
    {
        // A deployment rollback point is mandatory whenever a destination already exists.
        $backupEnabled = true;
        $real = realpath($packagePath);
        $root = realpath($this->uploadRoot);
        if ($real === false || $root === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new AppException('Deployment upload is outside secure temporary storage.', 422, 'invalid_deployment_upload', [], 'deploy.package');
        }
        $account = $this->accounts->getOwned($userId, $accountId);
        $accountRoot = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $destination = $this->paths->normalize($destination, $accountRoot);
        if ($destination === $accountRoot) {
            throw new AppException('Deploying over the cPanel home root is blocked. Select a site directory.', 403, 'deployment_root_blocked', [], 'deploy.start');
        }
        $metadata = $this->packages->validate($real);
        $originalName = basename(str_replace('\\', '/', $originalName));
        if (!preg_match('/^[\pL\pN._ -]{1,200}\.zip$/ui', $originalName)) {
            $originalName = 'release.zip';
        }
        if ($healthCheckUrl !== null && $healthCheckUrl !== '') {
            $parts = parse_url($healthCheckUrl);
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
                throw new AppException('Health check must be an HTTPS URL.', 422, 'invalid_health_check_url', [], 'deploy.health');
            }
        } else {
            $healthCheckUrl = null;
        }
        $this->database->execute(
            'INSERT INTO deployments (user_id, account_id, package_name, destination, status, backup_enabled, health_check_url) VALUES (?, ?, ?, ?, \'queued\', ?, ?)',
            [$userId, $accountId, $originalName, $destination, (int) $backupEnabled, $healthCheckUrl]
        );
        $deploymentId = $this->database->lastInsertId();
        $this->event($deploymentId, 'validate', 'completed', 'deployment.validated', $metadata);
        $jobId = $this->queue->dispatch('deployment.run', $userId, $accountId, [
            'deployment_id' => $deploymentId,
            'package_path' => $real,
            'destination' => $destination,
            'top_level' => $metadata['top_level'],
            'health_check_url' => $healthCheckUrl,
            'main_domain' => (string) ($account['main_domain'] ?? ''),
            'backup_enabled' => $backupEnabled,
        ], $metadata['sha256'] . '|' . $destination . '|' . $deploymentId);
        $preview = ['target' => $destination, 'package' => $originalName, 'files' => $metadata['files'], 'compressed_bytes' => $metadata['compressed_bytes'], 'uncompressed_bytes' => $metadata['uncompressed_bytes'], 'backup' => true, 'health_check' => $healthCheckUrl !== null];
        $this->audit->record($userId, $accountId, 'deployment.queue', 'success', 'deployment', (string) $deploymentId, $preview + ['job_id' => $jobId]);
        return ['deployment_id' => $deploymentId, 'job_id' => $jobId, 'preview' => $preview];
    }

    public function rollback(int $userId, int $accountId, int $deploymentId): int
    {
        $deployment = $this->owned($userId, $accountId, $deploymentId);
        if (!is_string($deployment['backup_ref']) || $deployment['backup_ref'] === '' || !in_array($deployment['status'], ['completed', 'failed', 'rolled_back'], true)) {
            throw new AppException('This deployment has no usable rollback point.', 422, 'rollback_unavailable', [], 'deployment.rollback');
        }
        return $this->queue->dispatch('deployment.rollback', $userId, $accountId, ['deployment_id' => $deploymentId], 'rollback|' . $deploymentId . '|' . microtime(true));
    }

    /** @return list<array<string,mixed>> */
    public function list(int $userId, int $accountId): array
    {
        $this->accounts->getOwned($userId, $accountId);
        return $this->database->all('SELECT id, package_name, destination, status, backup_enabled, backup_ref, rollback_path, destination_existed, health_check_url, health_status, error_code, started_at, completed_at, rolled_back_at, created_at FROM deployments WHERE user_id = ? AND account_id = ? ORDER BY id DESC LIMIT 100', [$userId, $accountId]);
    }

    /** @return array<string,mixed> */
    public function status(int $userId, int $accountId, int $deploymentId): array
    {
        $deployment = $this->owned($userId, $accountId, $deploymentId);
        $deployment['events'] = $this->database->all('SELECT stage, status, message_key, metadata_json, created_at FROM deployment_events WHERE deployment_id = ? ORDER BY id', [$deploymentId]);
        return $deployment;
    }

    /** @param array<string,mixed> $metadata */
    private function event(int $deploymentId, string $stage, string $status, string $messageKey, array $metadata = []): void
    {
        $this->database->execute('INSERT INTO deployment_events (deployment_id, stage, status, message_key, metadata_json) VALUES (?, ?, ?, ?, ?)', [$deploymentId, $stage, $status, $messageKey, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /** @return array<string,mixed> */
    private function owned(int $userId, int $accountId, int $deploymentId): array
    {
        $row = $this->database->one('SELECT * FROM deployments WHERE id = ? AND user_id = ? AND account_id = ?', [$deploymentId, $userId, $accountId]);
        if ($row === null) {
            throw new AppException('Deployment was not found or does not belong to you.', 404, 'deployment_not_found', [], 'security.idor');
        }
        return $row;
    }
}
