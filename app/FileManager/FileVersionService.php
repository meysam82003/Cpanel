<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Core\Database;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;

final class FileVersionService
{
    public function __construct(private readonly Database $database, private readonly UapiClient $cpanel)
    {
    }

    /** @param array{base_url:string,username:string,token:string} $connection */
    public function backupBeforeWrite(int $userId, int $accountId, array $connection, string $remotePath, string $root, string $reason): ?array
    {
        try {
            $info = $this->cpanel->call($connection, 'Fileman', 'get_file_information', ['path' => $remotePath, 'include_mime' => 1]);
            $metadata = $this->first($info['data']);
            if ((int) ($metadata['exists'] ?? 0) !== 1 || ($metadata['type'] ?? 'file') === 'dir') {
                return null;
            }
            $row = $this->database->one('SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version FROM file_versions WHERE account_id = ? AND remote_path = ?', [$accountId, $remotePath]);
            $version = (int) ($row['next_version'] ?? 1);
            $relative = ltrim(substr($remotePath, strlen(rtrim($root, '/'))), '/');
            $backupPath = rtrim($root, '/') . '/.tcpm-versions/' . hash('sha256', $relative) . '/v' . $version . '-' . basename($remotePath);
            $this->ensureBackupDirectory($connection, dirname($backupPath), $root);
            try {
                $this->cpanel->call($connection, 'Fileman', 'copy_file', ['source' => $remotePath, 'destination' => $backupPath], 'GET', [], false);
            } catch (CpanelApiException $exception) {
                if (!$this->cpanel->isOperationUnavailable($exception)) {
                    throw $exception;
                }
                $this->cpanel->callLegacyApi2($connection, 'Fileman', 'fileop', [
                    'op' => 'copy',
                    'sourcefiles' => ltrim($remotePath, '/'),
                    'destfiles' => ltrim($backupPath, '/'),
                    'doubledecode' => 0,
                ], false);
            }
            $this->database->execute(
                'INSERT INTO file_versions (user_id, account_id, remote_path, version_number, backup_path, size_bytes) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $accountId, $remotePath, $version, $backupPath, isset($metadata['size']) ? (int) $metadata['size'] : null]
            );
            $this->database->execute(
                'INSERT INTO file_backups (user_id, account_id, remote_path, backup_path, size_bytes, reason, status) VALUES (?, ?, ?, ?, ?, ?, \'completed\')',
                [$userId, $accountId, $remotePath, $backupPath, isset($metadata['size']) ? (int) $metadata['size'] : null, $reason]
            );
            return ['version' => $version, 'backup_path' => $backupPath];
        } catch (\App\Cpanel\CpanelApiException $exception) {
            if ($exception->safeCode === 'cpanel_operation_failed') {
                throw $exception;
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(int $userId, int $accountId, string $remotePath): array
    {
        return $this->database->all(
            'SELECT id, version_number, backup_path, checksum_sha256, size_bytes, created_at FROM file_versions WHERE user_id = ? AND account_id = ? AND remote_path = ? ORDER BY version_number DESC',
            [$userId, $accountId, $remotePath]
        );
    }

    /** @param array{base_url:string,username:string,token:string} $connection */
    public function restore(int $userId, int $accountId, array $connection, int $versionId, string $remotePath): void
    {
        $version = $this->database->one('SELECT * FROM file_versions WHERE id = ? AND user_id = ? AND account_id = ? AND remote_path = ?', [$versionId, $userId, $accountId, $remotePath]);
        if ($version === null) {
            throw new \App\Core\AppException('The requested file version was not found.', 404, 'file_version_not_found');
        }
        $backupPath = (string) $version['backup_path'];
        $contentResult = $this->cpanel->call($connection, 'Fileman', 'get_file_content', [
            'dir' => dirname($backupPath),
            'file' => basename($backupPath),
            'from_charset' => '_DETECT_',
            'to_charset' => 'UTF-8',
        ]);
        $contentData = $this->first($contentResult['data']);
        if (!array_key_exists('content', $contentData) || !is_string($contentData['content'])) {
            throw new \App\Core\AppException('The selected file version could not be read from cPanel.', 502, 'file_version_content_unavailable', [], 'files.versions');
        }
        $this->cpanel->call($connection, 'Fileman', 'save_file_content', [
            'dir' => dirname($remotePath),
            'file' => basename($remotePath),
            'content' => $contentData['content'],
            'from_charset' => 'UTF-8',
            'to_charset' => 'UTF-8',
        ], 'POST', [], false);
    }

    /** @param array{base_url:string,username:string,token:string} $connection */
    private function ensureBackupDirectory(array $connection, string $directory, string $root): void
    {
        $relative = trim(substr($directory, strlen(rtrim($root, '/'))), '/');
        $current = rtrim($root, '/');
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            try {
                $this->cpanel->callLegacyApi2($connection, 'Fileman', 'mkdir', ['path' => $current, 'name' => $segment, 'permissions' => '0700'], false);
            } catch (\App\Cpanel\CpanelApiException $exception) {
                $check = $this->cpanel->call($connection, 'Fileman', 'get_file_information', ['path' => $current . '/' . $segment]);
                $metadata = $this->first($check['data']);
                if ((int) ($metadata['exists'] ?? 0) !== 1) {
                    throw $exception;
                }
            }
            $current .= '/' . $segment;
        }
    }

    /** @return array<string,mixed> */
    private function first(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        return array_is_list($data) ? (is_array($data[0] ?? null) ? $data[0] : []) : $data;
    }
}
