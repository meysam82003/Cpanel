<?php

declare(strict_types=1);

namespace App\Queue;

use App\Core\AppException;
use App\Core\Database;
use App\Security\Crypto;

final class QueueService
{
    public function __construct(private readonly Database $database, private readonly Crypto $crypto)
    {
    }

    /** @param array<string,mixed> $payload */
    public function dispatch(string $type, ?int $userId, ?int $accountId, array $payload, ?string $idempotencyKey = null, string $queue = 'default', int $maxAttempts = 3): int
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $type) || !preg_match('/^[a-z][a-z0-9_.-]{0,49}$/', $queue)) {
            throw new AppException('Queue job type or queue is invalid.', 500, 'invalid_queue_job');
        }
        $idempotencyKey = hash('sha256', ($idempotencyKey ?? bin2hex(random_bytes(16))) . '|' . ($userId ?? 0) . '|' . $type);
        $encrypted = $this->crypto->encrypt(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $this->context($type, $userId, $accountId, $idempotencyKey)
        );
        try {
            $this->database->execute(
                'INSERT INTO queue_jobs (queue, job_type, user_id, account_id, payload_encrypted, status, progress, max_attempts, idempotency_key) VALUES (?, ?, ?, ?, ?, \'queued\', 0, ?, ?)',
                [$queue, $type, $userId, $accountId, $encrypted, max(1, min(10, $maxAttempts)), $idempotencyKey]
            );
            return $this->database->lastInsertId();
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing = $this->database->one('SELECT id FROM queue_jobs WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing === null) {
                throw $exception;
            }
            return (int) $existing['id'];
        }
    }

    /** @return array<string,mixed>|null */
    public function claim(string $queue = 'default'): ?array
    {
        return $this->database->transaction(function (Database $db) use ($queue): ?array {
            $db->execute("UPDATE queue_jobs SET status = 'queued', reserved_at = NULL WHERE status = 'running' AND reserved_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 20 MINUTE)");
            $job = $db->one("SELECT * FROM queue_jobs WHERE queue = ? AND status = 'queued' AND available_at <= CURRENT_TIMESTAMP ORDER BY id ASC LIMIT 1 FOR UPDATE", [$queue]);
            if ($job === null) {
                return null;
            }
            $db->execute("UPDATE queue_jobs SET status = 'running', attempts = attempts + 1, reserved_at = CURRENT_TIMESTAMP, status_message = 'running' WHERE id = ? AND status = 'queued'", [$job['id']]);
            return $db->one('SELECT * FROM queue_jobs WHERE id = ?', [$job['id']]);
        });
    }

    /** @param array<string,mixed> $job
     *  @return array<string,mixed>
     */
    public function payload(array $job): array
    {
        $json = $this->crypto->decrypt((string) $job['payload_encrypted'], $this->context((string) $job['job_type'], $job['user_id'] === null ? null : (int) $job['user_id'], $job['account_id'] === null ? null : (int) $job['account_id'], (string) $job['idempotency_key']));
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new AppException('Queue payload is invalid.', 500, 'invalid_queue_payload');
        }
        return $payload;
    }

    public function progress(int $jobId, int $percentage, string $message): void
    {
        $percentage = max(0, min(99, $percentage));
        $message = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $message) ?? '', 0, 191);
        $this->database->execute("UPDATE queue_jobs SET progress = ?, status_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'running'", [$percentage, $message, $jobId]);
    }

    /** @param array<string,mixed> $job
     *  @param array<string,mixed> $result
     */
    public function complete(array $job, array $result): void
    {
        $encrypted = $this->crypto->encrypt(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'queue-result:' . $job['id'] . ':' . $job['job_type']);
        $this->database->execute("UPDATE queue_jobs SET status = 'completed', progress = 100, status_message = 'completed', result_encrypted = ?, completed_at = CURRENT_TIMESTAMP, reserved_at = NULL WHERE id = ?", [$encrypted, $job['id']]);
    }

    /** @param array<string,mixed> $job */
    public function fail(array $job, string $safeCode, string $safeMessage): void
    {
        $safeMessage = mb_substr($safeMessage, 0, 500);
        if ((int) $job['attempts'] < (int) $job['max_attempts']) {
            $delay = min(900, 10 * (2 ** max(0, (int) $job['attempts'] - 1)));
            $this->database->execute("UPDATE queue_jobs SET status = 'queued', progress = 0, status_message = 'retrying', last_error_code = ?, available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND), reserved_at = NULL WHERE id = ?", [$safeCode, $delay, $job['id']]);
            return;
        }
        $this->database->transaction(function (Database $db) use ($job, $safeCode, $safeMessage): void {
            $db->execute('INSERT INTO queue_failed_jobs (original_job_id, queue, job_type, user_id, account_id, payload_encrypted, error_code, error_message_safe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$job['id'], $job['queue'], $job['job_type'], $job['user_id'], $job['account_id'], $job['payload_encrypted'], $safeCode, $safeMessage]);
            $db->execute("UPDATE queue_jobs SET status = 'failed', status_message = 'failed', last_error_code = ?, reserved_at = NULL, completed_at = CURRENT_TIMESTAMP WHERE id = ?", [$safeCode, $job['id']]);
        });
    }

    /** @return array<string,mixed> */
    public function status(int $jobId, int $userId): array
    {
        $row = $this->database->one('SELECT id, queue, job_type, user_id, account_id, status, progress, status_message, attempts, max_attempts, last_error_code, created_at, updated_at, completed_at, result_encrypted FROM queue_jobs WHERE id = ? AND user_id = ?', [$jobId, $userId]);
        if ($row === null) {
            throw new AppException('Queue job was not found or does not belong to you.', 404, 'queue_job_not_found', [], 'security.idor');
        }
        if ($row['result_encrypted'] !== null) {
            try {
                $row['result'] = json_decode($this->crypto->decrypt((string) $row['result_encrypted'], 'queue-result:' . $row['id'] . ':' . $row['job_type']), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $row['result'] = null;
            }
        }
        unset($row['result_encrypted']);
        return $row;
    }

    public function retryFailed(int $failedJobId): int
    {
        return $this->database->transaction(function (Database $db) use ($failedJobId): int {
            $failed = $db->one('SELECT * FROM queue_failed_jobs WHERE id = ? FOR UPDATE', [$failedJobId]);
            if ($failed === null || $failed['original_job_id'] === null) {
                throw new AppException('Failed queue job was not found or cannot be retried.', 404, 'failed_job_not_found');
            }
            $jobId = (int) $failed['original_job_id'];
            $job = $db->one('SELECT id, status FROM queue_jobs WHERE id = ? FOR UPDATE', [$jobId]);
            if ($job === null || (string) $job['status'] !== 'failed') {
                throw new AppException('The failed job is no longer retryable.', 409, 'failed_job_not_retryable');
            }
            $db->execute("UPDATE queue_jobs SET status = 'queued', progress = 0, status_message = 'queued', attempts = 0, available_at = CURRENT_TIMESTAMP, reserved_at = NULL, completed_at = NULL, last_error_code = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$jobId]);
            $db->execute('DELETE FROM queue_failed_jobs WHERE id = ?', [$failedJobId]);
            return $jobId;
        });
    }

    private function context(string $type, ?int $userId, ?int $accountId, string $idempotency): string
    {
        return 'queue-payload:' . $type . ':' . ($userId ?? 0) . ':' . ($accountId ?? 0) . ':' . $idempotency;
    }
}
