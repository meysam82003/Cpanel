<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AppException;
use App\Security\MiniAppSessionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class MiniAppSessionTest extends TestCase
{
    use SqliteTestDatabase;

    public function testSessionRequiresBearerCsrfAndMatchingDevice(): void
    {
        $database = $this->database();
        $sessions = new MiniAppSessionService($database, str_repeat('session-secret-', 4), 3600);
        $created = $sessions->create(1, time(), 'Telegram WebView/1', '203.0.113.7');
        self::assertArrayNotHasKey('token', $database->one('SELECT * FROM miniapp_sessions LIMIT 1'));
        self::assertSame(1, (int) $sessions->authenticate($created['token'], null, 'GET', 'Telegram WebView/1')['user_id']);
        $this->assertCode('csrf_failed', fn () => $sessions->authenticate($created['token'], 'wrong', 'POST', 'Telegram WebView/1'));
        $this->assertCode('session_binding_failed', fn () => $sessions->authenticate($created['token'], null, 'GET', 'Other device'));
        self::assertSame(1, (int) $sessions->authenticate($created['token'], $created['csrf'], 'DELETE', 'Telegram WebView/1')['user_id']);
    }

    public function testCreatingReplacementRevokesPriorDeviceSession(): void
    {
        $database = $this->database();
        $sessions = new MiniAppSessionService($database, str_repeat('session-secret-', 4), 3600);
        $first = $sessions->create(1, time(), 'Same device', null);
        $second = $sessions->create(1, time(), 'Same device', null);
        $this->assertCode('session_expired', fn () => $sessions->authenticate($first['token'], null, 'GET', 'Same device'));
        self::assertSame(1, (int) $sessions->authenticate($second['token'], null, 'GET', 'Same device')['user_id']);
    }

    private function database(): \App\Core\Database
    {
        $database = $this->sqlite(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER, username TEXT, first_name TEXT, last_name TEXT, language TEXT, ux_mode TEXT, status TEXT, is_super_admin INTEGER);
CREATE TABLE miniapp_sessions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, csrf_hash TEXT,
 telegram_auth_date INTEGER, user_agent_hash TEXT, ip_hash TEXT, last_used_at TEXT DEFAULT CURRENT_TIMESTAMP,
 expires_at TEXT, revoked_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $database->execute("INSERT INTO users (id, telegram_id, language, ux_mode, status, is_super_admin) VALUES (1, 123, 'fa', 'beginner', 'active', 0)");
        return $database;
    }

    private function assertCode(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected session error was not thrown.');
        } catch (AppException $exception) {
            self::assertSame($code, $exception->safeCode);
        }
    }
}
