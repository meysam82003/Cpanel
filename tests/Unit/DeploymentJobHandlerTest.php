<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Deployment\DeploymentBackupVerifier;
use App\Deployment\DeploymentJobHandler;
use App\Deployment\DeploymentPackageService;
use App\Deployment\DeploymentRollbackExecutor;
use App\Deployment\DeploymentService;
use App\Deployment\HealthCheckService;
use App\Deployment\ZipPackageValidator;
use App\Queue\JobContext;
use App\Queue\QueueService;
use App\Security\Crypto;
use App\Security\HostValidator;
use App\Security\OperationLockService;
use App\Security\PathGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDeploymentFilesystem;
use Tests\Support\SqliteTestDatabase;
use ZipArchive;

final class DeploymentJobHandlerTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;
    private DeploymentPackageService $packages;
    private DeploymentService $deployments;
    private DeploymentJobHandler $handler;
    private InMemoryDeploymentFilesystem $filesystem;
    private string $tempRoot;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is required for deployment execution tests.');
        }
        $this->tempRoot = sys_get_temp_dir() . '/tcm-deployment-handler-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->tempRoot, 0700, true));
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
CREATE TABLE operation_locks (
 lock_key TEXT PRIMARY KEY, owner_token TEXT NOT NULL, expires_at TEXT NOT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
 backup_ref TEXT NULL, backup_size INTEGER NULL, backup_checksum TEXT NULL,
 rollback_path TEXT NULL, destination_existed INTEGER NOT NULL DEFAULT 0,
 health_check_url TEXT NULL, health_status INTEGER NULL, error_code TEXT NULL,
 reconciliation_json TEXT NULL, notification_id INTEGER NULL,
 rollback_notification_id INTEGER NULL, recovery_attempts INTEGER NOT NULL DEFAULT 0,
 last_recovery_at TEXT NULL, started_at TEXT NULL, completed_at TEXT NULL,
 rolled_back_at TEXT NULL, rollback_verified_at TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE deployment_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, deployment_id INTEGER NOT NULL, stage TEXT NOT NULL,
 status TEXT NOT NULL, message_key TEXT NOT NULL, metadata_json TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE backups (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 type TEXT NOT NULL, target TEXT NOT NULL, remote_path TEXT NULL, size_bytes INTEGER NULL,
 status TEXT NOT NULL, provider_ref TEXT NULL, metadata_json TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, completed_at TEXT NULL
);
CREATE TABLE notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL,
 title_key TEXT NOT NULL, body_key TEXT NOT NULL, parameters_json TEXT NULL,
 sent_at TEXT NULL, read_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $crypto = new Crypto([1 => str_repeat('j', 32)], 1);
        $this->queue = new QueueService($this->database, $crypto);
        $accounts = new AccountRepository($this->database, $crypto);
        $validator = new ZipPackageValidator();
        $audit = new AuditLogger($this->database);
        $paths = new PathGuard();
        $this->packages = new DeploymentPackageService($this->database, $accounts, $validator);
        $this->deployments = new DeploymentService($this->database, $accounts, $paths, $validator, $this->queue, $audit, $this->tempRoot);
        $this->filesystem = new InMemoryDeploymentFilesystem();
        $backupVerifier = new DeploymentBackupVerifier($this->filesystem, $validator, $this->tempRoot);
        $rollback = new DeploymentRollbackExecutor($this->filesystem, $backupVerifier);
        $this->handler = new DeploymentJobHandler(
            $this->database,
            $accounts,
            $this->filesystem,
            $validator,
            $backupVerifier,
            new HealthCheckService(new HostValidator(false, [443], 443)),
            $rollback,
            new OperationLockService($this->database),
            $paths,
            $audit,
            $this->tempRoot,
            $this->tempRoot,
        );
        $this->database->execute("INSERT INTO cpanel_accounts (id, user_id, root_path, cpanel_username, base_url, main_domain) VALUES (10, 1, '/home/alice', 'alice', 'https://example.test:2083', 'example.test')");
        $this->filesystem->seedDirectory('/home/alice/public_html/app');
        $this->filesystem->seedFile('/home/alice/public_html/app/index.php', 'old-release');
    }

    protected function tearDown(): void
    {
        if (!isset($this->tempRoot) || !is_dir($this->tempRoot)) {
            return;
        }
        foreach (glob($this->tempRoot . '/*') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->tempRoot);
    }

    public function testValidatedPackageRunsThroughBackupAtomicActivationAndCompletion(): void
    {
        $registered = $this->packages->register(1, 10, $this->releaseZip(), 'release.zip');
        $queued = $this->deployments->deployPackage(1, 10, $registered['id'], '/home/alice/public_html/app', null);
        $job = $this->queue->claim();
        self::assertNotNull($job);
        self::assertSame($queued['job_id'], (int) $job['id']);

        $result = $this->handler->handle(new JobContext($this->queue, $job), $this->queue->payload($job));
        $this->queue->complete($job, $result);

        $deployment = $this->database->one('SELECT * FROM deployments WHERE id = ?', [$queued['deployment_id']]);
        self::assertSame('completed', $deployment['status']);
        self::assertSame('activated', $deployment['switch_state']);
        self::assertNotNull($deployment['completed_at']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $deployment['backup_checksum']);
        self::assertSame('<?php echo "new";', $this->filesystem->content('/home/alice/public_html/app/index.php'));
        self::assertSame('body{color:#123}', $this->filesystem->content('/home/alice/public_html/app/assets/app.css'));
        self::assertSame('old-release', $this->filesystem->content('/home/alice/.tcm-rollbacks/deployment-' . $queued['deployment_id'] . '-previous/index.php'));
        self::assertFalse($this->filesystem->exists('/home/alice/.tcm-deploy/deployment-' . $queued['deployment_id']));
        self::assertFileDoesNotExist($registered['id'] > 0 ? $this->tempRoot . '/release.zip' : '');
        self::assertSame('completed', $this->database->one('SELECT status FROM queue_jobs WHERE id = ?', [$job['id']])['status']);
        self::assertSame('notification.deployment_done', $this->database->one('SELECT body_key FROM notifications WHERE id = ?', [$deployment['notification_id']])['body_key']);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM backups WHERE type = 'deployment' AND status = 'completed'")['total']);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM deployment_events WHERE message_key = 'deployment.deploy_completed'")['total']);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM deployment_events WHERE message_key = 'deployment.health_skipped'")['total']);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM deployment_events WHERE message_key = 'deployment.completed'")['total']);
        self::assertSame(0, (int) $this->database->one('SELECT COUNT(*) AS total FROM operation_locks')['total']);
    }

    public function testLateHealthCheckRecoveryDoesNotDependOnDeletedLocalPackage(): void
    {
        $packagePath = $this->releaseZip();
        $registered = $this->packages->register(1, 10, $packagePath, 'release.zip');
        $queued = $this->deployments->deployPackage(1, 10, $registered['id'], '/home/alice/public_html/app', null);
        $deployment = $this->database->one('SELECT * FROM deployments WHERE id = ?', [$queued['deployment_id']]);
        $stage = '/home/alice/.tcm-deploy/deployment-' . $queued['deployment_id'] . '/release-' . substr((string) $deployment['package_checksum'], 0, 16);
        $rollback = '/home/alice/.tcm-rollbacks/deployment-' . $queued['deployment_id'] . '-previous';
        $this->filesystem->moveDirectory(1, 10, '/home/alice/public_html/app', $rollback);
        $this->filesystem->seedDirectory('/home/alice/public_html/app');
        $this->filesystem->seedFile('/home/alice/public_html/app/index.php', '<?php echo "new";');
        $this->filesystem->seedDirectory('/home/alice/public_html/app/assets');
        $this->filesystem->seedFile('/home/alice/public_html/app/assets/app.css', 'body{color:#123}');
        $this->database->execute("UPDATE deployments SET status = 'health_check', switch_state = 'activated', stage_path = ?, rollback_path = ?, destination_existed = 1 WHERE id = ?", [$stage, $rollback, $queued['deployment_id']]);
        self::assertTrue(unlink($packagePath));
        $job = $this->queue->claim();
        self::assertNotNull($job);

        $result = $this->handler->handle(new JobContext($this->queue, $job), $this->queue->payload($job));
        $this->queue->complete($job, $result);

        self::assertSame('completed', $this->database->one('SELECT status FROM deployments WHERE id = ?', [$queued['deployment_id']])['status']);
        self::assertSame('<?php echo "new";', $this->filesystem->content('/home/alice/public_html/app/index.php'));
        self::assertSame('old-release', $this->filesystem->content($rollback . '/index.php'));
    }

    public function testActivationFailureAutomaticallyRestoresPreviousRelease(): void
    {
        $registered = $this->packages->register(1, 10, $this->releaseZip(), 'release.zip');
        $queued = $this->deployments->deployPackage(1, 10, $registered['id'], '/home/alice/public_html/app', null);
        $deployment = $this->database->one('SELECT package_checksum FROM deployments WHERE id = ?', [$queued['deployment_id']]);
        $stage = '/home/alice/.tcm-deploy/deployment-' . $queued['deployment_id'] . '/release-' . substr((string) $deployment['package_checksum'], 0, 16);
        $this->filesystem->throwBeforeMoveSource = $stage;
        $job = $this->queue->claim();
        self::assertNotNull($job);

        try {
            $this->handler->handle(new JobContext($this->queue, $job), $this->queue->payload($job));
            self::fail('The simulated activation failure unexpectedly completed.');
        } catch (AppException $exception) {
            self::assertSame('deployment_switch_failed', $exception->safeCode);
        }

        $failed = $this->database->one('SELECT * FROM deployments WHERE id = ?', [$queued['deployment_id']]);
        self::assertSame('rolled_back', $failed['status']);
        self::assertSame('rollback_complete', $failed['switch_state']);
        self::assertNotNull($failed['rollback_verified_at']);
        self::assertSame('old-release', $this->filesystem->content('/home/alice/public_html/app/index.php'));
        self::assertSame('notification.deployment_rolled_back', $this->database->one('SELECT body_key FROM notifications WHERE id = ?', [$failed['notification_id']])['body_key']);
    }

    private function releaseZip(): string
    {
        $path = $this->tempRoot . '/release.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('index.php', '<?php echo "new";'));
        self::assertTrue($zip->addFromString('assets/app.css', 'body{color:#123}'));
        self::assertTrue($zip->close());
        return $path;
    }
}
