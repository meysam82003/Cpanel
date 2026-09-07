<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Security\OperationLockService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class OperationLockServiceTest extends TestCase
{
    use SqliteTestDatabase;

    public function testOwnerCanRenewAndOnlyOwnerCanReleaseLock(): void
    {
        $database = $this->sqlite('CREATE TABLE operation_locks (lock_key TEXT PRIMARY KEY, owner_token TEXT NOT NULL, expires_at TEXT NOT NULL);');
        $locks = new OperationLockService($database);
        $token = $locks->acquire('deploy:10:target', 30);

        $locks->renew('deploy:10:target', $token, 600);
        $row = $database->one('SELECT * FROM operation_locks WHERE lock_key = ?', ['deploy:10:target']);
        self::assertSame($token, $row['owner_token']);
        self::assertGreaterThan(time() + 500, strtotime((string) $row['expires_at']));

        $other = str_repeat('f', 64);
        $this->assertSafeCode('operation_lock_lost', fn () => $locks->renew('deploy:10:target', $other, 600));
        $locks->release('deploy:10:target', $other);
        self::assertNotNull($database->one('SELECT lock_key FROM operation_locks WHERE lock_key = ?', ['deploy:10:target']));
        $locks->release('deploy:10:target', $token);
        self::assertNull($database->one('SELECT lock_key FROM operation_locks WHERE lock_key = ?', ['deploy:10:target']));
    }

    public function testExpiredLockCannotBeRenewedAndCanBeReacquired(): void
    {
        $database = $this->sqlite('CREATE TABLE operation_locks (lock_key TEXT PRIMARY KEY, owner_token TEXT NOT NULL, expires_at TEXT NOT NULL);');
        $locks = new OperationLockService($database);
        $database->execute("INSERT INTO operation_locks VALUES ('deploy:10:expired', ?, '2000-01-01 00:00:00')", [str_repeat('a', 64)]);

        $this->assertSafeCode('operation_lock_lost', fn () => $locks->renew('deploy:10:expired', str_repeat('a', 64), 600));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $locks->acquire('deploy:10:expired', 600));
        $this->assertSafeCode('operation_locked', fn () => $locks->acquire('deploy:10:expired', 600));
    }

    private function assertSafeCode(string $expected, callable $operation): void
    {
        try {
            $operation();
            self::fail('Operation unexpectedly succeeded: ' . $expected);
        } catch (AppException $exception) {
            self::assertSame($expected, $exception->safeCode);
        }
    }
}
