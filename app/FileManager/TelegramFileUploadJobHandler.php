<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Plans\PlanGuard;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use App\Telegram\TelegramClient;
use Throwable;

final class TelegramFileUploadJobHandler implements JobHandler
{
    public function __construct(
        private readonly Database $database,
        private readonly TelegramUploadService $uploads,
        private readonly FileManagerService $files,
        private readonly PlanGuard $plans,
        private readonly TelegramClient $telegram,
        private readonly AuditLogger $audit,
        private readonly string $tempRoot,
    ) {
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId() ?? throw new AppException('Telegram upload job has no owner.', 500, 'queue_owner_missing');
        $accountId = $context->accountId() ?? throw new AppException('Telegram upload job has no host.', 500, 'queue_account_missing');
        $jobId = (int) $context->job['id'];
        $validated = $this->uploads->validateQueued($userId, $accountId, $payload);
        $state = $this->state($jobId, $userId, $accountId);

        if ((string) $state['status'] === 'failed') {
            // A deliberate retry of a failed queue job keeps the original failure
            // notification but permits one new completion notification.
            $this->database->execute("UPDATE telegram_file_uploads SET status = 'retrying', notification_id = NULL, completed_at = NULL WHERE job_id = ? AND user_id = ? AND account_id = ?", [$jobId, $userId, $accountId]);
            $state = $this->state($jobId, $userId, $accountId);
        }

        if (in_array((string) $state['status'], ['uploaded', 'completed'], true)) {
            if ((string) $state['status'] === 'uploaded') {
                $this->finish($state, true, null);
                $state = $this->state($jobId, $userId, $accountId);
            }
            $context->progress(99, 'upload_completed');
            return $this->result($state);
        }

        $temporary = null;
        try {
            $context->progress(5, 'upload_validating');
            $temporary = $this->temporaryPath($jobId);
            $this->database->execute("UPDATE telegram_file_uploads SET status = 'downloading', last_error_code = NULL WHERE job_id = ? AND user_id = ? AND account_id = ?", [$jobId, $userId, $accountId]);
            $context->progress(15, 'upload_downloading_from_telegram');
            $download = $this->telegram->downloadFile($validated['file_id'], $temporary, $validated['max_bytes']);
            clearstatcache(true, $temporary);
            $actualSize = is_file($temporary) ? filesize($temporary) : false;
            if ($actualSize === false || (int) $actualSize !== (int) $download['bytes'] || (int) $actualSize > $validated['max_bytes']) {
                throw new AppException('The temporary Telegram download failed its size verification.', 422, 'telegram_upload_integrity_failed', [], 'files.upload');
            }
            $this->plans->upload($userId, (int) $actualSize);
            $checksum = hash_file('sha256', $temporary);
            if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
                throw new AppException('The Telegram document checksum could not be calculated.', 500, 'telegram_upload_integrity_failed');
            }

            $target = is_string($state['target_filename'] ?? null) && $state['target_filename'] !== ''
                ? (string) $state['target_filename']
                : $this->files->resolveUploadName($userId, $accountId, $validated['directory'], $validated['filename'], $validated['collision']);
            $this->database->execute(
                "UPDATE telegram_file_uploads SET target_filename = COALESCE(target_filename, ?), status = 'uploading', actual_size = ?, checksum_sha256 = ?, last_error_code = NULL WHERE job_id = ? AND user_id = ? AND account_id = ?",
                [$target, (int) $actualSize, $checksum, $jobId, $userId, $accountId]
            );
            $fresh = $this->state($jobId, $userId, $accountId);
            $target = (string) $fresh['target_filename'];
            $context->progress(60, 'upload_sending_to_cpanel');
            $this->files->upload(
                $userId,
                $accountId,
                $validated['directory'],
                [['path' => $temporary, 'name' => $target]],
                'overwrite',
                ['source' => 'telegram', 'job_id' => $jobId, 'bytes' => (int) $actualSize, 'sha256' => $checksum]
            );
            $context->progress(90, 'upload_verifying');
            $this->database->execute(
                "UPDATE telegram_file_uploads SET status = 'uploaded', actual_size = ?, checksum_sha256 = ?, last_error_code = NULL WHERE job_id = ? AND user_id = ? AND account_id = ?",
                [(int) $actualSize, $checksum, $jobId, $userId, $accountId]
            );
            $this->finish($this->state($jobId, $userId, $accountId), true, null);
            $context->progress(99, 'upload_completed');
            return $this->result($this->state($jobId, $userId, $accountId));
        } catch (Throwable $exception) {
            $safeCode = $exception instanceof AppException ? $exception->safeCode : 'telegram_upload_failed';
            $finalAttempt = (int) $context->job['attempts'] >= (int) $context->job['max_attempts'];
            $this->database->execute(
                "UPDATE telegram_file_uploads SET status = ?, last_error_code = ? WHERE job_id = ? AND user_id = ? AND account_id = ? AND status <> 'completed'",
                [$finalAttempt ? 'failed' : 'retrying', $safeCode, $jobId, $userId, $accountId]
            );
            if ($finalAttempt) {
                try {
                    $this->finish($this->state($jobId, $userId, $accountId), false, $safeCode);
                    $this->audit->record($userId, $accountId, 'file.telegram_upload', 'failed', 'file', $validated['directory'] . '/' . $validated['filename'], ['job_id' => $jobId, 'error_code' => $safeCode]);
                } catch (Throwable) {
                }
            }
            throw $exception;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string,mixed> */
    private function state(int $jobId, int $userId, int $accountId): array
    {
        $state = $this->database->one('SELECT * FROM telegram_file_uploads WHERE job_id = ? AND user_id = ? AND account_id = ?', [$jobId, $userId, $accountId]);
        if ($state === null) {
            throw new AppException('Telegram upload state is missing or not owned by this job.', 404, 'telegram_upload_not_found', [], 'security.idor');
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function finish(array $state, bool $success, ?string $errorCode): void
    {
        $this->database->transaction(function (Database $database) use ($state, $success, $errorCode): void {
            $locked = $database->one('SELECT * FROM telegram_file_uploads WHERE job_id = ? AND user_id = ? AND account_id = ? FOR UPDATE', [$state['job_id'], $state['user_id'], $state['account_id']]);
            if ($locked === null) {
                throw new AppException('Telegram upload completion state was not found.', 404, 'telegram_upload_not_found');
            }
            if ($locked['notification_id'] === null) {
                $database->execute(
                    'INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, ?, ?, ?, ?)',
                    [
                        $locked['user_id'],
                        'file_upload',
                        'notification.file_title',
                        $success ? 'notification.file_upload_done' : 'notification.file_upload_failed',
                        json_encode(['name' => (string) ($locked['target_filename'] ?: $locked['requested_filename']), 'job' => (int) $locked['job_id'], 'code' => $errorCode ?? ''], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]
                );
                $notificationId = $database->lastInsertId();
                $database->execute('UPDATE telegram_file_uploads SET notification_id = ? WHERE job_id = ?', [$notificationId, $locked['job_id']]);
            }
            $database->execute(
                'UPDATE telegram_file_uploads SET status = ?, completed_at = CURRENT_TIMESTAMP, last_error_code = ? WHERE job_id = ?',
                [$success ? 'completed' : 'failed', $errorCode, $locked['job_id']]
            );
        });
    }

    /** @param array<string,mixed> $state
     *  @return array<string,mixed>
     */
    private function result(array $state): array
    {
        return [
            'job_id' => (int) $state['job_id'],
            'directory' => (string) $state['remote_directory'],
            'filename' => (string) ($state['target_filename'] ?: $state['requested_filename']),
            'bytes' => $state['actual_size'] === null ? null : (int) $state['actual_size'],
            'sha256' => $state['checksum_sha256'],
            'uploaded' => (string) $state['status'] === 'completed',
        ];
    }

    private function temporaryPath(int $jobId): string
    {
        $directory = rtrim($this->tempRoot, '/');
        if (is_link($directory)) {
            throw new AppException('Secure Telegram upload storage must not be a symbolic link.', 500, 'upload_storage_unavailable');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new AppException('Secure Telegram upload storage is unavailable.', 500, 'upload_storage_unavailable');
        }
        @chmod($directory, 0700);
        $real = realpath($directory);
        if ($real === false || !is_writable($real)) {
            throw new AppException('Secure Telegram upload storage is unavailable.', 500, 'upload_storage_unavailable');
        }
        return $real . '/telegram-upload-' . $jobId . '-' . bin2hex(random_bytes(16));
    }
}
