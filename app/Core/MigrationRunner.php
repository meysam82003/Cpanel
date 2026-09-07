<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private readonly string $driver;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $lockWaitSeconds = 10,
    ) {
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @return list<string> */
    public function migrate(string $directory): array
    {
        $directory = rtrim($directory, '/');
        if (!is_dir($directory) || !is_readable($directory)) {
            throw new RuntimeException('Migration directory is unavailable.');
        }

        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied = [];
        $lockName = null;

        if ($this->driver === 'mysql') {
            $lockName = $this->acquireMysqlLock($directory);
        }

        try {
            $this->ensureMigrationTable();
            foreach ($files as $file) {
                $version = basename($file);
                if (strlen($version) > 64) {
                    throw new RuntimeException('Migration filename is longer than 64 characters.');
                }
                if ($this->isApplied($version)) {
                    continue;
                }

                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException("Migration cannot be read: {$version}");
                }

                try {
                    $this->applyMigration($version, $this->splitStatements($sql));
                    $applied[] = $version;
                } catch (Throwable $exception) {
                    throw new RuntimeException("Migration failed ({$version}): {$exception->getMessage()}", 0, $exception);
                }
            }
        } finally {
            if ($lockName !== null) {
                $this->releaseMysqlLock($lockName);
            }
        }

        return $applied;
    }

    private function ensureMigrationTable(): void
    {
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)' . $suffix);
    }

    private function isApplied(string $version): bool
    {
        $check = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
        $check->execute([$version]);
        return (bool) $check->fetchColumn();
    }

    /** @param list<string> $statements */
    private function applyMigration(string $version, array $statements): void
    {
        // MySQL and MariaDB implicitly commit DDL. Running those statements in a
        // PDO transaction creates a false rollback guarantee and may make commit()
        // fail after the server already committed the schema change.
        if ($this->driver === 'mysql') {
            foreach ($statements as $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (PDOException $exception) {
                    if (!$this->isResumableMysqlDuplicate($exception, $statement)) {
                        throw $exception;
                    }
                }
            }
            $this->record($version);
            return;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($statements as $statement) {
                $this->pdo->exec($statement);
            }
            $this->record($version);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function record(string $version): void
    {
        $insert = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $insert->execute([$version]);
    }

    private function isResumableMysqlDuplicate(PDOException $exception, string $statement): bool
    {
        $serverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
        if ($serverCode === 1060 && preg_match('/^\s*ALTER\s+TABLE\b[\s\S]*\bADD\s+(?:COLUMN\s+)?/i', $statement)) {
            return true;
        }
        return $serverCode === 1061 && preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\b/i', $statement) === 1;
    }

    private function acquireMysqlLock(string $directory): string
    {
        $identity = $directory;
        try {
            $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            if (is_string($database) && $database !== '') {
                $identity = $database;
            }
        } catch (Throwable) {
            // The directory-derived identity still serializes this installation.
        }
        $lockName = 'tcm:migrate:' . substr(hash('sha256', $identity), 0, 48);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$lockName, max(1, min(60, $this->lockWaitSeconds))]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another migration process is active.');
        }
        return $lockName;
    }

    private function releaseMysqlLock(string $lockName): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (Throwable) {
            // MySQL releases advisory locks when this connection closes.
        }
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($quote === null && $char === '-' && $next === '-' && ($i === 0 || ctype_space($sql[$i - 1]))) {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            }
            if ($quote === null && ($char === "'" || $char === '"' || $char === '`')) {
                $quote = $char;
            } elseif ($quote !== null && $char === $quote && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $quote = null;
            }
            if ($char === ';' && $quote === null) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }

    public function seed(string $directory): void
    {
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Seed file cannot be read: ' . basename($file));
            }
            foreach ($this->splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
        }
    }
}
