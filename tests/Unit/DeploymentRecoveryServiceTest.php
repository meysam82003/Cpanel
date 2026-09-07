<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Deployment\DeploymentRecoveryService;
use App\Queue\QueueService;
use App\Security\Crypto;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class DeploymentRecoveryServiceTest extends TestCase
{
    use SqliteTestDatabase;

    private Database $database;
    private QueueService $queue;
    private DeploymentRecoveryService $recovery;
    private string $uploadRoot;
    private string $packagePath;
    private string $checksum;

    protected function setUp(): void
    {
        $this->uploadRoot = sys_get_temp_dir() . '/tcm-recovery-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->uploadRoot, 0700, true));
        $this->packagePath = $this->uploadRoot . '/release.zip';
        file_put_contents($this->packagePath, 'immutable-package');
        $this->checksum = hash_file('sha256', $this->packagePath);
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
CREATE TABLE deployment_packages (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 local_path TEXT NOT NULL, checksum_sha256 TEXT NOT NULL, metadata_json TEXT NOT NULL
);
CREATE TABLE deployments (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
 package_id INTEGER NULL, queue_job_id INTEGER NULL, rollback_job_id INTEGER NULL,
 package_checksum TEXT NULL, package_metadata_json TEXT NULL, status TEXT NOT NULL,
 switch_state TEXT NOT NULL DEFAULT 'none', recovery_attempts INTEGER NOT NULL DEFAULT 0,
 last_recovery_at TEXT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        $crypto = new Crypto([1 => str_repeat('r', 32)], 1);
        $this->queue = new QueueService($this->database, $crypto);
        $this->recovery = new DeploymentRecoveryService($this->database, $this->queue, new AuditLogger($this->database), $this->uploadRoot, 30, 3);
        $metadata = json_encode(['files' => 1, 'compressed_bytes' => 17, 'uncompressed_bytes' => 17, 'top_level' => ['index.php'], 'sha256' => $this->checksum], JSON_THROW_ON_ERROR);
        $this->database->execute('INSERT INTO deployment_packages (id, user_id, account_id, local_path, checksum_sha256, metadata_json) VALUES (1, 1, 10, ?, ?, ?)', [$this->packagePath, $this->checksum, $metadata]);
    }

    protected function tearDown(): void
    {
        if (isset($this->packagePath) && is_file($this->packagePath)) {
            @unlink($this->packagePath);
        }
        if (isset($this->uploadRoot) && is_dir($this->uploadRoot)) {
            @rmdir($this->uploadRoot);
        }
    }

    public function testFailedSingleAttemptJobIsRequeuedWithEncryptedImmutablePayload(): void
    {
        $oldJob = $this->failedJob('deployment.run', 'queue_lease_expired');
        $deploymentId = $this->deployment('backup_completed', $oldJob, null);

        self::assertSame(1, $this->recovery->recover());
        $deployment = $this->database->one('SELECT * FROM deployments WHERE id = ?', [$deploymentId]);
        $job = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$deployment['queue_job_id']]);

        self::assertNotSame($oldJob, (int) $deployment['queue_job_id']);
        self::assertSame(1, (int) $deployment['recovery_attempts']);
        self::assertNotNull($deployment['last_recovery_at']);
        self::assertSame('deployment.run', $job['job_type']);
        self::assertSame(1, (int) $job['max_attempts']);
        self::assertStringNotContainsString($this->packagePath, (string) $job['payload_encrypted']);
        $payload = $this->queue->payload($job);
        self::assertSame($deploymentId, $payload['deployment_id']);
        self::assertSame(1, $payload['recovery_attempt']);
        self::assertSame($this->checksum, $payload['package_sha256']);
        self::assertSame(1, (int) $this->database->one("SELECT COUNT(*) AS total FROM audit_logs WHERE action = 'deployment.recovery_queue'")['total']);
        self::assertSame(0, $this->recovery->recover());
    }

    public function testLatePhaseCanRecoverWhenLocalPackageWasAlreadyCleaned(): void
    {
        $oldJob = $this->failedJob('deployment.run', 'queue_lease_expired');
        $deploymentId = $this->deployment('health_check', $oldJob, null);
        self::assertTrue(unlink($this->packagePath));

        self::assertSame(1, $this->recovery->recover());
        $deployment = $this->database->one('SELECT queue_job_id FROM deployments WHERE id = ?', [$deploymentId]);
        $job = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$deployment['queue_job_id']]);
        self::assertSame('deployment.run', $job['job_type']);
        self::assertSame($this->packagePath, $this->queue->payload($job)['package_path']);
    }

    public function testQueuedLockConflictAndRollingBackStateUseSafeRecoveryJobs(): void
    {
        $lockJob = $this->failedJob('deployment.run', 'operation_locked');
        $queuedId = $this->deployment('queued', $lockJob, null);
        $rollbackJob = $this->failedJob('deployment.rollback', 'queue_lease_expired');
        $rollingId = $this->deployment('rolling_back', $lockJob, $rollbackJob, 2);

        self::assertSame(2, $this->recovery->recover());
        $queued = $this->database->one('SELECT * FROM deployments WHERE id = ?', [$queuedId]);
        $rolling = $this->database->one('SELECT * FROM deployments WHERE id = ?', [$rollingId]);
        $queuedJob = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$queued['queue_job_id']]);
        $newRollbackJob = $this->database->one('SELECT * FROM queue_jobs WHERE id = ?', [$rolling['rollback_job_id']]);

        self::assertSame('deployment.run', $queuedJob['job_type']);
        self::assertSame('deployment.rollback', $newRollbackJob['job_type']);
        self::assertSame(['deployment_id' => $rollingId], $this->queue->payload($newRollbackJob));
        self::assertNotSame($rollbackJob, (int) $rolling['rollback_job_id']);
    }

    private function failedJob(string $type, string $error): int
    {
        $jobId = $this->queue->dispatch($type, 1, 10, ['seed' => bin2hex(random_bytes(4))], null, 'default', 1);
        $this->database->execute("UPDATE queue_jobs SET status = 'failed', last_error_code = ?, completed_at = '2000-01-01 00:00:00', updated_at = '2000-01-01 00:00:00' WHERE id = ?", [$error, $jobId]);
        return $jobId;
    }

    private function deployment(string $status, int $queueJobId, ?int $rollbackJobId, int $id = 1): int
    {
        $metadata = (string) $this->database->one('SELECT metadata_json FROM deployment_packages WHERE id = 1')['metadata_json'];
        $this->database->execute("INSERT INTO deployments (id, user_id, account_id, package_id, queue_job_id, rollback_job_id, package_checksum, package_metadata_json, status, switch_state, recovery_attempts, updated_at) VALUES (?, 1, 10, 1, ?, ?, ?, ?, ?, 'rollback_pending', 0, '2000-01-01 00:00:00')", [$id, $queueJobId, $rollbackJobId, $this->checksum, $metadata, $status]);
        return $id;
    }
}
