<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\AppException;
use PDO;

final class DatabaseDumpWriter
{
    /** @return list<string> */
    public function tables(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $statement->execute([$database]);
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @param list<string> $requestedTables Empty means all base tables.
     * @param null|callable(int,string):void $progress
     * @return array{tables:int,rows:int,bytes:int,sha256:string}
     */
    public function write(PDO $pdo, string $database, array $requestedTables, string $mode, string $compression, string $path, ?callable $progress = null): array
    {
        if (!in_array($mode, ['full', 'structure', 'data'], true) || !in_array($compression, ['none', 'gz'], true)) {
            throw new AppException('SQL dump options are invalid.', 422, 'invalid_export_options');
        }
        $allTables = $this->tables($pdo, $database);
        $tables = $requestedTables === [] ? $allTables : array_values(array_unique(array_map('strval', $requestedTables)));
        if (array_diff($tables, $allTables) !== []) {
            throw new AppException('Export contains a table outside the selected database.', 422, 'invalid_export_table');
        }
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new AppException('Secure backup storage is unavailable.', 500, 'backup_storage_unavailable');
        }
        $stream = $compression === 'gz' ? gzopen($path, 'wb6') : fopen($path, 'xb');
        if ($stream === false) {
            throw new AppException('SQL dump file could not be created.', 500, 'export_file_unavailable');
        }
        $write = static function (string $chunk) use ($stream, $compression): void {
            $written = $compression === 'gz' ? gzwrite($stream, $chunk) : fwrite($stream, $chunk);
            if ($written === false || $written !== strlen($chunk)) {
                throw new AppException('SQL dump storage write failed.', 500, 'export_write_failed');
            }
        };
        $rowCount = 0;
        try {
            $write("-- Telegram cPanel Manager SQL Export\n-- Database: `" . str_replace('`', '``', $database) . "`\n-- Generated: " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            foreach ($tables as $index => $table) {
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                if ($mode !== 'data') {
                    $show = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
                    if (!is_array($show) || !isset($show[1])) {
                        throw new AppException('Could not read table structure for export.', 422, 'export_structure_failed', ['table' => $table]);
                    }
                    $write("-- Structure for {$quoted}\nDROP TABLE IF EXISTS {$quoted};\n" . $show[1] . ";\n\n");
                }
                if ($mode !== 'structure') {
                    $statement = $pdo->query('SELECT * FROM ' . $quoted, PDO::FETCH_ASSOC);
                    $columns = null;
                    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $columns ??= array_keys($row);
                        $values = array_map(fn (mixed $value): string => $this->sqlValue($pdo, $value), array_values($row));
                        $write('INSERT INTO ' . $quoted . ' (' . implode(',', array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns)) . ') VALUES (' . implode(',', $values) . ");\n");
                        $rowCount++;
                    }
                    $write("\n");
                }
                if ($progress !== null) {
                    $progress((int) floor(100 * (($index + 1) / max(1, count($tables)))), 'dumping_database');
                }
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            $compression === 'gz' ? gzclose($stream) : fclose($stream);
        }
        @chmod($path, 0600);
        return ['tables' => count($tables), 'rows' => $rowCount, 'bytes' => (int) filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    private function sqlValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $pdo->quote((string) $value);
    }
}
