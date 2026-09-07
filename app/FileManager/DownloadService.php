<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Cpanel\UapiClient;
use App\Security\PathGuard;

final class DownloadService
{
    public function __construct(
        private readonly Database $database,
        private readonly AccountRepository $accounts,
        private readonly PathGuard $paths,
        private readonly UapiClient $cpanel,
        private readonly AuditLogger $audit,
        private readonly string $downloadDirectory,
        private readonly int $maxBytes = 104_857_600,
    ) {
    }

    /** @return array{token:string,expires_at:string,filename:string} */
    public function issue(int $userId, int $accountId, string $remotePath, int $ttlSeconds = 300): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $safePath = $this->paths->normalize($remotePath, $root);
        $filename = $this->paths->sanitizeFilename(basename($safePath));
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = gmdate('Y-m-d H:i:s', time() + max(60, min(1800, $ttlSeconds)));
        $this->database->execute(
            'INSERT INTO download_tokens (user_id, account_id, token_hash, remote_path, filename, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $accountId, hash('sha256', $token), $safePath, $filename, $expires]
        );
        return ['token' => $token, 'expires_at' => $expires, 'filename' => $filename];
    }

    /** @return array{path:string,filename:string,content_type:string,bytes:int,sha256:string,cleanup:bool} */
    public function materialize(string $token, ?int $authenticatedUserId = null): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            throw new AppException('Download link is invalid.', 404, 'download_not_found', [], 'files.download');
        }
        $row = $this->database->transaction(function (Database $db) use ($token, $authenticatedUserId): array {
            $download = $db->one('SELECT * FROM download_tokens WHERE token_hash = ? FOR UPDATE', [hash('sha256', $token)]);
            if ($download === null || strtotime((string) $download['expires_at']) < time() || (int) $download['uses'] >= (int) $download['max_uses'] || ($authenticatedUserId !== null && (int) $download['user_id'] !== $authenticatedUserId)) {
                throw new AppException('Download link is invalid, expired, already used, or belongs to another user.', 404, 'download_not_found', [], 'files.download');
            }
            $db->execute('UPDATE download_tokens SET uses = uses + 1 WHERE id = ?', [$download['id']]);
            return $download;
        });
        $directory = rtrim($this->downloadDirectory, '/');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new AppException('Secure download storage is unavailable.', 500, 'download_storage_unavailable');
        }
        $local = $directory . '/download-' . bin2hex(random_bytes(16));
        try {
            $metadata = $this->cpanel->downloadTo(
                $this->accounts->connection((int) $row['user_id'], (int) $row['account_id']),
                (string) $row['remote_path'],
                $local,
                $this->maxBytes,
            );
            $contentType = $metadata['content_type'] ?: (mime_content_type($local) ?: 'application/octet-stream');
            $this->audit->record((int) $row['user_id'], (int) $row['account_id'], 'file.download', 'success', 'file', (string) $row['remote_path'], ['bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256']]);
            return ['path' => $local, 'filename' => (string) $row['filename'], 'content_type' => $contentType, 'bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256'], 'cleanup' => true];
        } catch (\Throwable $exception) {
            @unlink($local);
            $this->audit->record((int) $row['user_id'], (int) $row['account_id'], 'file.download', 'failed', 'file', (string) $row['remote_path'], ['error_code' => $exception instanceof AppException ? $exception->safeCode : 'unexpected_error']);
            throw $exception;
        }
    }
}
