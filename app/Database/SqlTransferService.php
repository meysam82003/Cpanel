<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\AppException;
use App\Queue\QueueService;

final class SqlTransferService
{
    /** @param list<string> $backupRoots */
    public function __construct(
        private readonly QueueService $queue,
        private readonly string $tempDirectory,
        private readonly array $backupRoots = [],
    ) {
    }

    public function import(int $userId, int $accountId, string $database, string $uploadedPath, bool $backupFirst = true): int
    {
        $real = realpath($uploadedPath);
        $root = realpath($this->tempDirectory);
        if ($real === false || $root === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new AppException('SQL import file is outside the secure temporary directory.', 422, 'invalid_import_file', [], 'database.import');
        }
        if (!preg_match('/\.(?:sql|sql\.gz|zip)$/i', basename($real))) {
            throw new AppException('Only .sql, .sql.gz, or ZIP containing SQL can be imported.', 415, 'unsupported_import_format', [], 'database.import');
        }
        return $this->queue->dispatch('sql.import', $userId, $accountId, ['database' => $database, 'path' => $real, 'backup_first' => $backupFirst], hash_file('sha256', $real) . '|' . $database . '|' . basename($real));
    }

    public function importBackup(int $userId, int $accountId, string $database, string $storedPath, ?string $expectedSha256 = null): int
    {
        $source = $this->storedBackupPath($storedPath);
        if ($expectedSha256 !== null && !preg_match('/^[a-f0-9]{64}$/', $expectedSha256)) {
            throw new AppException('The stored backup checksum is invalid.', 409, 'backup_integrity_invalid', [], 'backup.overview');
        }
        $suffix = $this->importSuffix($source);
        if (!is_dir($this->tempDirectory) && !mkdir($this->tempDirectory, 0700, true) && !is_dir($this->tempDirectory)) {
            throw new AppException('Secure restore staging is unavailable.', 500, 'backup_restore_storage_unavailable', [], 'backup.overview');
        }
        $temporary = rtrim($this->tempDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backup-restore-' . bin2hex(random_bytes(16)) . $suffix;
        $input = fopen($source, 'rb');
        $output = fopen($temporary, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($temporary);
            throw new AppException('The stored backup cannot be staged for restore.', 410, 'backup_file_unavailable', [], 'backup.overview');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new AppException('The stored backup could not be read completely.', 410, 'backup_file_unavailable', [], 'backup.overview');
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > 1_073_741_824) {
                    throw new AppException('The compressed backup exceeds the 1 GiB restore staging limit.', 413, 'backup_restore_too_large', [], 'backup.overview');
                }
                hash_update($hash, $chunk);
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new AppException('The stored backup could not be staged completely.', 500, 'backup_restore_storage_unavailable', [], 'backup.overview');
                }
            }
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($temporary);
            throw $exception;
        }
        fclose($input);
        fclose($output);
        @chmod($temporary, 0600);
        $actualSha256 = hash_final($hash);
        if ($bytes < 1 || ($expectedSha256 !== null && !hash_equals($expectedSha256, $actualSha256))) {
            @unlink($temporary);
            throw new AppException('The stored backup failed its integrity check.', 409, 'backup_integrity_failed', [], 'backup.overview');
        }
        try {
            return $this->import($userId, $accountId, $database, $temporary, true);
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    /** @param list<string> $tables */
    public function export(int $userId, int $accountId, string $database, array $tables = [], string $mode = 'full', string $compression = 'gz'): int
    {
        if (!in_array($mode, ['full', 'structure', 'data'], true) || !in_array($compression, ['none', 'gz'], true)) {
            throw new AppException('SQL export mode or compression is invalid.', 422, 'invalid_export_options', [], 'database.export');
        }
        if (count($tables) > 500) {
            throw new AppException('Too many selected tables.', 422, 'too_many_export_tables');
        }
        return $this->queue->dispatch('sql.export', $userId, $accountId, ['database' => $database, 'tables' => array_values($tables), 'mode' => $mode, 'compression' => $compression]);
    }

    private function storedBackupPath(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new AppException('The stored backup file is unavailable.', 410, 'backup_file_unavailable', [], 'backup.overview');
        }
        foreach ($this->backupRoots as $root) {
            $realRoot = realpath($root);
            if ($realRoot !== false && str_starts_with($real, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        throw new AppException('The stored backup is outside managed storage.', 403, 'backup_storage_invalid', [], 'security.path');
    }

    private function importSuffix(string $path): string
    {
        $lower = strtolower(basename($path));
        foreach (['.sql.gz', '.sql', '.zip'] as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return $suffix;
            }
        }
        throw new AppException('The stored backup format cannot be restored as SQL.', 415, 'unsupported_import_format', [], 'database.import');
    }
}
