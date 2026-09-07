<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Accounts\AccountRepository;
use App\Core\AppException;
use App\Security\Crypto;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class AccountOwnershipTest extends TestCase
{
    use SqliteTestDatabase;

    public function testAccountLookupAndTokenDecryptionAreOwnerBound(): void
    {
        $database = $this->sqlite(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY);
CREATE TABLE cpanel_accounts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, label TEXT, base_url TEXT NOT NULL,
 hostname TEXT NOT NULL, port INTEGER NOT NULL, cpanel_username TEXT NOT NULL, encrypted_token TEXT,
 token_key_version INTEGER, token_mode TEXT, root_path TEXT, main_domain TEXT, status TEXT,
 last_error_code TEXT, last_checked_at TEXT, capability_checked_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE temporary_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, encrypted_token TEXT, token_key_version INTEGER, expires_at TEXT, consumed_at TEXT);
CREATE TABLE account_capabilities (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, available INTEGER);
SQL);
        $database->execute('INSERT INTO users (id) VALUES (1), (2)');
        $repository = new AccountRepository($database, new Crypto([1 => str_repeat('x', 32)], 1));
        $accountId = $repository->create(1, ['base_url' => 'https://8.8.8.8:2083', 'host' => '8.8.8.8', 'port' => 2083], 'alice', 'real-api-token', true, '/home/alice', 'example.test');
        self::assertSame('real-api-token', $repository->connection(1, $accountId)['token']);
        self::assertNotSame('real-api-token', $database->one('SELECT encrypted_token FROM cpanel_accounts WHERE id = ?', [$accountId])['encrypted_token']);
        try {
            $repository->connection(2, $accountId);
            self::fail('A different tenant accessed the account.');
        } catch (AppException $exception) {
            self::assertSame('host_not_found', $exception->safeCode);
            self::assertSame(404, $exception->httpStatus);
        }
    }
}
