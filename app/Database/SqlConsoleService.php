<?php

declare(strict_types=1);

namespace App\Database;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Security\Crypto;
use PDO;

final class SqlConsoleService
{
    public function __construct(
        private readonly Database $database,
        private readonly DirectDatabaseConnectionService $connections,
        private readonly SqlSafetyAnalyzer $analyzer,
        private readonly Crypto $crypto,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array{analysis:array<string,mixed>,columns:list<string>,rows:list<array<string,mixed>>,affected_rows:int,execution_ms:int,truncated:bool} */
    public function execute(int $userId, int $accountId, string $databaseName, string $sql, bool $destructiveConfirmed = false, bool $saveHistory = true): array
    {
        $analysis = $this->analyzer->analyze($sql);
        if ($analysis['requires_confirmation'] && !$destructiveConfirmed) {
            throw new AppException('This destructive query requires a fresh one-time confirmation.', 409, 'sql_confirmation_required', ['analysis' => $analysis, 'query_hash' => hash('sha256', $sql)], 'database.sql');
        }
        $pdo = $this->connections->pdo($userId, $accountId, $databaseName);
        $this->setTimeout($pdo, 15);
        $started = microtime(true);
        $resultCode = 'success';
        $affected = 0;
        $columns = [];
        $rows = [];
        $truncated = false;
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute();
            $affected = $statement->rowCount();
            if ($statement->columnCount() > 0) {
                for ($index = 0; $index < $statement->columnCount(); $index++) {
                    $meta = $statement->getColumnMeta($index);
                    $columns[] = (string) ($meta['name'] ?? $index);
                }
                $bytes = 0;
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    $bytes += strlen((string) $encoded);
                    if (count($rows) >= 500 || $bytes > 2_097_152) {
                        $truncated = true;
                        break;
                    }
                    $rows[] = $row;
                }
            }
        } catch (\Throwable $exception) {
            $resultCode = 'failed';
            throw new AppException('The SQL server rejected the query. Check syntax, privileges, and selected database.', 422, 'sql_execution_failed', ['driver_code' => $exception->getCode()], 'database.sql');
        } finally {
            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->storeHistory($userId, $accountId, $databaseName, $sql, $analysis, $affected, $duration, $resultCode, $saveHistory);
            $this->audit->record($userId, $accountId, 'sql.execute', $resultCode, 'database', $databaseName, ['query_type' => $analysis['type'], 'query_hash' => hash('sha256', $sql), 'affected_rows' => $affected, 'duration_ms' => $duration, 'truncated' => $truncated]);
        }
        return ['analysis' => $analysis, 'columns' => $columns, 'rows' => $rows, 'affected_rows' => $affected, 'execution_ms' => $duration, 'truncated' => $truncated];
    }

    /** @return array<string,mixed> */
    public function explain(int $userId, int $accountId, string $databaseName, string $sql): array
    {
        $analysis = $this->analyzer->analyze($sql);
        if (!$analysis['read_only']) {
            throw new AppException('EXPLAIN is available only for read-only queries in this interface.', 422, 'explain_not_read_only');
        }
        return $this->execute($userId, $accountId, $databaseName, 'EXPLAIN ' . $sql, false, false);
    }

    /** @return list<array<string,mixed>> */
    public function history(int $userId, int $accountId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->database->all('SELECT id, account_id, database_name, query_encrypted, query_hash, query_type, affected_rows, duration_ms, result, created_at FROM sql_history WHERE user_id = ? AND account_id = ? ORDER BY id DESC LIMIT ' . $limit, [$userId, $accountId]);
        foreach ($rows as &$row) {
            if (is_string($row['query_encrypted']) && $row['query_encrypted'] !== '') {
                try {
                    $row['query'] = $this->crypto->decrypt($row['query_encrypted'], 'sql-history:' . $row['id'] . ':' . $userId);
                } catch (AppException) {
                    $row['query'] = null;
                }
            }
            unset($row['query_encrypted']);
        }
        unset($row);
        return $rows;
    }

    public function saveQuery(int $userId, int $accountId, string $databaseName, string $name, string $sql): int
    {
        $name = trim(mb_substr($name, 0, 191));
        if ($name === '') {
            throw new AppException('Saved query name is required.', 422, 'saved_query_name_required');
        }
        $this->analyzer->analyze($sql);
        $encrypted = $this->crypto->encrypt($sql, 'saved-query:' . $userId . ':' . $accountId . ':' . $name);
        $this->database->execute(
            'INSERT INTO saved_queries (user_id, account_id, database_name, name, query_encrypted) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE database_name = VALUES(database_name), query_encrypted = VALUES(query_encrypted), updated_at = CURRENT_TIMESTAMP',
            [$userId, $accountId, $databaseName, $name, $encrypted]
        );
        return (int) ($this->database->one('SELECT id FROM saved_queries WHERE user_id = ? AND account_id = ? AND name = ?', [$userId, $accountId, $name])['id'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function savedQueries(int $userId, int $accountId): array
    {
        $rows = $this->database->all('SELECT id, database_name, name, query_encrypted, created_at, updated_at FROM saved_queries WHERE user_id = ? AND account_id = ? ORDER BY updated_at DESC LIMIT 100', [$userId, $accountId]);
        foreach ($rows as &$row) {
            try {
                $row['query'] = $this->crypto->decrypt((string) $row['query_encrypted'], 'saved-query:' . $userId . ':' . $accountId . ':' . $row['name']);
            } catch (AppException) {
                $row['query'] = null;
            }
            unset($row['query_encrypted']);
        }
        unset($row);
        return $rows;
    }

    public function deleteSavedQuery(int $userId, int $accountId, int $queryId): void
    {
        $statement = $this->database->execute('DELETE FROM saved_queries WHERE id = ? AND user_id = ? AND account_id = ?', [$queryId, $userId, $accountId]);
        if ($statement->rowCount() !== 1) {
            throw new AppException('Saved query was not found or does not belong to you.', 404, 'saved_query_not_found', [], 'security.idor');
        }
    }

    /** @param array<string,mixed> $analysis */
    private function storeHistory(int $userId, int $accountId, string $databaseName, string $sql, array $analysis, int $affected, int $duration, string $result, bool $save): void
    {
        $this->database->execute('INSERT INTO sql_history (user_id, account_id, database_name, query_encrypted, query_hash, query_type, affected_rows, duration_ms, result) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?)', [$userId, $accountId, $databaseName, hash('sha256', $sql), $analysis['type'], $affected, $duration, $result]);
        if ($save) {
            $id = $this->database->lastInsertId();
            $encrypted = $this->crypto->encrypt($sql, 'sql-history:' . $id . ':' . $userId);
            $this->database->execute('UPDATE sql_history SET query_encrypted = ? WHERE id = ? AND user_id = ?', [$encrypted, $id, $userId]);
        }
    }

    private function setTimeout(PDO $pdo, int $seconds): void
    {
        try {
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME = ' . ($seconds * 1000));
        } catch (\Throwable) {
            try {
                $pdo->exec('SET SESSION max_statement_time = ' . $seconds);
            } catch (\Throwable) {
                // Connection-level PDO timeout still applies when the server lacks statement timeout variables.
            }
        }
    }
}
