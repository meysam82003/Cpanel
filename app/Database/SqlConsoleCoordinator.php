<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Database;

final class SqlConsoleCoordinator
{
    public function __construct(
        private readonly Database $database,
        private readonly DirectDatabaseConnectionService $connections,
        private readonly DatabaseDumpWriter $dumps,
        private readonly SqlConsoleService $console,
        private readonly string $backupRoot,
    ) {
    }

    /** @return array{backup:array<string,mixed>,execution:array<string,mixed>} */
    public function backupAndExecute(int $userId, int $accountId, string $databaseName, string $sql, bool $confirmed): array
    {
        $pdo = $this->connections->pdo($userId, $accountId, $databaseName);
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $databaseName);
        $path = rtrim($this->backupRoot, '/') . '/pre-sql-' . $safe . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
        $stats = $this->dumps->write($pdo, $databaseName, [], 'full', 'gz', $path);
        $this->database->execute(
            "INSERT INTO backups (user_id, account_id, type, target, size_bytes, status, provider_ref, metadata_json, completed_at) VALUES (?, ?, 'database', ?, ?, 'completed', ?, ?, CURRENT_TIMESTAMP)",
            [$userId, $accountId, $databaseName, $stats['bytes'], 'local:' . basename($path), json_encode(['reason' => 'before_destructive_sql', 'sha256' => $stats['sha256'], 'tables' => $stats['tables'], 'rows' => $stats['rows']], JSON_THROW_ON_ERROR)]
        );
        $backup = ['id' => $this->database->lastInsertId(), 'file' => basename($path)] + $stats;
        return ['backup' => $backup, 'execution' => $this->console->execute($userId, $accountId, $databaseName, $sql, $confirmed)];
    }
}
