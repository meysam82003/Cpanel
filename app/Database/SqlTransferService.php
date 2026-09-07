<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\AppException;
use App\Queue\QueueService;

final class SqlTransferService
{
    public function __construct(private readonly QueueService $queue, private readonly string $tempDirectory)
    {
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
        return $this->queue->dispatch('sql.import', $userId, $accountId, ['database' => $database, 'path' => $real, 'backup_first' => $backupFirst], hash_file('sha256', $real) . '|' . $database);
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
}

