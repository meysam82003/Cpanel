<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use App\Security\OperationLockService;
use Throwable;

final class RollbackJobHandler implements JobHandler
{
    public function __construct(
        private readonly Database $database,
        private readonly DeploymentRollbackExecutor $rollback,
        private readonly OperationLockService $locks,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId() ?? throw new AppException('Rollback job has no owner.', 500, 'queue_owner_missing');
        $accountId = $context->accountId() ?? throw new AppException('Rollback job has no host.', 500, 'queue_account_missing');
        $deploymentId = (int) ($payload['deployment_id'] ?? 0);
        $deployment = $this->owned($deploymentId, $userId, $accountId);
        if ($deployment['rollback_job_id'] === null || (int) $deployment['rollback_job_id'] !== (int) $context->job['id']) {
            throw new AppException('Rollback job does not match the deployment recovery state.', 409, 'rollback_job_mismatch', [], 'security.idor');
        }
        if ((string) $deployment['status'] === 'rolled_back') {
            return ['deployment_id' => $deploymentId, 'restored' => true, 'reconciled' => true];
        }
        if (!in_array((string) $deployment['status'], ['completed', 'rollback_failed', 'rolling_back'], true)) {
            throw new AppException('Deployment is not in a rollback-compatible state.', 409, 'rollback_state_invalid', [], 'deployment.rollback');
        }
        $hasDirectory = is_string($deployment['rollback_path']) && $deployment['rollback_path'] !== '';
        $hasArchive = is_string($deployment['backup_ref']) && $deployment['backup_ref'] !== '';
        if (!$hasDirectory && !$hasArchive && (bool) $deployment['destination_existed']) {
            throw new AppException('Rollback point was not found for this deployment.', 404, 'rollback_unavailable', [], 'deployment.rollback');
        }

        $key = 'deploy:' . $accountId . ':' . hash('sha256', (string) $deployment['destination']);
        $token = $this->locks->acquire($key, 1800);
        try {
            $switchState = (string) ($deployment['switch_state'] ?? '');
            if (!str_starts_with($switchState, 'rollback_') || $switchState === 'rollback_complete') {
                $switchState = 'rollback_pending';
            }
            $this->database->execute("UPDATE deployments SET status = 'rolling_back', switch_state = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND account_id = ?", [$switchState, $deploymentId, $userId, $accountId]);
            $this->eventOnce($deploymentId, 'rollback', 'running', 'deployment.rollback_running');
            $context->progress(10, 'rollback_preparing');
            $this->locks->renew($key, $token, 1800);
            $result = $this->rollback->restore(
                $userId,
                $accountId,
                $this->owned($deploymentId, $userId, $accountId),
                function (string $state, array $metadata) use ($context, $deploymentId, $userId, $accountId, $key, $token): void {
                    $this->checkpoint($context, $deploymentId, $userId, $accountId, $key, $token, $state, $metadata);
                },
            );
            $this->locks->renew($key, $token, 1800);
            $context->progress(95, 'rollback_verifying');
            $this->database->execute("UPDATE deployments SET status = 'rolled_back', switch_state = 'rollback_complete', reconciliation_json = NULL, rolled_back_at = CURRENT_TIMESTAMP, rollback_verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND account_id = ?", [$deploymentId, $userId, $accountId]);
            $this->eventOnce($deploymentId, 'rollback', 'completed', 'deployment.rollback_completed', $result);
            $this->notificationOnce($deploymentId, $userId, 'notification.rollback_done', ['id' => $deploymentId]);
            $this->audit->record($userId, $accountId, 'deployment.rollback', 'success', 'deployment', (string) $deploymentId, $result);
            return ['deployment_id' => $deploymentId, 'restored' => true] + $result;
        } catch (Throwable $exception) {
            $safeCode = $exception instanceof AppException ? $exception->safeCode : 'rollback_failed';
            $metadata = ['phase' => 'rollback', 'error_code' => $safeCode];
            $this->database->execute("UPDATE deployments SET status = 'rollback_failed', reconciliation_json = ?, error_code = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND account_id = ?", [json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $safeCode, $deploymentId, $userId, $accountId]);
            $this->eventOnce($deploymentId, 'rollback', 'failed', 'deployment.rollback_failed', $metadata);
            $this->notificationOnce($deploymentId, $userId, 'notification.rollback_failed', ['id' => $deploymentId, 'code' => $safeCode]);
            $this->audit->record($userId, $accountId, 'deployment.rollback', 'failed', 'deployment', (string) $deploymentId, $metadata);
            throw $exception;
        } finally {
            $this->locks->release($key, $token);
        }
    }

    /** @return array<string,mixed> */
    private function owned(int $deploymentId, int $userId, int $accountId): array
    {
        $deployment = $this->database->one('SELECT * FROM deployments WHERE id = ? AND user_id = ? AND account_id = ?', [$deploymentId, $userId, $accountId]);
        if ($deployment === null) {
            throw new AppException('Rollback point was not found or is not owned by this user.', 404, 'rollback_unavailable', [], 'security.idor');
        }
        return $deployment;
    }

    private function eventOnce(int $deploymentId, string $stage, string $status, string $message, array $metadata = []): void
    {
        $existing = $this->database->one('SELECT id FROM deployment_events WHERE deployment_id = ? AND stage = ? AND status = ? AND message_key = ? LIMIT 1', [$deploymentId, $stage, $status, $message]);
        if ($existing === null) {
            $this->database->execute('INSERT INTO deployment_events (deployment_id, stage, status, message_key, metadata_json) VALUES (?, ?, ?, ?, ?)', [$deploymentId, $stage, $status, $message, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function checkpoint(JobContext $context, int $deploymentId, int $userId, int $accountId, string $key, string $token, string $state, array $metadata): void
    {
        if (!preg_match('/^rollback_(?:dir|archive|remove)_[a-z_]{3,28}$/', $state) || strlen($state) > 40) {
            throw new AppException('Rollback checkpoint is invalid.', 500, 'rollback_state_invalid');
        }
        $this->database->execute("UPDATE deployments SET status = 'rolling_back', switch_state = ?, reconciliation_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND account_id = ?", [$state, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $deploymentId, $userId, $accountId]);
        $this->locks->renew($key, $token, 1800);
        $progress = str_contains($state, 'restored') || str_contains($state, 'complete') ? 90 : (str_contains($state, 'quarantined') ? 60 : 30);
        try {
            $context->progress($progress, $state);
        } catch (AppException $exception) {
            if ($exception->safeCode !== 'queue_lease_lost') {
                throw $exception;
            }
        }
    }

    private function notificationOnce(int $deploymentId, int $userId, string $bodyKey, array $parameters): void
    {
        $this->database->transaction(function (Database $database) use ($deploymentId, $userId, $bodyKey, $parameters): void {
            $row = $database->one('SELECT d.rollback_notification_id, n.body_key, n.parameters_json, n.sent_at FROM deployments d LEFT JOIN notifications n ON n.id = d.rollback_notification_id AND n.user_id = d.user_id WHERE d.id = ? AND d.user_id = ? FOR UPDATE', [$deploymentId, $userId]);
            if ($row === null) {
                return;
            }
            $encoded = json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($row['rollback_notification_id'] !== null && (string) ($row['body_key'] ?? '') === $bodyKey && (string) ($row['parameters_json'] ?? '') === $encoded) {
                return;
            }
            $database->execute("INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, 'deployment', 'notification.deployment_title', ?, ?)", [$userId, $bodyKey, $encoded]);
            $database->execute('UPDATE deployments SET rollback_notification_id = ? WHERE id = ? AND user_id = ?', [$database->lastInsertId(), $deploymentId, $userId]);
        });
    }
}
