<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AppException;
use App\Database\SqlSafetyAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlSafetyAnalyzerTest extends TestCase
{
    private SqlSafetyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SqlSafetyAnalyzer();
    }

    public function testClassifiesReadAndWriteStatements(): void
    {
        self::assertTrue($this->analyzer->analyze("SELECT ';' AS separator")['read_only']);
        self::assertFalse($this->analyzer->analyze('INSERT INTO notes (body) VALUES (\'safe\')')['read_only']);
        self::assertSame('SELECT', $this->analyzer->analyze('WITH recent AS (SELECT id FROM log) SELECT * FROM recent')['type']);
    }

    #[DataProvider('destructiveStatements')]
    public function testDestructiveStatementsRequireConfirmation(string $sql, string $reason): void
    {
        $analysis = $this->analyzer->analyze($sql);
        self::assertTrue($analysis['requires_confirmation']);
        self::assertContains($reason, $analysis['reasons']);
    }

    public static function destructiveStatements(): array
    {
        return [
            ['DROP TABLE customers', 'drop_object'],
            ['TRUNCATE TABLE events', 'truncate'],
            ['DELETE FROM sessions', 'delete_without_where'],
            ['UPDATE users SET status = 0', 'update_without_where'],
            ['WITH active AS (SELECT id FROM users WHERE status=1) DELETE FROM sessions', 'delete_without_where'],
            ['WITH active AS (SELECT id FROM users WHERE status=1) UPDATE sessions SET valid=0', 'update_without_where'],
        ];
    }

    #[DataProvider('blockedStatements')]
    public function testBlocksMultipleHiddenFileAndBlockingOperations(string $sql): void
    {
        try {
            $this->analyzer->analyze($sql);
            self::fail('Unsafe SQL was accepted.');
        } catch (AppException $exception) {
            self::assertContains($exception->safeCode, ['multiple_sql_statements', 'sql_statement_not_allowed']);
        }
    }

    public static function blockedStatements(): array
    {
        return [
            ['SELECT 1; DELETE FROM users'],
            ['/*!50000 DROP TABLE users */'],
            ["SELECT * FROM users INTO OUTFILE '/tmp/users'"],
            ["SELECT LOAD_FILE('/etc/passwd')"],
            ['SELECT SLEEP(20)'],
            ['SELECT BENCHMARK(1000000, SHA2(\'x\', 256))'],
            ['CALL dangerous_procedure()'],
        ];
    }

    public function testWhereInsideStringOrCommentDoesNotSuppressConfirmation(): void
    {
        self::assertTrue($this->analyzer->analyze("DELETE FROM users /* WHERE id=1 */")['requires_confirmation']);
        self::assertTrue($this->analyzer->analyze("UPDATE users SET note='WHERE id=1'")['requires_confirmation']);
    }
}
