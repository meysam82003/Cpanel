<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Queue\QueueService;

final class DeploymentRecoveryService
{
    private const RESUMABLE_STATES = [
        'queued', 'validating', 'validated', 'backup_started', 'backup_completed',
        'upload_started', 'upload_completed', 'extract_started', 'extract_completed',
        'deploying', 'health_check', 'rolling_back', 'reconciliation_required',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly QueueService $queue,
        private readonly AuditLogger $audit,
        private readonly string $uploadRoot,
        private readonly int $cooldownSeconds = 60,
        private readonly int $maxRecoveryAttempts = 3,
    ) {
    }

    public function recover(int $limit = 10): int
    {
        $limit = max(1, min(50, $limit));
        $before = gmdate('Y-m-d H:i:s', time() - max(30, min(3600, $this->cooldownSeconds)));
        $placeholders = implode(',', array_fill(0, count(self::RESUMABLE_STATES), '?'));
        $rows = $this->database->all(
            "SELECT d.id FROM deployments d LEFT JOIN queue_jobs q ON q.id = CASE WHEN d.status = 'rolling_back' AND d.rollback_job_id IS NOT NULL THEN d.rollback_job_id ELSE d.queue_job_id END WHERE d.status IN ({$placeholders}) AND d.recovery_attempts < ? AND d.updated_at < ? AND (d.last_recovery_at IS NULL OR d.last_recovery_at < ?) AND (q.id IS NULL OR q.status = 'failed') ORDER BY d.updated_at ASC, d.id ASC LIMIT {$limit}",
            [...self::RESUMABLE_STATES, max(1, min(10, $this->maxRecoveryAttempts)), $before, $before]
        );
        $recovered = 0;
        foreach ($rows as $row) {
            try {
                if ($this->enqueue((int) $row['id'], $before)) {
                    $recovered++;
                }
            } catch (AppException) {
                // The persisted deployment remains visible for manual guidance.
            }
        }
        return $recovered;
    }

    private function enqueue(int $deploymentId, string $before): bool
    {
        return $this->database->transaction(function (Database $database) use ($deploymentId, $before): bool {
            $deployment = $database->one('SELECT * FROM deployments WHERE id = ? FOR UPDATE', [$deploymentId]);
            if ($deployment === null || !in_array((string) $deployment['status'], self::RESUMABLE_STATES, true) || (int) $deployment['recovery_attempts'] >= max(1, min(10, $this->maxRecoveryAttempts)) || ($deployment['last_recovery_at'] !== null && (string) $deployment['last_recovery_at'] >= $before)) {
                return false;
            }
            $rollbackRecovery = (string) $deployment['status'] === 'rolling_back' && $deployment['rollback_job_id'] !== null;
            $previousJobId = (int) ($rollbackRecovery ? $deployment['rollback_job_id'] : $deployment['queue_job_id']);
            if ($previousJobId > 0) {
                $job = $database->one('SELECT status, last_error_code FROM queue_jobs WHERE id = ?', [$previousJobId]);
                if ($job !== null && (string) $job['status'] !== 'failed') {
                    return false;
                }
                if ((string) $deployment['status'] === 'queued' && !in_array((string) ($job['last_error_code'] ?? ''), ['queue_lease_expired', 'queue_lease_lost', 'operation_locked', 'operation_lock_lost'], true)) {
                    return false;
                }
            } elseif ((string) $deployment['status'] === 'queued') {
                return false;
            }

            $attempt = (int) $deployment['recovery_attempts'] + 1;
            if ($rollbackRecovery) {
                $type = 'deployment.rollback';
                $payload = ['deployment_id' => $deploymentId];
            } else {
                $type = 'deployment.run';
                $package = $database->one('SELECT * FROM deployment_packages WHERE id = ? AND user_id = ? AND account_id = ?', [(int) $deployment['package_id'], (int) $deployment['user_id'], (int) $deployment['account_id']]);
                if ($package === null || !hash_equals((string) $deployment['package_checksum'], (string) $package['checksum_sha256']) || ($this->requiresLocalPackage($deployment) && !$this->secureLocalPackage((string) $package['local_path']))) {
                    throw new AppException('Deployment recovery package is unavailable or failed integrity validation.', 409, 'deployment_recovery_package_unavailable', [], 'deployment.rollback');
                }
                $metadata = json_decode((string) $deployment['package_metadata_json'], true);
                if (!is_array($metadata)) {
                    throw new AppException('Deployment recovery metadata is invalid.', 409, 'deployment_package_integrity_failed', [], 'deployment.rollback');
                }
                $payload = [
                    'deployment_id' => $deploymentId,
                    'package_id' => (int) $deployment['package_id'],
                    'package_path' => (string) $package['local_path'],
                    'package_sha256' => (string) $deployment['package_checksum'],
                    'package_metadata' => $metadata,
                    'recovery_attempt' => $attempt,
                ];
            }
            $jobId = $this->queue->dispatch($type, (int) $deployment['user_id'], (int) $deployment['account_id'], $payload, 'deployment-recovery:' . $deploymentId . ':after:' . $previousJobId . ':attempt:' . $attempt, 'default', 1);
            $column = $rollbackRecovery ? 'rollback_job_id' : 'queue_job_id';
            $database->execute("UPDATE deployments SET {$column} = ?, recovery_attempts = ?, last_recovery_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$jobId, $attempt, $deploymentId]);
            $this->audit->record((int) $deployment['user_id'], (int) $deployment['account_id'], 'deployment.recovery_queue', 'success', 'deployment', (string) $deploymentId, ['job_id' => $jobId, 'previous_job_id' => $previousJobId, 'attempt' => $attempt, 'type' => $type]);
            return true;
        });
    }

    private function secureLocalPackage(string $path): bool
    {
        $real = realpath($path);
        $root = realpath($this->uploadRoot);
        return $real !== false && $root !== false && $real === $path && !is_link($path) && is_file($real) && is_readable($real) && str_starts_with($real . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /** @param array<string,mixed> $deployment */
    private function requiresLocalPackage(array $deployment): bool
    {
        $status = (string) $deployment['status'];
        if (in_array($status, ['queued', 'validating', 'validated', 'backup_started', 'backup_completed'], true)) {
            return true;
        }
        if ($status !== 'reconciliation_required') {
            return false;
        }
        $details = json_decode((string) ($deployment['reconciliation_json'] ?? ''), true);
        return is_array($details) && (string) ($details['phase'] ?? '') === 'backup';
    }
}
