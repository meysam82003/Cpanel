<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use App\Security\OperationLockService;

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
        $deployment = $this->database->one('SELECT * FROM deployments WHERE id = ? AND user_id = ? AND account_id = ?', [$deploymentId, $userId, $accountId]);
        if ($deployment === null || !is_string($deployment['backup_ref']) || $deployment['backup_ref'] === '') {
            throw new AppException('Rollback point was not found or is not owned by this user.', 404, 'rollback_unavailable', [], 'security.idor');
        }
        $key = 'deploy:' . $accountId . ':' . hash('sha256', (string) $deployment['destination']);
        $token = $this->locks->acquire($key, 1200);
        try {
            $context->progress(10, 'rollback_preparing');
            $this->database->execute('INSERT INTO deployment_events (deployment_id, stage, status, message_key, metadata_json) VALUES (?, \'rollback\', \'running\', \'deployment.rollback_running\', \'{}\')', [$deploymentId]);
            $result = $this->rollback->restore($userId, $accountId, $deployment);
            $context->progress(95, 'rollback_verifying');
            $this->database->execute("UPDATE deployments SET status = 'rolled_back', rollback_path = NULL, rolled_back_at = CURRENT_TIMESTAMP WHERE id = ?", [$deploymentId]);
            $this->database->execute('INSERT INTO deployment_events (deployment_id, stage, status, message_key, metadata_json) VALUES (?, \'rollback\', \'completed\', \'deployment.rollback_completed\', \'{}\')', [$deploymentId]);
            $this->audit->record($userId, $accountId, 'deployment.rollback', 'success', 'deployment', (string) $deploymentId, $result);
            return ['deployment_id' => $deploymentId, 'restored' => true] + $result;
        } finally {
            $this->locks->release($key, $token);
        }
    }
}
