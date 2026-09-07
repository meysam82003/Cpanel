<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\FileManager\ArchiveService;
use App\Plans\PlanGuard;
use App\Queue\QueueService;
use App\Security\Crypto;
use App\Security\PathGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class ArchiveServiceTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;
    private ArchiveService $archives;

    protected function setUp(): void
    {
        $this->database = $this->sqlite(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER NOT NULL, status TEXT NOT NULL, host_limit INTEGER NULL);
CREATE TABLE plans (
 id INTEGER PRIMARY KEY, slug TEXT, name_fa TEXT, name_en TEXT, host_limit INTEGER,
 max_upload_bytes INTEGER, database_manager INTEGER, sql_console INTEGER,
 backup_enabled INTEGER, deployment_enabled INTEGER, daily_operation_limit INTEGER,
 settings_json TEXT
);
CREATE TABLE user_plans (id INTEGER PRIMARY KEY, user_id INTEGER, plan_id INTEGER, status TEXT, ends_at TEXT);
CREATE TABLE cpanel_accounts (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, root_path TEXT NULL,
 cpanel_username TEXT NOT NULL, base_url TEXT, encrypted_token TEXT
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
CREATE TABLE notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL,
 title_key TEXT NOT NULL, body_key TEXT NOT NULL, parameters_json TEXT,
 read_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
CREATE TABLE file_archive_jobs (
 job_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 operation TEXT NOT NULL, sources_json TEXT NULL, archive_path TEXT NOT NULL,
 destination TEXT NOT NULL, format TEXT NULL, collision_policy TEXT NOT NULL,
 status TEXT NOT NULL, validation_json TEXT NULL, reconciliation_json TEXT NULL,
 reconciled INTEGER NOT NULL DEFAULT 0, notification_id INTEGER NULL,
 last_error_code TEXT NULL, started_at TEXT NULL, completed_at TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $crypto = new Crypto([1 => str_repeat('a', 32)], 1);
        $this->queue = new QueueService($this->database, $crypto);
        $accounts = new AccountRepository($this->database, $crypto);
        $this->archives = new ArchiveService($this->database, $accounts, new PlanGuard($this->database), new PathGuard(), $this->queue, new AuditLogger($this->database));
        $this->database->execute("INSERT INTO plans VALUES (1, 'pro', 'حرفه‌ای', 'Pro', 10, 500000000, 1, 1, 1, 1, 1000, NULL)");
        $this->database->execute("INSERT INTO users VALUES (1, 10001, 'active', NULL), (2, 20002, 'active', NULL)");
        $this->database->execute("INSERT INTO user_plans VALUES (1, 1, 1, 'active', NULL), (2, 2, 1, 'active', NULL)");
        $this->database->execute("INSERT INTO cpanel_accounts VALUES (10, 1, '/home/alice', 'alice', 'https://example.test:2083', NULL)");
    }

    public function testCreateQueueIsEncryptedOwnerBoundNormalizedAndIdempotent(): void
    {
        $first = $this->archives->enqueueCreate(1, 10, ['public_html/app', '/home/alice/public_html/assets'], 'public_html/releases/site.tar.gz', 'tar.gz');
        $second = $this->archives->enqueueCreate(1, 10, ['public_html/app', '/home/alice/public_html/assets'], 'public_html/releases/site.tar.gz', 'tar.gz');

        self::assertSame($first['job_id'], $second['job_id']);
        $job = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$first['job_id']]);
        self::assertSame('file.archive_create', $job['job_type']);
        self::assertSame(1, (int) $job['max_attempts']);
        self::assertStringNotContainsString('site.tar.gz', (string) $job['payload_encrypted']);
        self::assertSame([
            'sources' => ['/home/alice/public_html/app', '/home/alice/public_html/assets'],
            'destination' => '/home/alice/public_html/releases/site.tar.gz',
            'format' => 'tar.gz',
        ], $this->queue->payload($job));
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'file.archive_queue'")['total']);
        self::assertSame('queued', $this->archives->state((int) $first['job_id'], 1, 10, 'create')['status']);
    }

    public function testExtractQueueNormalizesPathsAndPersistsCollisionPolicy(): void
    {
        $result = $this->archives->enqueueExtract(1, 10, 'public_html/releases/site.tgz', 'public_html/current', 'overwrite');
        $job = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$result['job_id']]);
        self::assertSame([
            'archive' => '/home/alice/public_html/releases/site.tgz',
            'destination' => '/home/alice/public_html/current',
            'collision' => 'overwrite',
            'format' => 'tar.gz',
        ], $this->queue->payload($job));
        $state = $this->archives->state((int) $result['job_id'], 1, 10, 'extract');
        self::assertSame('overwrite', $state['collision_policy']);
        self::assertSame('tar.gz', $state['format']);
    }

    public function testArchiveRequestsRejectCrossTenantAndUnsafePaths(): void
    {
        $this->assertSafeCode('host_not_found', fn () => $this->archives->enqueueExtract(2, 10, 'site.zip', '.', 'reject'));
        $this->assertSafeCode('path_traversal_blocked', fn () => $this->archives->enqueueExtract(1, 10, '../../../etc/site.zip', '.', 'reject'));
        $this->assertSafeCode('invalid_archive_path', fn () => $this->archives->enqueueCreate(1, 10, ['public_html/a,b'], 'public_html/site.zip', 'zip'));
        $this->assertSafeCode('archive_destination_inside_source', fn () => $this->archives->enqueueCreate(1, 10, ['public_html'], 'public_html/site.zip', 'zip'));
        $this->assertSafeCode('archive_single_source_required', fn () => $this->archives->enqueueCreate(1, 10, ['a.txt', 'b.txt'], 'bundle.gz', 'gz'));
        $this->assertSafeCode('archive_extension_mismatch', fn () => $this->archives->enqueueCreate(1, 10, ['a.txt'], 'bundle.zip', 'tar'));
        $this->assertSafeCode('invalid_collision_policy', fn () => $this->archives->enqueueExtract(1, 10, 'site.zip', '.', 'rename'));
    }

    public function testCompletionAndFailureAreIdempotentAndReconciliationUpdatesNotice(): void
    {
        $create = $this->archives->enqueueCreate(1, 10, ['public_html/index.php'], 'backups/site.zip', 'zip');
        $this->archives->transition((int) $create['job_id'], 1, 10, 'create', 'executing');
        $this->archives->complete((int) $create['job_id'], 1, 10, 'create', false);
        $this->archives->complete((int) $create['job_id'], 1, 10, 'create', false);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'file.archive_create' AND result = 'success'")['total']);

        $extract = $this->archives->enqueueExtract(1, 10, 'backups/site.zip', 'public_html', 'reject');
        $this->archives->fail((int) $extract['job_id'], 1, 10, 'extract', 'archive_reconciliation_required', true);
        $this->archives->fail((int) $extract['job_id'], 1, 10, 'extract', 'archive_reconciliation_required', true);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'file.archive_extract' AND result = 'failed'")['total']);
        $this->archives->complete((int) $extract['job_id'], 1, 10, 'extract', true, ['reason' => 'verified']);
        $state = $this->archives->state((int) $extract['job_id'], 1, 10, 'extract');
        $notice = $this->database->one('SELECT * FROM notifications WHERE id = ?', [$state['notification_id']]);
        self::assertSame('notification.archive_extract_done', $notice['body_key']);
        self::assertSame(2, (int) $this->database->one('SELECT COUNT(*) AS total FROM notifications')['total']);
        self::assertSame(1, (int) $state['reconciled']);
    }

    private function assertSafeCode(string $expected, callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe archive request was accepted: ' . $expected);
        } catch (AppException $exception) {
            self::assertSame($expected, $exception->safeCode);
        }
    }
}
