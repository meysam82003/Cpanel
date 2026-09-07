<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Core\Logger;
use App\Cpanel\UapiClient;
use App\FileManager\DownloadService;
use App\Queue\QueueService;
use App\Security\Crypto;
use App\Security\HostValidator;
use App\Security\PathGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class DownloadServiceTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;
    private DownloadService $downloads;
    private string $directory;

    protected function setUp(): void
    {
        $this->database = $this->sqlite(<<<'SQL'
CREATE TABLE cpanel_accounts (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, root_path TEXT, cpanel_username TEXT, base_url TEXT, encrypted_token TEXT);
CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER, status TEXT);
CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, action TEXT, target_type TEXT, target_ref TEXT, result TEXT, request_id TEXT, ip_address BLOB, metadata_json TEXT);
CREATE TABLE recent_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, action TEXT, resource_type TEXT, resource_ref TEXT, metadata_json TEXT);
CREATE TABLE download_tokens (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 token_hash TEXT UNIQUE NOT NULL, remote_path TEXT NOT NULL, content_type TEXT NULL, filename TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'queued', job_id INTEGER NULL, preparation_token TEXT NULL,
 prepared_path TEXT NULL, prepared_size INTEGER NULL, prepared_sha256 TEXT NULL, prepared_content_type TEXT NULL,
 prepared_at TEXT NULL, last_error_code TEXT NULL, max_uses INTEGER NOT NULL DEFAULT 1,
 uses INTEGER NOT NULL DEFAULT 0, expires_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE queue_jobs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL, job_type TEXT NOT NULL, user_id INTEGER NULL,
 account_id INTEGER NULL, payload_encrypted TEXT NOT NULL, result_encrypted TEXT NULL, status TEXT NOT NULL,
 progress INTEGER NOT NULL, status_message TEXT NULL, attempts INTEGER NOT NULL DEFAULT 0,
 max_attempts INTEGER NOT NULL, idempotency_key TEXT UNIQUE, available_at TEXT DEFAULT CURRENT_TIMESTAMP,
 reserved_at TEXT NULL, reservation_token TEXT NULL, completed_at TEXT NULL, last_error_code TEXT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $crypto = new Crypto([1 => str_repeat('d', 32)], 1);
        $this->queue = new QueueService($this->database, $crypto);
        $accounts = new AccountRepository($this->database, $crypto);
        $this->directory = sys_get_temp_dir() . '/tcm-downloads-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->downloads = new DownloadService(
            $this->database,
            $accounts,
            new PathGuard(),
            new UapiClient(new HostValidator(), new Logger($this->directory . '/logs')),
            new AuditLogger($this->database),
            $this->queue,
            $this->directory,
        );
        $this->database->execute("INSERT INTO cpanel_accounts (id, user_id, root_path, cpanel_username, base_url) VALUES (10, 1, '/home/alice', 'alice', 'https://example.com:2083')");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    public function testIssueCreatesOwnerBoundPreparationJob(): void
    {
        $issued = $this->downloads->issue(1, 10, 'public_html/site.zip');
        self::assertSame('queued', $issued['status']);
        self::assertGreaterThan(0, $issued['job_id']);
        $job = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$issued['job_id']]);
        self::assertSame(1, (int) $job['user_id']);
        self::assertSame(10, (int) $job['account_id']);
        self::assertSame('file.download', $job['job_type']);
        $payload = $this->queue->payload($job);
        self::assertGreaterThan(0, (int) $payload['download_token_id']);

        $this->expectException(AppException::class);
        $this->downloads->issue(2, 10, 'public_html/site.zip');
    }

    public function testPreparedFileIsIntegrityCheckedOwnerBoundAndSingleUse(): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $path = $this->directory . '/ready-file';
        file_put_contents($path, 'verified-content');
        $this->database->execute(
            "INSERT INTO download_tokens (user_id, account_id, token_hash, remote_path, filename, status, prepared_path, prepared_size, prepared_sha256, prepared_content_type, prepared_at, expires_at) VALUES (1, 10, ?, '/home/alice/file.txt', 'file.txt', 'ready', ?, ?, ?, 'text/plain', CURRENT_TIMESTAMP, '2999-01-01 00:00:00')",
            [hash('sha256', $token), $path, filesize($path), hash_file('sha256', $path)]
        );

        try {
            $this->downloads->materialize($token, 2);
            self::fail('Another user consumed the prepared file.');
        } catch (AppException $exception) {
            self::assertSame('download_not_found', $exception->safeCode);
        }

        $file = $this->downloads->materialize($token, 1);
        self::assertSame('verified-content', file_get_contents($file['path']));
        self::assertTrue($file['cleanup']);
        try {
            $this->downloads->materialize($token, 1);
            self::fail('A one-time link was reused.');
        } catch (AppException $exception) {
            self::assertSame('download_not_found', $exception->safeCode);
        }
    }
}
