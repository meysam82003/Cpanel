<?php

declare(strict_types=1);

namespace App\Plans;

use App\Core\AppException;
use App\Core\Database;

final class PlanGuard
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string,mixed> */
    public function plan(int $userId): array
    {
        $row = $this->database->one(
            "SELECT p.*, COALESCE(u.host_limit, p.host_limit) AS effective_host_limit FROM users u JOIN user_plans up ON up.user_id = u.id AND up.status = 'active' JOIN plans p ON p.id = up.plan_id WHERE u.id = ? AND (up.ends_at IS NULL OR up.ends_at > CURRENT_TIMESTAMP) ORDER BY up.id DESC LIMIT 1",
            [$userId]
        );
        if ($row === null) {
            throw new AppException('No active plan is assigned to this user.', 403, 'active_plan_missing', [], 'plans');
        }
        return $row;
    }

    public function feature(int $userId, string $feature): void
    {
        $columns = ['database_manager', 'sql_console', 'backup_enabled', 'deployment_enabled'];
        if (!in_array($feature, $columns, true)) {
            throw new AppException('Plan feature identifier is invalid.', 500, 'invalid_plan_feature');
        }
        $plan = $this->plan($userId);
        if (!(bool) $plan[$feature]) {
            throw new AppException('This feature is not enabled by your current plan.', 403, 'plan_feature_disabled', ['feature' => $feature], 'plans');
        }
    }

    public function upload(int $userId, int $bytes): void
    {
        $plan = $this->plan($userId);
        if ($bytes < 0 || $bytes > (int) $plan['max_upload_bytes']) {
            throw new AppException('The uploaded file exceeds your plan limit.', 413, 'plan_upload_limit', ['limit' => (int) $plan['max_upload_bytes']], 'plans');
        }
    }

    public function dailyOperation(int $userId): void
    {
        $plan = $this->plan($userId);
        $row = $this->database->one("SELECT COUNT(*) AS operations FROM audit_logs WHERE user_id = ? AND created_at >= ? AND action NOT IN ('api.request', 'file.browse', 'host.health')", [$userId, gmdate('Y-m-d 00:00:00')]);
        if ((int) ($row['operations'] ?? 0) >= (int) $plan['daily_operation_limit']) {
            throw new AppException('Your daily operation limit has been reached.', 429, 'daily_operation_limit', ['limit' => (int) $plan['daily_operation_limit']], 'plans');
        }
    }
}
