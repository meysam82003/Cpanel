<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\CleanupService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class CleanupServiceTest extends TestCase
{
    use SqliteTestDatabase;

    public function testExpiredBotSessionsAreDeletedAndConsumedPackagesArePreserved(): void
    {
        $database = $this->sqlite(<<<'SQL'
CREATE TABLE temporary_connections (expires_at TEXT); CREATE TABLE callback_states (expires_at TEXT);
CREATE TABLE confirmation_nonces (expires_at TEXT); CREATE TABLE miniapp_sessions (expires_at TEXT);
CREATE TABLE user_sessions (expires_at TEXT); CREATE TABLE replay_nonces (expires_at TEXT);
CREATE TABLE rate_limits (expires_at TEXT); CREATE TABLE download_tokens (expires_at TEXT, prepared_path TEXT NULL);
CREATE TABLE operation_locks (expires_at TEXT);
CREATE TABLE deployment_packages (id INTEGER PRIMARY KEY, local_path TEXT, expires_at TEXT, consumed_at TEXT NULL);
CREATE TABLE deployments (id INTEGER PRIMARY KEY, package_id INTEGER NULL, status TEXT NOT NULL);
CREATE TABLE telegram_updates (processed_at TEXT NULL, received_at TEXT); CREATE TABLE api_request_metrics (created_at TEXT);
CREATE TABLE queue_jobs (status TEXT, completed_at TEXT NULL); CREATE TABLE audit_logs (created_at TEXT);
CREATE TABLE recent_actions (created_at TEXT); CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
SQL);
        $database->execute("INSERT INTO user_sessions (expires_at) VALUES ('2000-01-01 00:00:00'), ('2999-01-01 00:00:00')");

        $root = sys_get_temp_dir() . '/tcm-cleanup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/temp', 0700, true));
        self::assertTrue(mkdir($root . '/logs', 0700));
        $pendingPath = $root . '/temp/pending.zip';
        $consumedPath = $root . '/temp/consumed.zip';
        file_put_contents($pendingPath, 'pending');
        file_put_contents($consumedPath, 'consumed');
        $database->execute("INSERT INTO deployment_packages (id, local_path, expires_at, consumed_at) VALUES (1, ?, '2000-01-01 00:00:00', NULL), (2, ?, '2000-01-01 00:00:00', CURRENT_TIMESTAMP)", [$pendingPath, $consumedPath]);
        $database->execute("INSERT INTO deployments (id, package_id, status) VALUES (1, 2, 'upload_started')");
        touch($consumedPath, time() - 172800);

        try {
            $counts = (new CleanupService($database, $root))->run();
            self::assertSame(1, $counts['user_sessions']);
            self::assertSame(1, (int) $database->one('SELECT COUNT(*) AS total FROM user_sessions')['total']);
            self::assertFileDoesNotExist($pendingPath);
            self::assertFileExists($consumedPath);
            self::assertSame(1, (int) $database->one('SELECT COUNT(*) AS total FROM deployment_packages')['total']);
        } finally {
            if (is_file($pendingPath)) {
                unlink($pendingPath);
            }
            if (is_file($consumedPath)) {
                unlink($consumedPath);
            }
            rmdir($root . '/temp');
            rmdir($root . '/logs');
            rmdir($root);
        }
    }
}
