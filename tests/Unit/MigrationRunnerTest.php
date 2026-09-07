<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function testSqliteMigrationIsTransactionalAndRecordedOnlyOnce(): void
    {
        $pdo = $this->sqlite();
        $directory = $this->directory();
        file_put_contents($directory . '/001_widgets.sql', <<<'SQL'
CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL);
INSERT INTO widgets (name) VALUES ('one;two');
SQL);

        $runner = new MigrationRunner($pdo);
        self::assertSame(['001_widgets.sql'], $runner->migrate($directory));
        self::assertSame([], $runner->migrate($directory));
        self::assertSame('one;two', $pdo->query('SELECT name FROM widgets')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    public function testSqliteRollsBackSchemaAndDoesNotRecordFailedMigration(): void
    {
        $pdo = $this->sqlite();
        $directory = $this->directory();
        file_put_contents($directory . '/001_broken.sql', <<<'SQL'
CREATE TABLE partial_change (id INTEGER PRIMARY KEY);
INSERT INTO table_that_does_not_exist (id) VALUES (1);
SQL);

        try {
            (new MigrationRunner($pdo))->migrate($directory);
            self::fail('A broken migration was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('001_broken.sql', $exception->getMessage());
        }

        $exists = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'partial_change'")->fetchColumn();
        self::assertSame(0, (int) $exists);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    private function sqlite(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/tcm-migrations-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            self::fail('The migration fixture directory could not be created.');
        }
        $this->directories[] = $directory;
        return $directory;
    }
}
