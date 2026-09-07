<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Deployment\DeploymentPackageService;
use App\Deployment\DeploymentService;
use App\Deployment\ZipPackageValidator;
use App\Queue\QueueService;
use App\Security\Crypto;
use App\Security\PathGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;
use ZipArchive;

final class DeploymentIntakeServiceTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;
    private DeploymentPackageService $packageService;
    private DeploymentService $deployments;
    private string $uploadRoot;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is required for deployment package tests.');
        }
        $this->uploadRoot = sys_get_temp_dir() . '/tcm-deployment-intake-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->uploadRoot, 0700, true));
        $this->database = $this->sqlite(<<<'SQL'
CREATE TABLE cpanel_accounts (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, root_path TEXT NULL,
 cpanel_username TEXT NOT NULL, base_url TEXT, main_domain TEXT, encrypted_token TEXT
);
CREATE TABLE audit_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, action TEXT,
 target_type TEXT, target_ref TEXT, result TEXT, request_id TEXT, ip_address BLOB,
 metadata_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE recent_actions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, action TEXT,
 resource_type TEXT, resource_ref TEXT, metadata_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE queue_jobs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL, job_type TEXT NOT NULL,
 user_id INTEGER NULL, account_id INTEGER NULL, payload_encrypted TEXT NOT NULL,
 result_encrypted TEXT NULL, status TEXT NOT NULL, progress INTEGER NOT NULL,
 status_message TEXT NULL, attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL,
 idempotency_key TEXT UNIQUE, available_at TEXT DEFAULT CURRENT_TIMESTAMP,
 reserved_at TEXT NULL, reservation_token TEXT NULL, completed_at TEXT NULL,
 last_error_code TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE deployment_packages (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 original_name TEXT NOT NULL, local_path TEXT NOT NULL, checksum_sha256 TEXT NOT NULL,
 metadata_json TEXT NOT NULL, expires_at TEXT NOT NULL, consumed_at TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE deployments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 package_id INTEGER NULL, queue_job_id INTEGER NULL UNIQUE, rollback_job_id INTEGER NULL,
 package_name TEXT NOT NULL, package_checksum TEXT NULL, package_metadata_json TEXT NULL,
 destination TEXT NOT NULL, stage_path TEXT NULL, switch_state TEXT NOT NULL DEFAULT 'none',
 status TEXT NOT NULL DEFAULT 'created', backup_enabled INTEGER NOT NULL DEFAULT 1,
        backup_ref TEXT NULL, backup_size INTEGER NULL, backup_checksum TEXT NULL, rollback_path TEXT NULL,
 destination_existed INTEGER NOT NULL DEFAULT 0, health_check_url TEXT NULL,
 health_status INTEGER NULL, error_code TEXT NULL, reconciliation_json TEXT NULL,
        notification_id INTEGER NULL, rollback_notification_id INTEGER NULL,
        recovery_attempts INTEGER NOT NULL DEFAULT 0, last_recovery_at TEXT NULL,
        started_at TEXT NULL, completed_at TEXT NULL,
 rolled_back_at TEXT NULL, rollback_verified_at TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE deployment_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, deployment_id INTEGER NOT NULL, stage TEXT NOT NULL,
 status TEXT NOT NULL, message_key TEXT NOT NULL, metadata_json TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $crypto = new Crypto([1 => str_repeat('d', 32)], 1);
        $this->queue = new QueueService($this->database, $crypto);
        $accounts = new AccountRepository($this->database, $crypto);
        $validator = new ZipPackageValidator();
        $audit = new AuditLogger($this->database);
        $this->packageService = new DeploymentPackageService($this->database, $accounts, $validator);
        $this->deployments = new DeploymentService($this->database, $accounts, new PathGuard(), $validator, $this->queue, $audit, $this->uploadRoot);
        $this->database->execute("INSERT INTO cpanel_accounts VALUES (10, 1, '/home/alice', 'alice', 'https://example.test:2083', 'example.test', NULL), (20, 2, '/home/bob', 'bob', 'https://bob.test:2083', 'bob.test', NULL)");
    }

    protected function tearDown(): void
    {
        if (!isset($this->uploadRoot) || !is_dir($this->uploadRoot)) {
            return;
        }
        foreach (glob($this->uploadRoot . '/*') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->uploadRoot);
    }

    public function testPackageContractAndDeploymentIntakeAreAtomicEncryptedAndSingleAttempt(): void
    {
        $path = $this->zip('release.zip');
        $registered = $this->packageService->register(1, 10, $path, 'site-release.zip');

        self::assertSame('site-release.zip', $registered['name']);
        self::assertSame(2, $registered['metadata']['files']);
        self::assertSame($registered['metadata'], $registered['preview']);

        $result = $this->deployments->deployPackage(1, 10, $registered['id'], 'public_html/app', 'https://status.example.test/health?release=1');
        $deployment = $this->database->one('SELECT * FROM deployments WHERE id = ?', [$result['deployment_id']]);
        $job = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$result['job_id']]);
        $package = $this->database->one('SELECT * FROM deployment_packages WHERE id = ?', [$registered['id']]);

        self::assertSame('queued', $deployment['status']);
        self::assertSame('/home/alice/public_html/app', $deployment['destination']);
        self::assertSame($result['job_id'], (int) $deployment['queue_job_id']);
        self::assertSame($registered['metadata']['sha256'], $deployment['package_checksum']);
        self::assertNotNull($package['consumed_at']);
        self::assertSame('deployment.run', $job['job_type']);
        self::assertSame(1, (int) $job['max_attempts']);
        self::assertStringNotContainsString($path, (string) $job['payload_encrypted']);
        self::assertSame([
            'deployment_id' => $result['deployment_id'],
            'package_id' => $registered['id'],
            'package_path' => $path,
            'package_sha256' => $registered['metadata']['sha256'],
            'package_metadata' => $registered['metadata'],
        ], $this->queue->payload($job));
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM deployment_events WHERE stage = 'queue' AND status = 'queued' AND message_key = 'deployment.queued'")['total']);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'deployment.queue'")['total']);
        $this->assertSafeCode('deployment_package_unavailable', fn () => $this->deployments->deployPackage(1, 10, $registered['id'], 'public_html/again', null));
    }

    public function testQueueFailureRollsBackDeploymentAndPackageConsumption(): void
    {
        $registered = $this->packageService->register(1, 10, $this->zip('queue-failure.zip'), 'release.zip');
        $this->database->execute('DROP TABLE queue_jobs');

        try {
            $this->deployments->deployPackage(1, 10, $registered['id'], 'public_html/app', null);
            self::fail('Deployment intake unexpectedly succeeded without queue storage.');
        } catch (\PDOException) {
            self::assertSame(0, (int) $this->database->one('SELECT COUNT(*) AS total FROM deployments')['total']);
            self::assertNull($this->database->one('SELECT consumed_at FROM deployment_packages WHERE id = ?', [$registered['id']])['consumed_at']);
            self::assertSame(0, (int) $this->database->one('SELECT COUNT(*) AS total FROM deployment_events')['total']);
        }
    }

    public function testTenantPathPackageAndHealthCheckGuardsRejectUnsafeInput(): void
    {
        $registered = $this->packageService->register(1, 10, $this->zip('guards.zip'), '../../bad<script>.zip');
        self::assertSame('release.zip', $registered['name']);

        $this->assertSafeCode('host_not_found', fn () => $this->deployments->deployPackage(2, 10, $registered['id'], 'public_html', null));
        $this->assertSafeCode('deployment_package_unavailable', fn () => $this->deployments->deployPackage(2, 20, $registered['id'], 'public_html', null));
        $this->assertSafeCode('path_traversal_blocked', fn () => $this->deployments->deployPackage(1, 10, $registered['id'], '%2e%2e/etc', null));
        $this->assertSafeCode('deployment_root_blocked', fn () => $this->deployments->deployPackage(1, 10, $registered['id'], '/home/alice', null));
        $this->assertSafeCode('deployment_reserved_path', fn () => $this->deployments->deployPackage(1, 10, $registered['id'], '.tcm-deploy/site', null));
        $this->assertSafeCode('invalid_health_check_url', fn () => $this->deployments->deployPackage(1, 10, $registered['id'], 'public_html', 'https://attacker.example/'));
        $this->assertSafeCode('invalid_health_check_url', fn () => $this->deployments->deployPackage(1, 10, $registered['id'], 'public_html', 'https://user:pass@example.test/'));
        $this->assertSafeCode('invalid_health_check_url', fn () => $this->deployments->deployPackage(1, 10, $registered['id'], 'public_html', 'https://example.test:8443/'));
        self::assertNull($this->database->one('SELECT consumed_at FROM deployment_packages WHERE id = ?', [$registered['id']])['consumed_at']);
    }

    public function testRollbackQueueIsOwnerBoundIdempotentAndSingleAttempt(): void
    {
        $registered = $this->packageService->register(1, 10, $this->zip('rollback.zip'), 'rollback.zip');
        $deployment = $this->deployments->deployPackage(1, 10, $registered['id'], 'public_html/app', null);
        $this->database->execute("UPDATE deployments SET status = 'completed', destination_existed = 0, completed_at = CURRENT_TIMESTAMP WHERE id = ?", [$deployment['deployment_id']]);

        $first = $this->deployments->rollback(1, 10, $deployment['deployment_id']);
        $second = $this->deployments->rollback(1, 10, $deployment['deployment_id']);
        self::assertSame($first, $second);
        self::assertSame(1, (int) $this->database->one('SELECT max_attempts FROM queue_jobs WHERE id = ?', [$first])['max_attempts']);
        self::assertSame($first, (int) $this->database->one('SELECT rollback_job_id FROM deployments WHERE id = ?', [$deployment['deployment_id']])['rollback_job_id']);

        $this->database->execute("UPDATE queue_jobs SET status = 'failed' WHERE id = ?", [$first]);
        $this->database->execute("UPDATE deployments SET status = 'rollback_failed' WHERE id = ?", [$deployment['deployment_id']]);
        $retry = $this->deployments->rollback(1, 10, $deployment['deployment_id']);
        self::assertNotSame($first, $retry);
        self::assertSame(2, (int) $this->database->one("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'deployment.rollback_queue'")['total']);
        $this->assertSafeCode('deployment_not_found', fn () => $this->deployments->rollback(2, 20, $deployment['deployment_id']));
    }

    public function testOverviewClassifiesCurrentVersionsRollbackPointsAndAttention(): void
    {
        $insert = 'INSERT INTO deployments (id, user_id, account_id, package_name, destination, status, destination_existed, backup_ref, rollback_path, reconciliation_json, created_at) VALUES (?, 1, 10, ?, ?, ?, ?, ?, ?, ?, ?)';
        $this->database->execute($insert, [1, 'release-1.zip', '/home/alice/public_html/app', 'completed', 1, '/home/alice/.tcm-backups/1.zip', '/home/alice/.tcm-rollbacks/1', null, '2026-01-01 00:00:00']);
        $this->database->execute($insert, [2, 'release-2.zip', '/home/alice/public_html/app', 'rolled_back', 1, '/home/alice/.tcm-backups/2.zip', null, null, '2026-01-02 00:00:00']);
        $this->database->execute($insert, [3, 'new-site.zip', '/home/alice/public_html/new', 'completed', 0, null, null, null, '2026-01-03 00:00:00']);
        $this->database->execute($insert, [4, 'broken.zip', '/home/alice/public_html/broken', 'rollback_failed', 1, '/home/alice/.tcm-backups/4.zip', null, '{"phase":"rollback"}', '2026-01-04 00:00:00']);
        $this->database->execute($insert, [5, 'running.zip', '/home/alice/public_html/app', 'health_check', 1, '/home/alice/.tcm-backups/5.zip', null, null, '2026-01-05 00:00:00']);
        $this->database->execute($insert, [6, 'ambiguous.zip', '/home/alice/public_html/other', 'reconciliation_required', 1, null, null, '{"phase":"deploy"}', '2026-01-06 00:00:00']);
        $this->database->execute("INSERT INTO deployment_events (deployment_id, stage, status, message_key, metadata_json) VALUES (1, 'complete', 'completed', 'deployment.completed', '{\"entries\":2}')");

        $overview = $this->deployments->overview(1, 10);

        self::assertSame([3, 1], array_column($overview['current_versions'], 'id'));
        self::assertSame([4, 3, 1], array_column($overview['rollback_points'], 'id'));
        self::assertSame([5], array_column($overview['active_deployments'], 'id'));
        self::assertSame([6, 4], array_column($overview['attention_required'], 'id'));
        self::assertSame('remove_destination', $overview['current_versions'][0]['rollback_kind']);
        self::assertSame('directory', $overview['current_versions'][1]['rollback_kind']);
        self::assertFalse($overview['deployments'][4]['rollback_available']);

        $details = $this->deployments->status(1, 10, 1);
        self::assertTrue($details['is_current']);
        self::assertSame(['entries' => 2], $details['events'][0]['metadata']);
        self::assertArrayNotHasKey('metadata_json', $details['events'][0]);
        $this->assertSafeCode('host_not_found', fn () => $this->deployments->overview(2, 10));
    }

    private function zip(string $name): string
    {
        $path = $this->uploadRoot . '/' . $name;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('index.php', '<?php echo "ok";'));
        self::assertTrue($zip->addFromString('assets/app.css', 'body{color:#123}'));
        self::assertTrue($zip->close());
        return $path;
    }

    private function assertSafeCode(string $expected, callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe deployment operation was accepted: ' . $expected);
        } catch (AppException $exception) {
            self::assertSame($expected, $exception->safeCode);
        }
    }
}
