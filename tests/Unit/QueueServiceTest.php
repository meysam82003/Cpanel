<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Core\Database;
use App\Queue\QueueService;
use App\Security\Crypto;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class QueueServiceTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;

    protected function setUp(): void
    {
        $this->database = $this->sqlite(<<<'SQL'
CREATE TABLE queue_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL DEFAULT 'default', job_type TEXT NOT NULL,
    user_id INTEGER NULL, account_id INTEGER NULL,
    payload_encrypted TEXT NOT NULL, result_encrypted TEXT NULL,
    status TEXT NOT NULL DEFAULT 'queued', progress INTEGER NOT NULL DEFAULT 0,
    status_message TEXT NULL, attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3, idempotency_key TEXT NULL UNIQUE,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at TEXT NULL, reservation_token TEXT NULL, completed_at TEXT NULL,
    last_error_code TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE queue_failed_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, original_job_id INTEGER NULL,
    queue TEXT NOT NULL, job_type TEXT NOT NULL, user_id INTEGER NULL,
    account_id INTEGER NULL, payload_encrypted TEXT NOT NULL,
    error_code TEXT NOT NULL, error_message_safe TEXT NOT NULL,
    failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $this->queue = new QueueService($this->database, new Crypto([1 => str_repeat('k', 32)], 1), 3600);
    }

    public function testOnlyCurrentLeaseCanReportProgressAndComplete(): void
    {
        $jobId = $this->queue->dispatch('test.job', 7, 9, ['safe' => true], 'lease-test');
        $firstLease = $this->queue->claim();
        self::assertNotNull($firstLease);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $firstLease['reservation_token']);

        $this->database->execute("UPDATE queue_jobs SET reserved_at = '2000-01-01 00:00:00' WHERE id = ?", [$jobId]);
        $secondLease = $this->queue->claim();
        self::assertNotNull($secondLease);
        self::assertNotSame($firstLease['reservation_token'], $secondLease['reservation_token']);
        self::assertSame(2, (int) $secondLease['attempts']);

        try {
            $this->queue->progress($jobId, (string) $firstLease['reservation_token'], 40, 'stale_worker');
            self::fail('A stale worker changed job progress.');
        } catch (AppException $exception) {
            self::assertSame('queue_lease_lost', $exception->safeCode);
        }

        $this->database->execute("UPDATE queue_jobs SET reserved_at = '2000-01-01 00:00:00' WHERE id = ?", [$jobId]);
        $this->queue->progress($jobId, (string) $secondLease['reservation_token'], 60, 'working');
        $heartbeat = $this->database->one('SELECT reserved_at FROM queue_jobs WHERE id = ?', [$jobId]);
        self::assertNotSame('2000-01-01 00:00:00', $heartbeat['reserved_at']);

        $this->queue->complete($secondLease, ['done' => true]);
        $status = $this->queue->status($jobId, 7);
        self::assertSame('completed', $status['status']);
        self::assertSame(['done' => true], $status['result']);
    }

    public function testActiveLeaseCannotBeClaimedTwice(): void
    {
        $this->queue->dispatch('test.job', 7, null, [], 'active-test');
        self::assertNotNull($this->queue->claim());
        self::assertNull($this->queue->claim());
    }

    public function testExpiredFinalAttemptMovesToFailedLedger(): void
    {
        $jobId = $this->queue->dispatch('test.job', 7, null, [], 'expiry-test', 'default', 1);
        self::assertNotNull($this->queue->claim());
        $this->database->execute("UPDATE queue_jobs SET reserved_at = '2000-01-01 00:00:00' WHERE id = ?", [$jobId]);

        self::assertNull($this->queue->claim());
        $job = $this->database->one('SELECT status, last_error_code, reservation_token FROM queue_jobs WHERE id = ?', [$jobId]);
        self::assertSame('failed', $job['status']);
        self::assertSame('queue_lease_expired', $job['last_error_code']);
        self::assertNull($job['reservation_token']);
        self::assertSame(1, (int) $this->database->one('SELECT COUNT(*) AS total FROM queue_failed_jobs WHERE original_job_id = ?', [$jobId])['total']);
    }
}
