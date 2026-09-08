<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AppException;
use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class RateLimiterTest extends TestCase
{
    use SqliteTestDatabase;

    public function testLimitIsEnforcedAndReturnsSafeRetryMetadata(): void
    {
        $database = $this->database();
        $limiter = new RateLimiter($database);

        $limiter->hit('api.user', 41, 2, 60);
        $limiter->hit('api.user', 41, 2, 60);

        try {
            $limiter->hit('api.user', 41, 2, 60);
            self::fail('The third request exceeded a two-request policy.');
        } catch (AppException $exception) {
            self::assertSame(429, $exception->httpStatus);
            self::assertSame('rate_limit_exceeded', $exception->safeCode);
            self::assertSame('errors.rate-limit', $exception->helpSlug);
            self::assertGreaterThanOrEqual(1, $exception->context['retry_after']);
            self::assertLessThanOrEqual(60, $exception->context['retry_after']);
        }

        self::assertSame(3, (int) $database->one('SELECT hits FROM rate_limits')['hits']);
    }

    public function testScopesAndSubjectsUseIndependentBuckets(): void
    {
        $database = $this->database();
        $limiter = new RateLimiter($database);

        $limiter->hit('api.user', 1, 1, 60);
        $limiter->hit('api.user', 2, 1, 60);
        $limiter->hit('api.public.health', '203.0.113.8', 1, 60);

        self::assertSame(3, (int) $database->one('SELECT COUNT(*) AS total FROM rate_limits')['total']);
    }

    public function testInvalidPolicyFailsClosed(): void
    {
        $limiter = new RateLimiter($this->database());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Rate-limit policy is invalid.');
        $limiter->hit('api.user', 1, 0, 60);
    }

    private function database(): \App\Core\Database
    {
        return $this->sqlite(<<<'SQL'
CREATE TABLE rate_limits (
    bucket_key TEXT PRIMARY KEY,
    hits INTEGER NOT NULL DEFAULT 0,
    window_started_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
    }
}
