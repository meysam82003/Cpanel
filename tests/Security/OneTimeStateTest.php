<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AppException;
use App\Security\ConfirmationService;
use App\Security\RateLimiter;
use App\Telegram\CallbackStateService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class OneTimeStateTest extends TestCase
{
    use SqliteTestDatabase;

    public function testSignedCallbackIsShortOwnerBoundAndOneTime(): void
    {
        $database = $this->sqlite(<<<'SQL'
CREATE TABLE callback_states (id INTEGER PRIMARY KEY AUTOINCREMENT, opaque_id TEXT UNIQUE, user_id INTEGER, action TEXT, payload_json TEXT, expires_at TEXT, consumed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
SQL);
        $callbacks = new CallbackStateService($database, str_repeat('s', 32));
        $button = $callbacks->create(10, 'host.open', ['account' => 7]);
        self::assertLessThanOrEqual(64, strlen($button));
        try {
            $callbacks->consume(11, $button);
            self::fail('Cross-user callback was accepted.');
        } catch (AppException $exception) {
            self::assertSame('callback_expired', $exception->safeCode);
        }
        self::assertSame(['action' => 'host.open', 'payload' => ['account' => 7]], $callbacks->consume(10, $button));
        try {
            $callbacks->consume(10, $button);
            self::fail('Callback replay was accepted.');
        } catch (AppException $exception) {
            self::assertSame('callback_expired', $exception->safeCode);
        }
    }

    public function testConfirmationIsBoundToOwnerAccountActionTargetAndOneUse(): void
    {
        $database = $this->sqlite(<<<'SQL'
CREATE TABLE confirmation_nonces (id INTEGER PRIMARY KEY AUTOINCREMENT, nonce_hash TEXT UNIQUE, user_id INTEGER, account_id INTEGER, action TEXT, target_hash TEXT, operation_preview TEXT, expires_at TEXT, consumed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
SQL);
        $confirmations = new ConfirmationService($database);
        $nonce = $confirmations->issue(5, 9, 'database.drop', 'db_name', ['database' => 'db_name']);
        try {
            $confirmations->consume($nonce, 5, 10, 'database.drop', 'db_name');
            self::fail('Wrong account confirmation was accepted.');
        } catch (AppException $exception) {
            self::assertSame('confirmation_invalid', $exception->safeCode);
        }
        $confirmations->consume($nonce, 5, 9, 'database.drop', 'db_name');
        try {
            $confirmations->consume($nonce, 5, 9, 'database.drop', 'db_name');
            self::fail('Confirmation replay was accepted.');
        } catch (AppException $exception) {
            self::assertSame('confirmation_invalid', $exception->safeCode);
        }
    }

    public function testRateLimiterRejectsHitBeyondPolicy(): void
    {
        $database = $this->sqlite(<<<'SQL'
CREATE TABLE rate_limits (bucket_key TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_started_at TEXT, expires_at TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
SQL);
        $limiter = new RateLimiter($database);
        $limiter->hit('test', 42, 2, 60);
        $limiter->hit('test', 42, 2, 60);
        try {
            $limiter->hit('test', 42, 2, 60);
            self::fail('Rate limit was not enforced.');
        } catch (AppException $exception) {
            self::assertSame('rate_limit_exceeded', $exception->safeCode);
            self::assertGreaterThan(0, $exception->context['retry_after']);
        }
    }
}
