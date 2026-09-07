<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AppException;
use App\Core\Database;

final class RateLimiter
{
    public function __construct(private readonly Database $database)
    {
    }

    public function hit(string $scope, string|int $subject, int $limit, int $windowSeconds): void
    {
        if ($limit < 1 || $windowSeconds < 1) {
            throw new AppException('Rate-limit policy is invalid.', 500, 'rate_limit_policy_invalid');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $window = intdiv($now->getTimestamp(), $windowSeconds) * $windowSeconds;
        $bucket = hash('sha256', $scope . '|' . $subject . '|' . $window);
        $start = gmdate('Y-m-d H:i:s', $window);
        $expires = gmdate('Y-m-d H:i:s', $window + $windowSeconds + 5);

        $driver = (string) $this->database->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO rate_limits (bucket_key, hits, window_started_at, expires_at) VALUES (?, 1, ?, ?) ON CONFLICT(bucket_key) DO UPDATE SET hits = hits + 1, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO rate_limits (bucket_key, hits, window_started_at, expires_at) VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE hits = hits + 1, updated_at = CURRENT_TIMESTAMP';
        $this->database->execute($sql, [$bucket, $start, $expires]);
        $row = $this->database->one('SELECT hits FROM rate_limits WHERE bucket_key = ?', [$bucket]);
        if ((int) ($row['hits'] ?? 0) > $limit) {
            throw new AppException(
                'Too many requests. Wait briefly and try again.',
                429,
                'rate_limit_exceeded',
                ['retry_after' => max(1, ($window + $windowSeconds) - time())],
                'errors.rate-limit'
            );
        }
    }
}
