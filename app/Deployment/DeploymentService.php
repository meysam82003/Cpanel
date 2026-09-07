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
    public function deployPackage(int $userId, int $accountId, int $packageId, string $destination, ?string $healthCheckUrl): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $accountRoot = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $destination = $this->paths->normalize($destination, $accountRoot);
        if ($destination === $accountRoot) {
            throw new AppException('Deploying over the cPanel home root is blocked. Select a site directory.', 403, 'deployment_root_blocked', [], 'deploy.start');
        }
        $relativeDestination = ltrim(substr($destination, strlen($accountRoot)), '/');
        $reservedTopLevel = strtolower(explode('/', $relativeDestination)[0] ?? '');
        if (in_array($reservedTopLevel, ['.tcm-deploy', '.tcm-backups', '.tcm-rollbacks', '.tcpm-versions', '.trash'], true)) {
            throw new AppException('Deployment destination overlaps application-managed recovery storage.', 403, 'deployment_reserved_path', [], 'deploy.start');
        }
        $healthCheckUrl = $this->healthCheckUrl($healthCheckUrl, (string) ($account['main_domain'] ?? ''));
        $package = $this->availablePackage($userId, $accountId, $packageId);
        $real = $this->securePackagePath((string) $package['local_path']);
        $metadata = $this->packages->validate($real);
        if (!hash_equals((string) $package['checksum_sha256'], (string) $metadata['sha256'])) {
            throw new AppException('Deployment package changed after registration.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
        }
        $packageName = (string) $package['original_name'];
        $preview = ['target' => $destination, 'package' => $packageName, 'files' => $metadata['files'], 'compressed_bytes' => $metadata['compressed_bytes'], 'uncompressed_bytes' => $metadata['uncompressed_bytes'], 'backup' => true, 'health_check' => $healthCheckUrl !== null];
        [$deploymentId, $jobId] = $this->database->transaction(function (Database $database) use ($userId, $accountId, $packageId, $real, $metadata, $packageName, $destination, $healthCheckUrl, $preview): array {
            $locked = $database->one('SELECT * FROM deployment_packages WHERE id = ? AND user_id = ? AND account_id = ? FOR UPDATE', [$packageId, $userId, $accountId]);
            $currentChecksum = hash_file('sha256', $real);
            if ($locked === null || $locked['consumed_at'] !== null || strtotime((string) $locked['expires_at']) < time() || (string) $locked['local_path'] !== $real || !is_string($currentChecksum) || !hash_equals((string) $locked['checksum_sha256'], $currentChecksum) || !hash_equals((string) $metadata['sha256'], $currentChecksum)) {
                throw new AppException('Deployment package expired, changed, was used, or is not owned by this account.', 409, 'deployment_package_unavailable', [], 'security.idor');
            }
            $database->execute(
                'INSERT INTO deployments (user_id, account_id, package_id, package_name, package_checksum, package_metadata_json, destination, status, backup_enabled, health_check_url, switch_state) VALUES (?, ?, ?, ?, ?, ?, ?, \'queued\', 1, ?, \'none\')',
                [$userId, $accountId, $packageId, $packageName, $currentChecksum, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $destination, $healthCheckUrl]
            );
            $deploymentId = $database->lastInsertId();
            $jobId = $this->queue->dispatch('deployment.run', $userId, $accountId, [
                'deployment_id' => $deploymentId,
                'package_id' => $packageId,
                'package_path' => $real,
                'package_sha256' => $currentChecksum,
                'package_metadata' => $metadata,
            ], 'deployment:' . $deploymentId . ':' . $currentChecksum, 'default', 1);
            $database->execute('UPDATE deployments SET queue_job_id = ? WHERE id = ? AND user_id = ? AND account_id = ?', [$jobId, $deploymentId, $userId, $accountId]);
            $database->execute('UPDATE deployment_packages SET consumed_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND account_id = ? AND consumed_at IS NULL', [$packageId, $userId, $accountId]);
            $database->execute('INSERT INTO deployment_events (deployment_id, stage, status, message_key, metadata_json) VALUES (?, \'validate\', \'completed\', \'deployment.validated\', ?)', [$deploymentId, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            $this->audit->record($userId, $accountId, 'deployment.queue', 'success', 'deployment', (string) $deploymentId, $preview + ['job_id' => $jobId, 'package_id' => $packageId]);
            return [$deploymentId, $jobId];
        });
        return ['deployment_id' => $deploymentId, 'job_id' => $jobId, 'preview' => $preview];
    }

    public function rollback(int $userId, int $accountId, int $deploymentId): int
    {
        $this->owned($userId, $accountId, $deploymentId);
        return $this->database->transaction(function (Database $database) use ($userId, $accountId, $deploymentId): int {
            $deployment = $database->one('SELECT * FROM deployments WHERE id = ? AND user_id = ? AND account_id = ? FOR UPDATE', [$deploymentId, $userId, $accountId]);
            if ($deployment === null) {
                throw new AppException('Deployment was not found or does not belong to you.', 404, 'deployment_not_found', [], 'security.idor');
            }
            $hasDirectoryRollback = is_string($deployment['rollback_path']) && $deployment['rollback_path'] !== '';
            $hasArchiveRollback = is_string($deployment['backup_ref']) && $deployment['backup_ref'] !== '';
            $canRemoveNewDestination = !(bool) $deployment['destination_existed'];
            if (!in_array((string) $deployment['status'], ['completed', 'rollback_failed'], true) || (!$hasDirectoryRollback && !$hasArchiveRollback && !$canRemoveNewDestination)) {
                throw new AppException('This deployment has no usable rollback point.', 422, 'rollback_unavailable', [], 'deployment.rollback');
            }
            if ($deployment['rollback_job_id'] !== null) {
                $active = $database->one("SELECT id FROM queue_jobs WHERE id = ? AND user_id = ? AND account_id = ? AND status IN ('queued', 'running')", [(int) $deployment['rollback_job_id'], $userId, $accountId]);
                if ($active !== null) {
                    return (int) $active['id'];
                }
            }
            $previousJobId = (int) ($deployment['rollback_job_id'] ?? 0);
            $jobId = $this->queue->dispatch(
                'deployment.rollback',
                $userId,
                $accountId,
                ['deployment_id' => $deploymentId],
                'deployment-rollback:' . $deploymentId . ':after:' . $previousJobId,
                'default',
                1,
            );
            $database->execute('UPDATE deployments SET rollback_job_id = ? WHERE id = ? AND user_id = ? AND account_id = ?', [$jobId, $deploymentId, $userId, $accountId]);
            $this->audit->record($userId, $accountId, 'deployment.rollback_queue', 'success', 'deployment', (string) $deploymentId, ['job_id' => $jobId]);
            return $jobId;
        });
    }

    /** @return list<array<string,mixed>> */
    public function list(int $userId, int $accountId): array
    {
        $this->accounts->getOwned($userId, $accountId);
        return $this->database->all('SELECT id, package_id, queue_job_id, rollback_job_id, package_name, package_checksum, destination, stage_path, switch_state, status, backup_enabled, backup_ref, backup_size, rollback_path, destination_existed, health_check_url, health_status, error_code, reconciliation_json, started_at, completed_at, rolled_back_at, rollback_verified_at, created_at FROM deployments WHERE user_id = ? AND account_id = ? ORDER BY id DESC LIMIT 100', [$userId, $accountId]);
    }

    /** @return array<string,mixed> */
    public function status(int $userId, int $accountId, int $deploymentId): array
    {
        $deployment = $this->owned($userId, $accountId, $deploymentId);
        $deployment['events'] = $this->database->all('SELECT stage, status, message_key, metadata_json, created_at FROM deployment_events WHERE deployment_id = ? ORDER BY id', [$deploymentId]);
        return $deployment;
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

    /** @return array<string,mixed> */
    private function availablePackage(int $userId, int $accountId, int $packageId): array
    {
        $row = $this->database->one('SELECT * FROM deployment_packages WHERE id = ? AND user_id = ? AND account_id = ?', [$packageId, $userId, $accountId]);
        if ($row === null || $row['consumed_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
            throw new AppException('Deployment package expired, was used, or does not belong to this account.', 404, 'deployment_package_unavailable', [], 'security.idor');
        }
        return $row;
    }

    private function securePackagePath(string $path): string
    {
        $real = realpath($path);
        $root = realpath($this->uploadRoot);
        if ($real === false || $root === false || is_link($path) || !is_file($real) || !is_readable($real) || !str_starts_with($real . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new AppException('Deployment upload is outside secure temporary storage.', 422, 'invalid_deployment_upload', [], 'deploy.package');
        }
        return $real;
    }

    private function healthCheckUrl(?string $url, string $mainDomain): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        $mainDomain = strtolower(rtrim($mainDomain, '.'));
        if (!is_array($parts) || strlen($url) > 1024 || preg_match('/[\x00-\x1F\x7F]/u', $url) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '' || $mainDomain === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443) || ($host !== $mainDomain && !str_ends_with($host, '.' . $mainDomain))) {
            throw new AppException('Health check must be an HTTPS URL on the account main domain or one of its subdomains.', 422, 'invalid_health_check_url', [], 'deploy.health');
        }
        return $url;
    }
}
