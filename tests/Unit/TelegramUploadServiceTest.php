<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounts\AccountRepository;
use App\Core\AppException;
use App\Core\Database;
use App\FileManager\TelegramUploadService;
use App\Plans\PlanGuard;
use App\Queue\QueueService;
use App\Security\Crypto;
use App\Security\PathGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class TelegramUploadServiceTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;
    private TelegramUploadService $uploads;

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
CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
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
CREATE TABLE telegram_file_uploads (
 job_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 telegram_chat_id INTEGER NOT NULL, remote_directory TEXT NOT NULL,
 requested_filename TEXT NOT NULL, target_filename TEXT NULL,
 collision_policy TEXT NOT NULL, status TEXT NOT NULL, reported_size INTEGER NULL,
 actual_size INTEGER NULL, checksum_sha256 TEXT NULL, notification_id INTEGER NULL,
 last_error_code TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP, completed_at TEXT NULL
);
SQL);
        $crypto = new Crypto([1 => str_repeat('u', 32)], 1);
        $this->queue = new QueueService($this->database, $crypto);
        $accounts = new AccountRepository($this->database, $crypto);
        $plans = new PlanGuard($this->database);
        $this->uploads = new TelegramUploadService($this->database, $accounts, $plans, new PathGuard(), $this->queue, 20_000_000);
        $this->database->execute("INSERT INTO plans VALUES (1, 'pro', 'حرفه‌ای', 'Pro', 10, 30000000, 1, 1, 1, 1, 100, NULL)");
        $this->database->execute("INSERT INTO users VALUES (1, 10001, 'active', NULL), (2, 20002, 'active', NULL)");
        $this->database->execute("INSERT INTO user_plans VALUES (1, 1, 1, 'active', NULL), (2, 2, 1, 'active', NULL)");
        $this->database->execute("INSERT INTO cpanel_accounts VALUES (10, 1, '/home/alice', 'alice', 'https://example.test:2083', NULL)");
    }

    public function testUploadIsEncryptedOwnerBoundNormalizedAndIdempotent(): void
    {
        $fileId = 'BQACAgQAAxkBAAIBd2SecureOpaqueFileId_123456';
        $document = ['file_id' => $fileId, 'file_unique_id' => 'AQADUniqueFile_123456', 'file_name' => 'report.sql', 'file_size' => 1_048_576];
        $first = $this->uploads->enqueue(1, 10, 10001, 77, $document, 'public_html/uploads', 'rename', 'fa');
        $second = $this->uploads->enqueue(1, 10, 10001, 77, $document, 'public_html/uploads', 'rename', 'fa');

        self::assertSame($first['job_id'], $second['job_id']);
        self::assertSame(20_000_000, $first['max_bytes']);
        self::assertSame(1, (int) $this->database->one('SELECT COUNT(*) AS total FROM queue_jobs')['total']);
        $job = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$first['job_id']]);
        self::assertSame('telegram.file_upload', $job['job_type']);
        self::assertStringNotContainsString($fileId, (string) $job['payload_encrypted']);
        $payload = $this->queue->payload($job);
        self::assertSame('/home/alice/public_html/uploads', $payload['directory']);
        self::assertSame('report.sql', $payload['filename']);
        $state = $this->database->one('SELECT * FROM telegram_file_uploads WHERE job_id = ?', [$first['job_id']]);
        self::assertSame(1, (int) $state['user_id']);
        self::assertSame(10, (int) $state['account_id']);
        self::assertSame('queued', $state['status']);
    }

    public function testUploadRejectsCrossTenantHostAndNonPrivateChat(): void
    {
        $document = ['file_id' => 'BQACAgQAAxkBAAIBd2SecureOpaqueFileId_999999', 'file_name' => 'safe.txt', 'file_size' => 10];
        try {
            $this->uploads->enqueue(2, 10, 20002, 1, $document, '.', 'rename', 'en');
            self::fail('A user queued an upload for another tenant host.');
        } catch (AppException $exception) {
            self::assertSame('host_not_found', $exception->safeCode);
        }

        try {
            $this->uploads->enqueue(1, 10, -100123, 2, $document, '.', 'rename', 'en');
            self::fail('A group chat queued an owner-only upload.');
        } catch (AppException $exception) {
            self::assertSame('telegram_upload_forbidden', $exception->safeCode);
        }
    }

    public function testOfficialTelegramDownloadLimitIsEnforcedBeforeQueueing(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Bot API or plan limit');
        $this->uploads->enqueue(1, 10, 10001, 3, [
            'file_id' => 'BQACAgQAAxkBAAIBd2SecureOpaqueFileId_888888',
            'file_name' => 'oversized.zip',
            'file_size' => 20_000_001,
        ], '.', 'overwrite', 'en');
    }

    public function testFilenameAndDestinationTraversalAreRejected(): void
    {
        $document = ['file_id' => 'BQACAgQAAxkBAAIBd2SecureOpaqueFileId_777777', 'file_name' => '../escape.php', 'file_size' => 10];
        try {
            $this->uploads->enqueue(1, 10, 10001, 4, $document, '.', 'rename', 'en');
            self::fail('A filename containing traversal segments was accepted.');
        } catch (AppException $exception) {
            self::assertSame('invalid_filename', $exception->safeCode);
        }

        $document['file_name'] = 'safe.php';
        try {
            $this->uploads->enqueue(1, 10, 10001, 5, $document, '../../../etc', 'rename', 'en');
            self::fail('A destination outside the account root was accepted.');
        } catch (AppException $exception) {
            self::assertContains($exception->safeCode, ['path_outside_root', 'path_traversal_blocked']);
        }
    }
}
