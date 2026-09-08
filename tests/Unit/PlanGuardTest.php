<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Plans\PlanGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class PlanGuardTest extends TestCase
{
    use SqliteTestDatabase;

    public function testReadOnlyAuditDoesNotConsumeMutationQuota(): void
    {
        $database = $this->sqlite(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, host_limit INTEGER NULL);
CREATE TABLE plans (id INTEGER PRIMARY KEY, host_limit INTEGER NOT NULL, daily_operation_limit INTEGER NOT NULL);
CREATE TABLE user_plans (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, plan_id INTEGER NOT NULL, status TEXT NOT NULL, ends_at TEXT NULL);
CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, action TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO users (id, host_limit) VALUES (7, NULL);
INSERT INTO plans (id, host_limit, daily_operation_limit) VALUES (1, 3, 2);
INSERT INTO user_plans (id, user_id, plan_id, status, ends_at) VALUES (1, 7, 1, 'active', NULL);
SQL);
        for ($index = 0; $index < 25; $index++) {
            $database->execute("INSERT INTO audit_logs (user_id, action) VALUES (7, 'api.request')");
        }
        foreach (['file.browse', 'host.health', 'file.upload'] as $action) {
            $database->execute('INSERT INTO audit_logs (user_id, action) VALUES (7, ?)', [$action]);
        }

        $guard = new PlanGuard($database);
        $guard->dailyOperation(7);
        self::assertTrue(true);

        $database->execute("INSERT INTO audit_logs (user_id, action) VALUES (7, 'database.delete')");
        try {
            $guard->dailyOperation(7);
            self::fail('The actual mutation limit was not enforced.');
        } catch (AppException $exception) {
            self::assertSame('daily_operation_limit', $exception->safeCode);
        }
    }
}
