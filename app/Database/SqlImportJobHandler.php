<?php

declare(strict_types=1);

namespace App\Database;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Database;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use PDO;
use ZipArchive;

final class SqlImportJobHandler implements JobHandler
{
    public function __construct(
        private readonly DirectDatabaseConnectionService $connections,
        private readonly DatabaseDumpWriter $dumps,
        private readonly Database $database,
        private readonly AuditLogger $audit,
        private readonly string $tempRoot,
        private readonly string $backupRoot,
    ) {
    }

    public function handle(JobContext $context, array $payload): array
    {
        $userId = $context->userId() ?? throw new AppException('Import job has no owner.', 500, 'queue_owner_missing');
        $accountId = $context->accountId() ?? throw new AppException('Import job has no host.', 500, 'queue_account_missing');
        $database = (string) ($payload['database'] ?? '');
        $path = (string) ($payload['path'] ?? '');
        $this->assertTempPath($path);
        $context->progress(5, 'validating_import');
        [$stream, $size, $cleanup] = $this->openSqlStream($path);
        $pdo = $this->connections->pdo($userId, $accountId, $database);
        $backup = null;
        if (($payload['backup_first'] ?? true) === true) {
            $context->progress(7, 'creating_pre_import_backup');
            $backup = $this->createBackup($pdo, $userId, $accountId, $database);
        }
        $executed = 0;
        $bytes = 0;
        try {
            $context->progress(10, 'importing');
            foreach ($this->statements($stream) as [$sql, $consumed]) {
                $bytes += $consumed;
                if (trim($sql) === '') {
                    continue;
                }
                $pdo->exec($sql);
                $executed++;
                if ($executed % 10 === 0) {
                    $context->progress(min(95, 10 + (int) floor(85 * min(1, $bytes / max(1, $size)))), 'importing');
                }
            }
            $this->audit->record($userId, $accountId, 'sql.import', 'success', 'database', $database, ['statements' => $executed, 'bytes' => $bytes]);
            $context->progress(99, 'finalizing');
            return ['database' => $database, 'statements' => $executed, 'bytes' => $bytes, 'pre_import_backup' => $backup];
        } catch (\Throwable $exception) {
            $this->audit->record($userId, $accountId, 'sql.import', 'failed', 'database', $database, ['statements_before_failure' => $executed, 'driver_code' => $exception->getCode()]);
            throw new AppException('SQL import stopped because the server rejected a statement. Existing committed statements may remain; restore the pre-import backup if enabled.', 422, 'sql_import_failed', ['statements_before_failure' => $executed], 'database.import');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $cleanup();
            @unlink($path);
        }
    }

    /** @return array{id:int,file:string,bytes:int,sha256:string} */
    private function createBackup(PDO $pdo, int $userId, int $accountId, string $databaseName): array
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $databaseName);
        $path = rtrim($this->backupRoot, '/') . '/pre-import-' . $safe . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
        $stats = $this->dumps->write($pdo, $databaseName, [], 'full', 'gz', $path);
        $this->database->execute(
            "INSERT INTO backups (user_id, account_id, type, target, size_bytes, status, provider_ref, metadata_json, completed_at) VALUES (?, ?, 'database', ?, ?, 'completed', ?, ?, CURRENT_TIMESTAMP)",
            [$userId, $accountId, $databaseName, $stats['bytes'], 'local:' . basename($path), json_encode(['reason' => 'before_sql_import', 'sha256' => $stats['sha256'], 'tables' => $stats['tables'], 'rows' => $stats['rows']], JSON_THROW_ON_ERROR)]
        );
        return ['id' => $this->database->lastInsertId(), 'file' => basename($path), 'bytes' => $stats['bytes'], 'sha256' => $stats['sha256']];
    }

    /** @return array{0:resource,1:int,2:callable} */
    private function openSqlStream(string $path): array
    {
        $lower = strtolower($path);
        if (str_ends_with($lower, '.sql')) {
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new AppException('SQL import file cannot be opened.', 422, 'import_file_unreadable');
            }
            return [$stream, max(1, (int) filesize($path)), static function (): void {}];
        }
        if (str_ends_with($lower, '.sql.gz')) {
            $target = $this->tempRoot . '/sql-' . bin2hex(random_bytes(12)) . '.sql';
            $input = gzopen($path, 'rb');
            $output = fopen($target, 'xb');
            if ($input === false || $output === false) {
                throw new AppException('Compressed SQL file cannot be opened.', 422, 'import_file_unreadable');
            }
            $total = 0;
            while (!gzeof($input)) {
                $chunk = gzread($input, 1024 * 1024);
                if ($chunk === false || $total + strlen($chunk) > 1_073_741_824) {
                    gzclose($input);
                    fclose($output);
                    @unlink($target);
                    throw new AppException('Decompressed SQL exceeds the 1 GiB safety limit.', 413, 'import_too_large');
                }
                fwrite($output, $chunk);
                $total += strlen($chunk);
            }
            gzclose($input);
            fclose($output);
            $stream = fopen($target, 'rb');
            return [$stream, max(1, $total), static function () use ($target): void { @unlink($target); }];
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new AppException('ZIP import archive cannot be opened.', 422, 'invalid_import_zip');
        }
        $sqlEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $name))) {
                $zip->close();
                throw new AppException('ZIP traversal entry was blocked.', 403, 'zip_slip_blocked', ['entry' => $name], 'files.zip');
            }
            if (preg_match('/\.sql$/i', $name)) {
                $sqlEntries[] = [$i, $name];
            }
        }
        if (count($sqlEntries) !== 1) {
            $zip->close();
            throw new AppException('Import ZIP must contain exactly one .sql file.', 422, 'invalid_import_zip');
        }
        [$index, $name] = $sqlEntries[0];
        $stat = $zip->statIndex($index);
        $size = (int) ($stat['size'] ?? 0);
        if ($size < 1 || $size > 1_073_741_824) {
            $zip->close();
            throw new AppException('SQL entry size is invalid or exceeds 1 GiB.', 413, 'import_too_large');
        }
        $target = $this->tempRoot . '/sql-' . bin2hex(random_bytes(12)) . '.sql';
        $input = $zip->getStream($name);
        $output = fopen($target, 'xb');
        if ($input === false || $output === false) {
            $zip->close();
            throw new AppException('SQL entry cannot be extracted safely.', 422, 'invalid_import_zip');
        }
        stream_copy_to_stream($input, $output, $size + 1);
        fclose($input);
        fclose($output);
        $zip->close();
        if ((int) filesize($target) !== $size) {
            @unlink($target);
            throw new AppException('Extracted SQL size did not match ZIP metadata.', 422, 'invalid_import_zip');
        }
        $stream = fopen($target, 'rb');
        return [$stream, $size, static function () use ($target): void { @unlink($target); }];
    }

    /** @param resource $stream
     *  @return \Generator<int,array{0:string,1:int}>
     */
    private function statements($stream): \Generator
    {
        $buffer = '';
        $statementBytes = 0;
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                throw new AppException('SQL import stream could not be read.', 422, 'import_read_failed');
            }
            $length = strlen($chunk);
            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];
                $next = $i + 1 < $length ? $chunk[$i + 1] : '';
                $buffer .= $char;
                $statementBytes++;
                if ($statementBytes > 16_777_216) {
                    throw new AppException('A SQL statement exceeds the 16 MiB limit.', 413, 'sql_statement_too_large');
                }
                if ($lineComment) {
                    if ($char === "\n") {
                        $lineComment = false;
                    }
                    continue;
                }
                if ($blockComment) {
                    if ($char === '*' && $next === '/') {
                        $buffer .= '/';
                        $statementBytes++;
                        $i++;
                        $blockComment = false;
                    }
                    continue;
                }
                if ($quote !== null) {
                    if ($char === '\\') {
                        if ($i + 1 < $length) {
                            $buffer .= $chunk[++$i];
                            $statementBytes++;
                        }
                        continue;
                    }
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($char === '-' && $next === '-') {
                    $lineComment = true;
                    continue;
                }
                if ($char === '#') {
                    $lineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    continue;
                }
                if (in_array($char, ["'", '"', '`'], true)) {
                    $quote = $char;
                    continue;
                }
                if ($char === ';') {
                    yield [substr($buffer, 0, -1), $statementBytes];
                    $buffer = '';
                    $statementBytes = 0;
                }
            }
        }
        if (trim($buffer) !== '') {
            yield [$buffer, $statementBytes];
        }
    }

    private function assertTempPath(string $path): void
    {
        $root = realpath($this->tempRoot);
        $real = realpath($path);
        if ($root === false || $real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new AppException('Import path is invalid.', 422, 'invalid_import_file');
        }
    }
}
