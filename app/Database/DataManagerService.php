<?php

declare(strict_types=1);

namespace App\Database;

use App\Audit\AuditLogger;
use App\Core\AppException;
use PDO;

final class DataManagerService
{
    public function __construct(private readonly DirectDatabaseConnectionService $connections, private readonly AuditLogger $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function tables(int $userId, int $accountId, string $database): array
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $statement = $pdo->prepare('SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS size_bytes, ENGINE AS engine, TABLE_COLLATION AS collation, UPDATE_TIME AS updated_at FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
        $statement->execute([$database]);
        return $statement->fetchAll();
    }

    /** @param list<array{column:string,operator:string,value?:mixed}> $filters
     *  @return array{rows:list<array<string,mixed>>,columns:list<array<string,mixed>>,pagination:array<string,int|bool>}
     */
    public function browse(int $userId, int $accountId, string $database, string $table, int $page, int $perPage, ?string $sort, string $direction, array $filters = []): array
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $columns = $this->columns($pdo, $database, $table);
        $names = array_column($columns, 'name');
        $page = max(1, $page);
        $perPage = max(10, min(200, $perPage));
        $sort = $sort !== null && in_array($sort, $names, true) ? $sort : ($this->primaryKeys($columns)[0] ?? $names[0] ?? null);
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        [$where, $params] = $this->where($filters, $names);
        $sql = 'SELECT * FROM ' . $this->quote($table) . $where;
        if ($sort !== null) {
            $sql .= ' ORDER BY ' . $this->quote($sort) . ' ' . $direction;
        }
        $sql .= ' LIMIT ' . ($perPage + 1) . ' OFFSET ' . (($page - 1) * $perPage);
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $perPage;
        return ['rows' => array_slice($rows, 0, $perPage), 'columns' => $columns, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $hasMore]];
    }

    /** @param array<string,mixed> $values */
    public function insert(int $userId, int $accountId, string $database, string $table, array $values): array
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $columns = $this->columns($pdo, $database, $table);
        $writable = array_column(array_filter($columns, static fn (array $column): bool => !str_contains(strtolower((string) $column['extra']), 'generated')), 'name');
        $values = array_intersect_key($values, array_flip($writable));
        if ($values === []) {
            throw new AppException('No writable table values were supplied.', 422, 'empty_row_values');
        }
        $names = array_keys($values);
        $sql = 'INSERT INTO ' . $this->quote($table) . ' (' . implode(',', array_map($this->quote(...), $names)) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')';
        $statement = $pdo->prepare($sql);
        $statement->execute(array_values($values));
        $this->audit->record($userId, $accountId, 'table.row_insert', 'success', 'table', $database . '.' . $table, ['columns' => $names, 'affected_rows' => $statement->rowCount()]);
        return ['affected_rows' => $statement->rowCount(), 'insert_id' => $pdo->lastInsertId()];
    }

    /** @param array<string,mixed> $key
     *  @param array<string,mixed> $values
     */
    public function update(int $userId, int $accountId, string $database, string $table, array $key, array $values): int
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $columns = $this->columns($pdo, $database, $table);
        $names = array_column($columns, 'name');
        $primary = $this->primaryKeys($columns);
        $this->assertKey($key, $primary);
        $key = array_replace(array_fill_keys($primary, null), $key);
        $values = array_intersect_key($values, array_flip($names));
        if ($values === []) {
            throw new AppException('No valid columns were supplied for update.', 422, 'empty_row_values');
        }
        $set = implode(', ', array_map(fn (string $name): string => $this->quote($name) . ' = ?', array_keys($values)));
        [$where, $keyValues] = $this->keyWhere($key);
        $statement = $pdo->prepare('UPDATE ' . $this->quote($table) . ' SET ' . $set . $where . ' LIMIT 1');
        $statement->execute([...array_values($values), ...$keyValues]);
        $this->audit->record($userId, $accountId, 'table.row_update', 'success', 'table', $database . '.' . $table, ['columns' => array_keys($values), 'affected_rows' => $statement->rowCount()]);
        return $statement->rowCount();
    }

    /** @param array<string,mixed> $key */
    public function delete(int $userId, int $accountId, string $database, string $table, array $key): int
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $columns = $this->columns($pdo, $database, $table);
        $primary = $this->primaryKeys($columns);
        $this->assertKey($key, $primary);
        $key = array_replace(array_fill_keys($primary, null), $key);
        [$where, $values] = $this->keyWhere($key);
        $statement = $pdo->prepare('DELETE FROM ' . $this->quote($table) . $where . ' LIMIT 1');
        $statement->execute($values);
        $this->audit->record($userId, $accountId, 'table.row_delete', 'success', 'table', $database . '.' . $table, ['affected_rows' => $statement->rowCount()]);
        return $statement->rowCount();
    }

    /** @param list<array<string,mixed>> $keys */
    public function bulkDelete(int $userId, int $accountId, string $database, string $table, array $keys): int
    {
        if ($keys === [] || count($keys) > 100) {
            throw new AppException('Bulk delete requires 1–100 selected rows.', 422, 'invalid_bulk_selection');
        }
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $columns = $this->columns($pdo, $database, $table);
        $primary = $this->primaryKeys($columns);
        $deleted = 0;
        $pdo->beginTransaction();
        try {
            foreach ($keys as $key) {
                $this->assertKey($key, $primary);
                $key = array_replace(array_fill_keys($primary, null), $key);
                [$where, $values] = $this->keyWhere($key);
                $statement = $pdo->prepare('DELETE FROM ' . $this->quote($table) . $where . ' LIMIT 1');
                $statement->execute($values);
                $deleted += $statement->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $this->audit->record($userId, $accountId, 'table.row_bulk_delete', 'success', 'table', $database . '.' . $table, ['selected' => count($keys), 'affected_rows' => $deleted]);
        return $deleted;
    }

    /** @return array{columns:list<array<string,mixed>>,indexes:list<array<string,mixed>>,status:array<string,mixed>} */
    public function structure(int $userId, int $accountId, string $database, string $table): array
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $columns = $this->columns($pdo, $database, $table);
        $indexes = $pdo->query('SHOW INDEX FROM ' . $this->quote($table))->fetchAll();
        $statement = $pdo->prepare('SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $statement->execute([$database, $table]);
        return ['columns' => $columns, 'indexes' => $indexes, 'status' => $statement->fetch() ?: []];
    }

    /** @param array<string,mixed> $definition */
    public function addColumn(int $userId, int $accountId, string $database, string $table, array $definition): void
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $this->columns($pdo, $database, $table);
        $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->columnDefinition($definition));
        $this->audit->record($userId, $accountId, 'table.column_add', 'success', 'table', $database . '.' . $table, ['column' => $definition['name'] ?? null]);
    }

    /** @param array<string,mixed> $definition */
    public function changeColumn(int $userId, int $accountId, string $database, string $table, string $oldName, array $definition): void
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $columns = $this->columns($pdo, $database, $table);
        if (!in_array($oldName, array_column($columns, 'name'), true)) {
            throw new AppException('Column does not exist.', 404, 'column_not_found');
        }
        $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' CHANGE COLUMN ' . $this->quote($oldName) . ' ' . $this->columnDefinition($definition));
        $this->audit->record($userId, $accountId, 'table.column_change', 'success', 'table', $database . '.' . $table, ['old_name' => $oldName, 'new_name' => $definition['name'] ?? null]);
    }

    public function dropColumn(int $userId, int $accountId, string $database, string $table, string $column): void
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $columns = $this->columns($pdo, $database, $table);
        if (!in_array($column, array_column($columns, 'name'), true)) {
            throw new AppException('Column does not exist.', 404, 'column_not_found');
        }
        $pdo->exec('ALTER TABLE ' . $this->quote($table) . ' DROP COLUMN ' . $this->quote($column));
        $this->audit->record($userId, $accountId, 'table.column_drop', 'success', 'table', $database . '.' . $table, ['column' => $column]);
    }

    /** @param list<string> $columns */
    public function addIndex(int $userId, int $accountId, string $database, string $table, string $name, array $columns, bool $unique = false, bool $primary = false): void
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $known = array_column($this->columns($pdo, $database, $table), 'name');
        if ($columns === [] || array_diff($columns, $known) !== []) {
            throw new AppException('Index contains an unknown column.', 422, 'invalid_index_columns');
        }
        $columnSql = implode(',', array_map($this->quote(...), $columns));
        if ($primary) {
            $sql = 'ALTER TABLE ' . $this->quote($table) . ' ADD PRIMARY KEY (' . $columnSql . ')';
            $name = 'PRIMARY';
        } else {
            $name = $this->identifier($name);
            $sql = 'ALTER TABLE ' . $this->quote($table) . ' ADD ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->quote($name) . ' (' . $columnSql . ')';
        }
        $pdo->exec($sql);
        $this->audit->record($userId, $accountId, 'table.index_add', 'success', 'table', $database . '.' . $table, ['index' => $name, 'columns' => $columns, 'unique' => $unique]);
    }

    public function dropIndex(int $userId, int $accountId, string $database, string $table, string $name): void
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $this->columns($pdo, $database, $table);
        $sql = $name === 'PRIMARY'
            ? 'ALTER TABLE ' . $this->quote($table) . ' DROP PRIMARY KEY'
            : 'ALTER TABLE ' . $this->quote($table) . ' DROP INDEX ' . $this->quote($this->identifier($name));
        $pdo->exec($sql);
        $this->audit->record($userId, $accountId, 'table.index_drop', 'success', 'table', $database . '.' . $table, ['index' => $name]);
    }

    /** @param list<array<string,mixed>> $columns */
    public function createTable(int $userId, int $accountId, string $database, string $table, array $columns, string $engine = 'InnoDB', string $collation = 'utf8mb4_unicode_ci'): void
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $table = $this->identifier($table);
        if ($columns === [] || count($columns) > 200 || !in_array($engine, ['InnoDB', 'MyISAM', 'MEMORY', 'Aria'], true) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $collation)) {
            throw new AppException('Table definition, engine, or collation is invalid.', 422, 'invalid_table_definition');
        }
        $definitions = array_map(fn (array $column): string => $this->columnDefinition($column), $columns);
        $pdo->exec('CREATE TABLE ' . $this->quote($table) . ' (' . implode(',', $definitions) . ') ENGINE=' . $engine . ' DEFAULT COLLATE=' . $collation);
        $this->audit->record($userId, $accountId, 'table.create', 'success', 'table', $database . '.' . $table, ['engine' => $engine, 'columns' => count($columns)]);
    }

    public function renameTable(int $userId, int $accountId, string $database, string $table, string $newName): void
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $this->columns($pdo, $database, $table);
        $newName = $this->identifier($newName);
        $pdo->exec('RENAME TABLE ' . $this->quote($table) . ' TO ' . $this->quote($newName));
        $this->audit->record($userId, $accountId, 'table.rename', 'success', 'table', $database . '.' . $table, ['new_name' => $newName]);
    }

    public function tableOperation(int $userId, int $accountId, string $database, string $table, string $operation): array
    {
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $this->columns($pdo, $database, $table);
        $operation = strtoupper($operation);
        if (!in_array($operation, ['DROP', 'TRUNCATE', 'OPTIMIZE', 'REPAIR'], true)) {
            throw new AppException('Table operation is not supported.', 422, 'invalid_table_operation');
        }
        $statement = $pdo->query($operation . ' TABLE ' . $this->quote($table));
        $result = $statement->columnCount() > 0 ? $statement->fetchAll() : ['affected_rows' => $statement->rowCount()];
        $this->audit->record($userId, $accountId, 'table.' . strtolower($operation), 'success', 'table', $database . '.' . $table);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function columns(PDO $pdo, string $database, string $table): array
    {
        $table = $this->identifier($table);
        $statement = $pdo->prepare('SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, DATA_TYPE AS data_type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, COLUMN_KEY AS column_key, EXTRA AS extra, COLLATION_NAME AS collation, ORDINAL_POSITION AS position FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$database, $table]);
        $columns = $statement->fetchAll();
        if ($columns === []) {
            throw new AppException('Table was not found in this database.', 404, 'table_not_found');
        }
        return $columns;
    }

    /** @param list<array<string,mixed>> $columns
     *  @return list<string>
     */
    private function primaryKeys(array $columns): array
    {
        return array_values(array_column(array_filter($columns, static fn (array $column): bool => (string) $column['column_key'] === 'PRI'), 'name'));
    }

    /** @param list<array{column:string,operator:string,value?:mixed}> $filters
     *  @param list<string> $columns
     *  @return array{0:string,1:list<mixed>}
     */
    private function where(array $filters, array $columns): array
    {
        if (count($filters) > 20) {
            throw new AppException('Too many row filters.', 422, 'too_many_filters');
        }
        $parts = [];
        $params = [];
        foreach ($filters as $filter) {
            $column = (string) ($filter['column'] ?? '');
            $operator = strtoupper(trim((string) ($filter['operator'] ?? '=')));
            if (!in_array($column, $columns, true) || !in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL'], true)) {
                throw new AppException('A row filter is invalid.', 422, 'invalid_row_filter');
            }
            $parts[] = $this->quote($column) . ' ' . $operator . (str_starts_with($operator, 'IS ') ? '' : ' ?');
            if (!str_starts_with($operator, 'IS ')) {
                $params[] = $filter['value'] ?? null;
            }
        }
        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @param array<string,mixed> $key
     *  @return array{0:string,1:list<mixed>}
     */
    private function keyWhere(array $key): array
    {
        $parts = [];
        $values = [];
        foreach ($key as $column => $value) {
            $parts[] = $this->quote((string) $column) . ($value === null ? ' IS NULL' : ' = ?');
            if ($value !== null) {
                $values[] = $value;
            }
        }
        return [' WHERE ' . implode(' AND ', $parts), $values];
    }

    /** @param array<string,mixed> $key
     *  @param list<string> $primary
     */
    private function assertKey(array $key, array $primary): void
    {
        if ($primary === [] || count($key) !== count($primary) || array_diff(array_keys($key), $primary) !== [] || array_diff($primary, array_keys($key)) !== []) {
            throw new AppException('Row changes require every primary-key column.', 422, 'row_primary_key_required');
        }
    }

    /** @param array<string,mixed> $definition */
    private function columnDefinition(array $definition): string
    {
        $name = $this->identifier((string) ($definition['name'] ?? ''));
        $type = strtoupper((string) ($definition['type'] ?? 'VARCHAR'));
        $types = ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE', 'BIT', 'BOOLEAN', 'CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'BINARY', 'VARBINARY', 'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB', 'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR', 'JSON', 'ENUM', 'SET'];
        if (!in_array($type, $types, true)) {
            throw new AppException('Column data type is not allowed.', 422, 'invalid_column_type', ['type' => $type]);
        }
        $length = $definition['length'] ?? null;
        $typeSql = $type;
        if ($length !== null && $length !== '') {
            $length = (string) $length;
            if (in_array($type, ['ENUM', 'SET'], true)) {
                $typeSql .= '(' . $this->enumSetValues($length) . ')';
            } else {
                if (!preg_match('/^\d{1,6}(?:,\d{1,6})?$/', $length)) {
                    throw new AppException('Column length is invalid.', 422, 'invalid_column_length');
                }
                $typeSql .= '(' . $length . ')';
            }
        }
        $sql = $this->quote($name) . ' ' . $typeSql;
        if (!empty($definition['unsigned']) && in_array($type, ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE'], true)) {
            $sql .= ' UNSIGNED';
        }
        $nullable = (bool) ($definition['nullable'] ?? false);
        $sql .= $nullable ? ' NULL' : ' NOT NULL';
        if (array_key_exists('default', $definition)) {
            $default = $definition['default'];
            if ($default === null) {
                $sql .= ' DEFAULT NULL';
            } elseif (is_string($default) && in_array(strtoupper($default), ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()', 'NULL'], true)) {
                $sql .= ' DEFAULT ' . strtoupper($default);
            } else {
                $sql .= ' DEFAULT ' . $this->literal((string) $default);
            }
        }
        if (!empty($definition['auto_increment']) && in_array($type, ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'], true)) {
            $sql .= ' AUTO_INCREMENT';
        }
        if (!empty($definition['primary'])) {
            $sql .= ' PRIMARY KEY';
        } elseif (!empty($definition['unique'])) {
            $sql .= ' UNIQUE';
        }
        if (isset($definition['after']) && $definition['after'] !== '') {
            $sql .= ' AFTER ' . $this->quote($this->identifier((string) $definition['after']));
        } elseif (!empty($definition['first'])) {
            $sql .= ' FIRST';
        }
        return $sql;
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $value)) {
            throw new AppException('SQL identifier is invalid.', 422, 'invalid_sql_identifier');
        }
        return $value;
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $this->identifier($identifier)) . '`';
    }

    private function literal(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    private function enumSetValues(string $input): string
    {
        $values = str_getcsv($input, ',', "'", '\\');
        if ($values === [] || count($values) > 100) {
            throw new AppException('ENUM or SET requires 1–100 values.', 422, 'invalid_column_length');
        }
        $safe = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new AppException('An ENUM or SET value is invalid.', 422, 'invalid_column_length');
            }
            $safe[] = $this->literal($value);
        }
        return implode(',', array_values(array_unique($safe)));
    }
}
