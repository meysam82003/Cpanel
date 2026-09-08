<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Core\Database;
use App\Database\SqlTransferService;
use App\Queue\QueueService;
use App\Security\Crypto;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class SqlTransferServiceTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;
    private SqlTransferService $transfers;
    private string $root;
    private string $backupRoot;
    private string $tempRoot;

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
SQL);
        $this->queue = new QueueService($this->database, new Crypto([1 => str_repeat('s', 32)], 1));
        $this->root = sys_get_temp_dir() . '/tcm-sql-restore-' . bin2hex(random_bytes(8));
        $this->backupRoot = $this->root . '/backups';
        $this->tempRoot = $this->root . '/temp';
        self::assertTrue(mkdir($this->backupRoot, 0700, true));
        self::assertTrue(mkdir($this->tempRoot, 0700, true));
        $this->transfers = new SqlTransferService($this->queue, $this->tempRoot, [$this->backupRoot]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->tempRoot, $this->backupRoot] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testStoredBackupIsCopiedVerifiedAndQueuedWithPreRestoreBackup(): void
    {
        $source = $this->backupRoot . '/app.sql.gz';
        file_put_contents($source, gzencode("CREATE TABLE example (id INT);\n"));
        $checksum = hash_file('sha256', $source);

        $first = $this->transfers->importBackup(1, 10, 'alice_app', $source, $checksum);
        $second = $this->transfers->importBackup(1, 10, 'alice_app', $source, $checksum);

        self::assertNotSame($first, $second, 'A deliberate repeat restore must create a new operation.');
        $job = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$first]);
        $payload = $this->queue->payload($job);
        self::assertSame('alice_app', $payload['database']);
        self::assertTrue($payload['backup_first']);
        self::assertStringStartsWith($this->tempRoot . DIRECTORY_SEPARATOR, $payload['path']);
        self::assertStringEndsWith('.sql.gz', $payload['path']);
        self::assertSame($checksum, hash_file('sha256', $payload['path']));
        self::assertStringNotContainsString('CREATE TABLE', (string) $job['payload_encrypted']);
    }

    public function testStoredBackupRejectsOutsideStorageAndChecksumMismatch(): void
    {
        $outside = $this->root . '/outside.sql';
        file_put_contents($outside, 'SELECT 1;');
        try {
            $this->transfers->importBackup(1, 10, 'alice_app', $outside, hash_file('sha256', $outside));
            self::fail('An unmanaged backup path was accepted.');
        } catch (AppException $exception) {
            self::assertSame('backup_storage_invalid', $exception->safeCode);
        }

        $source = $this->backupRoot . '/tampered.sql';
        file_put_contents($source, 'SELECT 2;');
        try {
            $this->transfers->importBackup(1, 10, 'alice_app', $source, str_repeat('a', 64));
            self::fail('A backup with the wrong checksum was queued.');
        } catch (AppException $exception) {
            self::assertSame('backup_integrity_failed', $exception->safeCode);
        }
        unlink($outside);
        self::assertSame([], glob($this->tempRoot . '/*') ?: []);
    }
}
