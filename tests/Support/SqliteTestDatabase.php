<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use PDO;

trait SqliteTestDatabase
{
    private function sqlite(string $schema): Database
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for isolated database tests.');
        }
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec($schema);
        return Database::fromPdo($pdo);
    }
}
