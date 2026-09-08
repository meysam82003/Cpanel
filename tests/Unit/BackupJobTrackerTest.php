<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditLogger;
use App\Backup\BackupJobTracker;
use App\Core\Database;
use App\Core\Translator;
use App\Notifications\NotificationService;
use App\Telegram\TelegramClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class BackupJobTrackerTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private BackupJobTracker $tracker;

    protected function setUp(): void
    {
        $this->database = $this->sqlite(<<<'SQL'
CREATE TABLE queue_jobs (
 id INTEGER PRIMARY KEY, user_id INTEGER, account_id INTEGER, status TEXT NOT NULL,
 progress INTEGER NOT NULL DEFAULT 0, last_error_code TEXT NULL
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
 resource_type TEXT, resource_ref TEXT, metadata_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $notifications = new NotificationService(
            $this->database,
            new Translator(dirname(__DIR__, 2) . '/resources/lang'),
            new TelegramClient('123456:' . str_repeat('a', 30)),
        );
        $this->tracker = new BackupJobTracker($this->database, new AuditLogger($this->database), $notifications);
    }

    public function testCompletionPersistsArtifactMetadataOnce(): void
    {
        $this->database->execute("INSERT INTO queue_jobs VALUES (41, 1, 10, 'completed', 100, NULL)");
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, remote_path, status, provider_ref, metadata_json) VALUES (1, 10, 'directory', '/home/alice/site', '/home/alice/.tcm-backups/site.zip', 'queued', 'job:41', '{}')");

        self::assertSame(1, $this->tracker->completed(41, ['destination' => '/home/alice/.tcm-backups/site.zip', 'bytes' => 2048, 'operation' => 'create']));
        self::assertSame(0, $this->tracker->completed(41, ['bytes' => 2048]));

        $backup = $this->database->one('SELECT * FROM backups WHERE id = 1');
        self::assertSame('completed', $backup['status']);
        self::assertSame(2048, (int) $backup['size_bytes']);
        self::assertSame(41, json_decode((string) $backup['metadata_json'], true, 512, JSON_THROW_ON_ERROR)['job_id']);
        self::assertSame(1, (int) $this->database->one('SELECT COUNT(*) AS total FROM notifications')['total']);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'backup.job_completed'")['total']);
    }

    public function testDatabaseCompletionPromotesVerifiedLocalReference(): void
    {
        $this->database->execute("INSERT INTO queue_jobs VALUES (42, 1, 10, 'completed', 100, NULL)");
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, status, provider_ref) VALUES (1, 10, 'database', 'alice_app', 'processing', 'job:42')");

        $this->tracker->completed(42, ['database' => 'alice_app', 'file' => 'alice_app.sql.gz', 'bytes' => 99]);

        $backup = $this->database->one('SELECT * FROM backups WHERE id = 1');
        self::assertSame('local:alice_app.sql.gz', $backup['provider_ref']);
        self::assertSame(99, (int) $backup['size_bytes']);
    }

    public function testExistingArchiveNotificationIsNotDuplicated(): void
    {
        $this->database->execute("INSERT INTO queue_jobs VALUES (45, 1, 10, 'completed', 100, NULL)");
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, status, provider_ref) VALUES (1, 10, 'directory', '/home/alice/site', 'queued', 'job:45')");
        $this->database->execute('INSERT INTO file_archive_jobs (job_id, user_id, account_id, notification_id) VALUES (45, 1, 10, 777)');

        self::assertSame(1, $this->tracker->completed(45, ['destination' => '/home/alice/site.zip', 'bytes' => 123]));
        self::assertSame(0, (int) $this->database->one('SELECT COUNT(*) AS total FROM notifications')['total']);
    }

    public function testFailureOnlyFinalizesAfterQueueExhaustion(): void
    {
        $this->database->execute("INSERT INTO queue_jobs VALUES (43, 1, 10, 'queued', 0, 'temporary_error')");
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, status, provider_ref) VALUES (1, 10, 'database', 'alice_app', 'queued', 'job:43')");
        self::assertSame(0, $this->tracker->failed(43, 'temporary_error'));
        self::assertSame('queued', $this->database->one('SELECT status FROM backups WHERE id = 1')['status']);

        $this->database->execute("UPDATE queue_jobs SET status = 'failed', last_error_code = 'mysql_unavailable' WHERE id = 43");
        $summary = $this->tracker->reconcile(1, 10);
        self::assertSame(1, $summary['failed']);
        $backup = $this->database->one('SELECT * FROM backups WHERE id = 1');
        self::assertSame('failed', $backup['status']);
        self::assertSame('mysql_unavailable', json_decode((string) $backup['metadata_json'], true, 512, JSON_THROW_ON_ERROR)['error_code']);
    }

    public function testReconciliationIsOwnerBound(): void
    {
        $this->database->execute("INSERT INTO queue_jobs VALUES (44, 2, 20, 'completed', 100, NULL)");
        $this->database->execute("INSERT INTO backups (user_id, account_id, type, target, status, provider_ref) VALUES (1, 10, 'directory', '/home/alice/site', 'queued', 'job:44')");

        $summary = $this->tracker->reconcile(1, 10);
        self::assertSame(0, $summary['completed']);
        self::assertSame(0, $this->tracker->completed(44, ['bytes' => 123]));
        self::assertSame('queued', $this->database->one('SELECT status FROM backups WHERE id = 1')['status']);
    }
}
