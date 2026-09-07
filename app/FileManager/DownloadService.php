<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Cpanel\UapiClient;
use App\Queue\QueueService;
use App\Security\PathGuard;
use Throwable;

final class DownloadService
{
    private readonly int $maxBytes;

    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly PathGuard $paths,
        private readonly UapiClient $cpanel,
        private readonly AuditLogger $audit,
        private readonly QueueService $queue,
        private readonly string $downloadDirectory,
        int $maxBytes = 104_857_600,
    ) {
        $this->maxBytes = max(1_048_576, min(1_073_741_824, $maxBytes));
    }

    /** @return array{token:string,expires_at:string,filename:string,job_id:int,status:string} */
    public function issue(int $userId, int $accountId, string $remotePath, int $ttlSeconds = 900, ?int $telegramChatId = null, string $language = 'en'): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $safePath = $this->paths->normalize($remotePath, $root);
        $filename = $this->paths->sanitizeFilename(basename($safePath));
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $expires = gmdate('Y-m-d H:i:s', time() + max(300, min(3600, $ttlSeconds)));
        if ($telegramChatId !== null && $telegramChatId === 0) {
            throw new AppException('Telegram delivery chat is invalid.', 422, 'telegram_delivery_invalid');
        }
        $language = $language === 'fa' ? 'fa' : 'en';
        $jobId = $this->database->transaction(function (Database $database) use ($userId, $accountId, $token, $tokenHash, $safePath, $filename, $expires, $telegramChatId, $language): int {
            $database->execute(
                "INSERT INTO download_tokens (user_id, account_id, token_hash, remote_path, filename, status, expires_at) VALUES (?, ?, ?, ?, ?, 'queued', ?)",
                [$userId, $accountId, $tokenHash, $safePath, $filename, $expires]
            );
            $downloadId = $database->lastInsertId();
            $payload = ['download_token_id' => $downloadId];
            if ($telegramChatId !== null) {
                $payload['telegram_delivery'] = ['chat_id' => $telegramChatId, 'language' => $language, 'download_token' => $token];
            }
            $jobId = $this->queue->dispatch('file.download', $userId, $accountId, $payload, 'file.download:' . $tokenHash, 'default', 3);
            $database->execute('UPDATE download_tokens SET job_id = ? WHERE id = ?', [$jobId, $downloadId]);
            return $jobId;
        });
        return ['token' => $token, 'expires_at' => $expires, 'filename' => $filename, 'job_id' => $jobId, 'status' => 'queued'];
    }

    /** @return array{path:string,filename:string,content_type:string,bytes:int,sha256:string} */
    public function prepare(int $userId, int $accountId, int $downloadId, int $jobId, string $leaseToken): array
    {
        if ($downloadId < 1 || $jobId < 1 || !preg_match('/^[a-f0-9]{64}$/', $leaseToken)) {
            throw new AppException('Download preparation state is invalid.', 422, 'download_preparation_invalid', [], 'files.download');
        }
        $row = $this->database->transaction(function (Database $database) use ($userId, $accountId, $downloadId, $jobId, $leaseToken): array {
            $download = $database->one('SELECT * FROM download_tokens WHERE id = ? FOR UPDATE', [$downloadId]);
            if ($download === null || (int) $download['user_id'] !== $userId || (int) $download['account_id'] !== $accountId || (int) ($download['job_id'] ?? 0) !== $jobId) {
                throw new AppException('Download preparation does not belong to this queue job.', 404, 'download_not_found', [], 'security.idor');
            }
            if (strtotime((string) $download['expires_at']) < time() || (int) $download['uses'] >= (int) $download['max_uses']) {
                throw new AppException('The secure download expired before it was prepared.', 410, 'download_expired', [], 'files.download');
            }
            if ((string) $download['status'] === 'ready') {
                return $download;
            }
            $database->execute("UPDATE download_tokens SET status = 'preparing', preparation_token = ?, last_error_code = NULL WHERE id = ?", [$leaseToken, $downloadId]);
            $download['status'] = 'preparing';
            $download['preparation_token'] = $leaseToken;
            return $download;
        });

        if ((string) $row['status'] === 'ready') {
            return $this->preparedFile($row);
        }

        $directory = $this->ensureDirectory();
        $local = $directory . '/download-' . $downloadId . '-' . bin2hex(random_bytes(12));
        try {
            $metadata = $this->cpanel->downloadTo(
                $this->accounts->connection($userId, $accountId),
                (string) $row['remote_path'],
                $local,
                $this->maxBytes,
            );
            @chmod($local, 0600);
            $contentType = $this->safeContentType($metadata['content_type'], $local);
            $updated = $this->database->execute(
                "UPDATE download_tokens SET status = 'ready', prepared_path = ?, prepared_size = ?, prepared_sha256 = ?, prepared_content_type = ?, prepared_at = CURRENT_TIMESTAMP, last_error_code = NULL WHERE id = ? AND user_id = ? AND account_id = ? AND preparation_token = ?",
                [$local, $metadata['bytes'], $metadata['sha256'], mb_substr((string) $contentType, 0, 191), $downloadId, $userId, $accountId, $leaseToken]
            );
            if ($updated->rowCount() !== 1) {
                throw new AppException('The download preparation lease was superseded.', 409, 'queue_lease_lost');
            }
            $this->audit->record($userId, $accountId, 'file.download_prepared', 'success', 'file', (string) $row['remote_path'], ['bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256'], 'job_id' => $jobId]);
            return ['path' => $local, 'filename' => (string) $row['filename'], 'content_type' => (string) $contentType, 'bytes' => (int) $metadata['bytes'], 'sha256' => (string) $metadata['sha256']];
        } catch (Throwable $exception) {
            if (is_file($local)) {
                @unlink($local);
            }
            $safeCode = $exception instanceof AppException ? $exception->safeCode : 'download_preparation_failed';
            $marked = $this->database->execute("UPDATE download_tokens SET status = 'failed', last_error_code = ?, prepared_path = NULL, prepared_size = NULL, prepared_sha256 = NULL, prepared_content_type = NULL, prepared_at = NULL WHERE id = ? AND preparation_token = ?", [$safeCode, $downloadId, $leaseToken]);
            if ($marked->rowCount() === 1) {
                try {
                    $this->audit->record($userId, $accountId, 'file.download_prepared', 'failed', 'file', (string) $row['remote_path'], ['error_code' => $safeCode, 'job_id' => $jobId]);
                } catch (Throwable) {
                }
            }
            throw $exception;
        }
    }

    /** @return array{path:string,filename:string,content_type:string,bytes:int,sha256:string,cleanup:bool} */
    public function materialize(string $token, ?int $authenticatedUserId = null): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            throw new AppException('Download link is invalid.', 404, 'download_not_found', [], 'files.download');
        }
        return $this->database->transaction(function (Database $db) use ($token, $authenticatedUserId): array {
            $download = $db->one('SELECT * FROM download_tokens WHERE token_hash = ? FOR UPDATE', [hash('sha256', $token)]);
            if ($download === null || strtotime((string) $download['expires_at']) < time() || (int) $download['uses'] >= (int) $download['max_uses'] || ($authenticatedUserId !== null && (int) $download['user_id'] !== $authenticatedUserId)) {
                throw new AppException('Download link is invalid, expired, already used, or belongs to another user.', 404, 'download_not_found', [], 'files.download');
            }
            if ((string) ($download['status'] ?? '') === 'failed') {
                throw new AppException('The remote file could not be prepared. Review the job error and retry.', 424, 'download_preparation_failed', [], 'files.download');
            }
            if ((string) ($download['status'] ?? '') !== 'ready') {
                throw new AppException('The secure file is still being prepared. Wait for the queue job to complete.', 409, 'download_not_ready', [], 'files.download');
            }
            $file = $this->preparedFile($download);
            $db->execute('UPDATE download_tokens SET uses = uses + 1, status = CASE WHEN uses + 1 >= max_uses THEN \'consumed\' ELSE status END WHERE id = ? AND uses = ?', [$download['id'], $download['uses']]);
            $this->audit->record((int) $download['user_id'], (int) $download['account_id'], 'file.download', 'success', 'file', (string) $download['remote_path'], ['bytes' => $file['bytes'], 'sha256' => $file['sha256'], 'prepared' => true]);
            return $file + ['cleanup' => (int) $download['uses'] + 1 >= (int) $download['max_uses']];
        });
    }

    public function completeTelegramDelivery(int $userId, int $accountId, int $downloadId): void
    {
        $file = $this->database->transaction(function (Database $database) use ($userId, $accountId, $downloadId): array {
            $download = $database->one("SELECT * FROM download_tokens WHERE id = ? AND user_id = ? AND account_id = ? AND status = 'ready' FOR UPDATE", [$downloadId, $userId, $accountId]);
            if ($download === null) {
                throw new AppException('The prepared Telegram download is unavailable.', 409, 'download_file_unavailable');
            }
            $file = $this->preparedFile($download);
            $database->execute("UPDATE download_tokens SET uses = max_uses, status = 'consumed' WHERE id = ? AND user_id = ? AND account_id = ?", [$downloadId, $userId, $accountId]);
            return $file;
        });
        if (is_file($file['path'])) {
            @unlink($file['path']);
        }
        $this->audit->record($userId, $accountId, 'file.download_telegram', 'success', 'file', $file['filename'], ['bytes' => $file['bytes'], 'sha256' => $file['sha256']]);
    }

    private function ensureDirectory(): string
    {
        $directory = rtrim($this->downloadDirectory, '/');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new AppException('Secure download storage is unavailable.', 500, 'download_storage_unavailable');
        }
        @chmod($directory, 0700);
        return $directory;
    }

    /** @param array<string,mixed> $row
     *  @return array{path:string,filename:string,content_type:string,bytes:int,sha256:string}
     */
    private function preparedFile(array $row): array
    {
        $directory = realpath($this->ensureDirectory());
        $path = is_string($row['prepared_path'] ?? null) ? realpath((string) $row['prepared_path']) : false;
        if ($directory === false || $path === false || !is_file($path) || !is_readable($path) || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            throw new AppException('The prepared download file is unavailable or outside secure storage.', 410, 'download_file_unavailable', [], 'files.download');
        }
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if ($bytes === false || $sha256 === false || (int) ($row['prepared_size'] ?? -1) !== $bytes || !is_string($row['prepared_sha256'] ?? null) || !hash_equals((string) $row['prepared_sha256'], $sha256)) {
            throw new AppException('The prepared download failed its integrity check.', 409, 'download_integrity_failed', [], 'files.download');
        }
        $contentType = $this->safeContentType($row['prepared_content_type'] ?? null, $path);
        return ['path' => $path, 'filename' => (string) $row['filename'], 'content_type' => $contentType, 'bytes' => $bytes, 'sha256' => $sha256];
    }

    private function safeContentType(mixed $candidate, string $path): string
    {
        $contentType = strtolower(trim(explode(';', is_string($candidate) ? $candidate : '')[0]));
        if (!preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#', $contentType)) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $contentType = is_string($detected) && preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#', strtolower($detected)) ? strtolower($detected) : 'application/octet-stream';
        }
        return mb_substr($contentType, 0, 191);
    }
}
