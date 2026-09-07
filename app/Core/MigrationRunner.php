<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function migrate(string $directory): array
    {
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied = [];

        foreach ($files as $file) {
            $version = basename($file);
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $check = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
            $check->execute([$version]);
            if ($check->fetchColumn()) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Migration cannot be read: {$version}");
            }

            $this->pdo->beginTransaction();
            try {
                foreach ($this->splitStatements($sql) as $statement) {
                    $this->pdo->exec($statement);
                }
                $insert = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
                $insert->execute([$version]);
                $this->pdo->commit();
                $applied[] = $version;
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Migration failed ({$version}): {$exception->getMessage()}", 0, $exception);
            }
        }
        return $applied;
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

