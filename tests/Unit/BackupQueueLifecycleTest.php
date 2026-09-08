<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditLogger;
use App\Backup\BackupJobTracker;
use App\Core\AppException;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Translator;
use App\Notifications\NotificationService;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use App\Queue\QueueService;
use App\Queue\QueueWorker;
use App\Security\Crypto;
use App\Telegram\TelegramClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class BackupQueueLifecycleTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;
    private BackupJobTracker $tracker;
    private string $logs;

    protected function setUp(): void
    {
        $this->database = $this->sqlite(<<<'SQL'
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
CREATE TABLE queue_failed_jobs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, original_job_id INTEGER NULL, queue TEXT NOT NULL,
 job_type TEXT NOT NULL, user_id INTEGER NULL, account_id INTEGER NULL,
 payload_encrypted TEXT NOT NULL, error_code TEXT NOT NULL,
 error_message_safe TEXT NOT NULL, failed_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE backups (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 type TEXT NOT NULL, target TEXT NOT NULL, remote_path TEXT NULL, size_bytes INTEGER NULL,
 status TEXT NOT NULL, provider_ref TEXT NULL, metadata_json TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, completed_at TEXT NULL
);
CREATE TABLE file_archive_jobs (
 job_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 notification_id INTEGER NULL
);
CREATE TABLE notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL,
 title_key TEXT NOT NULL, body_key TEXT NOT NULL, parameters_json TEXT NULL
);
CREATE TABLE audit_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, action TEXT,
 target_type TEXT, target_ref TEXT, result TEXT, request_id TEXT, ip_address BLOB,
 metadata_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE recent_actions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, action TEXT,
 resource_type TEXT, resource_ref TEXT, metadata_json TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $this->queue = new QueueService($this->database, new Crypto([1 => str_repeat('q', 32)], 1));
        $notifications = new NotificationService(
            $this->database,
            new Translator(dirname(__DIR__, 2) . '/resources/lang'),
            new TelegramClient('123456:' . str_repeat('b', 30)),
        );
        $this->tracker = new BackupJobTracker($this->database, new AuditLogger($this->database), $notifications);
        $this->logs = sys_get_temp_dir() . '/tcm-backup-queue-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logs . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->logs)) {
            rmdir($this->logs);
        }
    }

    public function testSuccessfulWorkerCompletionFinalizesLinkedBackup(): void
    {
        $jobId = $this->queue->dispatch('test.backup', 7, 9, ['destination' => '/home/alice/site.zip'], null, 'default', 1);
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, remote_path, status, provider_ref) VALUES (7, 9, 'directory', '/home/alice/site', '/home/alice/site.zip', 'queued', ?)", ['job:' . $jobId]);
        $handler = new class implements JobHandler {
            public function handle(JobContext $context, array $payload): array
            {
                $context->progress(60, 'creating');
                return ['operation' => 'create', 'destination' => $payload['destination'], 'bytes' => 4096, 'completed' => true];
            }
        };
        $worker = new QueueWorker($this->queue, ['test.backup' => $handler], new Logger($this->logs), null, $this->tracker);

        self::assertTrue($worker->runOnce());
        self::assertSame('completed', $this->database->one('SELECT status FROM queue_jobs WHERE id = ?', [$jobId])['status']);
        $backup = $this->database->one('SELECT * FROM backups WHERE provider_ref = ?', ['job:' . $jobId]);
        self::assertSame('completed', $backup['status']);
        self::assertSame(4096, (int) $backup['size_bytes']);
        self::assertSame(1, (int) $this->database->one('SELECT COUNT(*) AS total FROM notifications')['total']);
    }

    public function testFinalWorkerFailureFinalizesLinkedBackupWithoutRawException(): void
    {
        $jobId = $this->queue->dispatch('test.backup', 7, 9, [], null, 'default', 1);
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, remote_path, status, provider_ref) VALUES (7, 9, 'directory', '/home/alice/site', '/home/alice/site.zip', 'queued', ?)", ['job:' . $jobId]);
        $handler = new class implements JobHandler {
            public function handle(JobContext $context, array $payload): array
            {
                throw new AppException('Sensitive provider detail', 502, 'backup_test_failure');
            }
        };
        $worker = new QueueWorker($this->queue, ['test.backup' => $handler], new Logger($this->logs), null, $this->tracker);

        self::assertTrue($worker->runOnce());
        $job = $this->database->one('SELECT status, last_error_code FROM queue_jobs WHERE id = ?', [$jobId]);
        self::assertSame('failed', $job['status']);
        self::assertSame('backup_test_failure', $job['last_error_code']);
        $backup = $this->database->one('SELECT status, metadata_json FROM backups WHERE provider_ref = ?', ['job:' . $jobId]);
        self::assertSame('failed', $backup['status']);
        self::assertSame('backup_test_failure', json_decode((string) $backup['metadata_json'], true, 512, JSON_THROW_ON_ERROR)['error_code']);
        self::assertStringNotContainsString('Sensitive provider detail', (string) $backup['metadata_json']);
    }
}
