<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AppException;
use App\Core\Database;

final class OperationLockService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function acquire(string $key, int $ttlSeconds = 1200): string
    {
        $this->assertKey($key);
        $token = hash('sha256', random_bytes(32));
        return $this->database->transaction(function (Database $db) use ($key, $token, $ttlSeconds): string {
            $db->execute('DELETE FROM operation_locks WHERE lock_key = ? AND expires_at < CURRENT_TIMESTAMP', [$key]);
            try {
                $db->execute('INSERT INTO operation_locks (lock_key, owner_token, expires_at) VALUES (?, ?, ?)', [$key, $token, gmdate('Y-m-d H:i:s', time() + max(30, min(3600, $ttlSeconds)))]);
            } catch (\PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw new AppException('Another operation is already changing this target.', 409, 'operation_locked', [], 'errors.concurrent-operation');
                }
                throw $exception;
            }
            return $token;
        });
    }

    public function renew(string $key, string $token, int $ttlSeconds = 1200): void
    {
        $this->assertKey($key);
        $this->assertToken($token);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(30, min(3600, $ttlSeconds)));
        $updated = $this->database->execute('UPDATE operation_locks SET expires_at = ? WHERE lock_key = ? AND owner_token = ? AND expires_at >= CURRENT_TIMESTAMP', [$expiresAt, $key, $token]);
        if ($updated->rowCount() !== 1) {
            throw new AppException('The operation lock lease was lost or expired.', 409, 'operation_lock_lost', [], 'errors.concurrent-operation');
        }
    }

    public function release(string $key, string $token): void
    {
        $this->assertKey($key);
        $this->assertToken($token);
        $this->database->execute('DELETE FROM operation_locks WHERE lock_key = ? AND owner_token = ?', [$key, $token]);
    }

    private function assertKey(string $key): void
    {
        if (!preg_match('/^[a-z0-9:._-]{3,191}$/', $key)) {
            throw new AppException('Operation lock key is invalid.', 500, 'invalid_lock_key');
        }
    }

    private function assertToken(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new AppException('Operation lock owner token is invalid.', 500, 'invalid_lock_token');
        }
    }
}
