<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\MigrationRunner;
use App\Help\HelpSeeder;
use PDO;
use PHPUnit\Framework\TestCase;

final class MariaDbInstallationTest extends TestCase
{
    public function testFreshInstallAndIdempotentRerunOnMariaDb(): void
    {
        $dsn = getenv('TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('TEST_MYSQL_DSN is required for the MariaDB installation smoke test.');
        }

        $pdo = new PDO(
            $dsn,
            (string) getenv('TEST_MYSQL_USER'),
            (string) getenv('TEST_MYSQL_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        $root = dirname(__DIR__, 2);
        $runner = new MigrationRunner($pdo);

        $applied = $runner->migrate($root . '/database/migrations');
        self::assertCount(count(glob($root . '/database/migrations/*.sql') ?: []), $applied);
        self::assertContains('001_initial.sql', $applied);
        self::assertContains('011_deployment_recovery.sql', $applied);

        $runner->seed($root . '/database/seeds');
        $database = Database::fromPdo($pdo);
        $expectedHelp = require $root . '/resources/help/topics.php';
        $seededHelp = (new HelpSeeder($database))->seed($root . '/resources/help/topics.php');

        self::assertCount($seededHelp, $expectedHelp);
        self::assertSame($seededHelp, (int) $database->one('SELECT COUNT(*) AS total FROM help_topics')['total']);
        self::assertSame(4, (int) $database->one('SELECT COUNT(*) AS total FROM plans')['total']);
        self::assertSame(1, (int) $database->one("SELECT backup_enabled FROM plans WHERE slug = 'pro'")['backup_enabled']);
        self::assertSame(1, (int) $database->one("SELECT deployment_enabled FROM plans WHERE slug = 'business'")['deployment_enabled']);

        $tables = $database->all(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\''
        );
        self::assertGreaterThanOrEqual(40, count($tables));
        foreach ($tables as $table) {
            self::assertSame('InnoDB', $table['ENGINE'], 'Unexpected engine for ' . $table['TABLE_NAME']);
            self::assertStringStartsWith('utf8mb4_', (string) $table['TABLE_COLLATION'], 'Unexpected collation for ' . $table['TABLE_NAME']);
        }

        self::assertGreaterThan(
            20,
            (int) $database->one(
                "SELECT COUNT(*) AS total FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()"
            )['total'],
        );

        self::assertSame([], $runner->migrate($root . '/database/migrations'));
        $runner->seed($root . '/database/seeds');
        self::assertSame(4, (int) $database->one('SELECT COUNT(*) AS total FROM plans')['total']);

        (new HelpSeeder($database))->seed($root . '/resources/help/topics.php');
        self::assertSame($seededHelp, (int) $database->one('SELECT COUNT(*) AS total FROM help_topics')['total']);
        self::assertSame(
            count($applied),
            (int) $database->one('SELECT COUNT(*) AS total FROM schema_migrations')['total'],
        );
    }
}
