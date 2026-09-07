<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\AppException;

final class SqlSafetyAnalyzer
{
    /** @return array{type:string,read_only:bool,destructive:bool,reasons:list<string>,requires_confirmation:bool} */
    public function analyze(string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '' || strlen($sql) > 1_048_576 || str_contains($sql, "\0")) {
            throw new AppException('SQL query is empty or exceeds the 1 MiB safety limit.', 422, 'invalid_sql', [], 'database.sql');
        }
        // MySQL executes the body of versioned comments (/*!...*/), so treating
        // them as ordinary comments would let hidden statements bypass analysis.
        if (preg_match('#/\*!#', $sql) === 1) {
            throw new AppException('Executable SQL comments are not accepted.', 403, 'sql_statement_not_allowed', [], 'database.sql');
        }
        $clean = $this->stripCommentsAndStrings($sql);
        if ($this->hasMultipleStatements($clean)) {
            throw new AppException('Execute one SQL statement at a time.', 422, 'multiple_sql_statements', [], 'database.sql');
        }
        if (!preg_match('/^\s*([A-Za-z]+)/', $clean, $match)) {
            throw new AppException('SQL statement type could not be determined.', 422, 'invalid_sql');
        }
        [$type, $mainStatement] = $this->statement($clean, strtoupper($match[1]));
        $allowed = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME', 'OPTIMIZE', 'REPAIR', 'ANALYZE'];
        if (!in_array($type, $allowed, true)) {
            throw new AppException('This SQL statement type is not allowed in the console.', 403, 'sql_statement_not_allowed', ['type' => $type], 'database.sql');
        }
        if (preg_match('/\b(?:INTO\s+(?:OUTFILE|DUMPFILE)|LOAD_FILE\s*\(|SLEEP\s*\(|BENCHMARK\s*\(|GET_LOCK\s*\(|RELEASE_LOCK\s*\()/i', $clean) === 1) {
            throw new AppException('Server file and blocking functions are not allowed in the SQL console.', 403, 'sql_statement_not_allowed', [], 'database.sql');
        }
        $reasons = [];
        if (preg_match('/\bDROP\s+(?:DATABASE|SCHEMA|TABLE|VIEW|INDEX)\b/i', $mainStatement)) {
            $reasons[] = 'drop_object';
        }
        if (preg_match('/\bTRUNCATE\b/i', $mainStatement)) {
            $reasons[] = 'truncate';
        }
        if ($type === 'DELETE' && !preg_match('/\bWHERE\b/i', $mainStatement)) {
            $reasons[] = 'delete_without_where';
        }
        if ($type === 'UPDATE' && !preg_match('/\bWHERE\b/i', $mainStatement)) {
            $reasons[] = 'update_without_where';
        }
        $readOnly = in_array($type, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true);
        return ['type' => $type, 'read_only' => $readOnly, 'destructive' => $reasons !== [], 'reasons' => $reasons, 'requires_confirmation' => $reasons !== []];
    }

    /** @return array{0:string,1:string} */
    private function statement(string $sql, string $initial): array
    {
        if ($initial !== 'WITH') {
            return [$initial, $sql];
        }
        $offset = stripos($sql, 'WITH') + 4;
        $depth = 0;
        $length = strlen($sql);
        for ($index = $offset; $index < $length; $index++) {
            if ($sql[$index] === '(') {
                $depth++;
                continue;
            }
            if ($sql[$index] !== ')' || $depth < 1) {
                continue;
            }
            $depth--;
            if ($depth !== 0) {
                continue;
            }
            $next = $index + 1;
            while ($next < $length && ctype_space($sql[$next])) {
                $next++;
            }
            if (($sql[$next] ?? '') === ',') {
                continue;
            }
            if (preg_match('/\G([A-Za-z]+)/', $sql, $match, 0, $next) === 1) {
                $candidate = strtoupper($match[1]);
                if ($candidate !== 'AS') {
                    return [$candidate, substr($sql, $next)];
                }
            }
        }
        throw new AppException('The main statement after WITH could not be determined.', 422, 'invalid_sql', [], 'database.sql');
    }

    private function stripCommentsAndStrings(string $sql): string
    {
        $output = '';
        $length = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($quote === null && $char === '-' && $next === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $output .= "\n";
                continue;
            }
            if ($quote === null && $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $output .= "\n";
                continue;
            }
            if ($quote === null && $char === '/' && $next === '*') {
                $i += 2;
                while ($i < $length - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i++;
                $output .= ' ';
                continue;
            }
            if ($quote === null && in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $output .= $char === '`' ? '`' : "''";
                continue;
            }
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $output .= $quote === '`' ? '`' : '';
                    $quote = null;
                }
                continue;
            }
            $output .= $char;
        }
        return $output;
    }

    private function hasMultipleStatements(string $sql): bool
    {
        $trimmed = rtrim($sql);
        $trimmed = rtrim($trimmed, "; \t\n\r\0\x0B");
        return str_contains($trimmed, ';');
    }
}
