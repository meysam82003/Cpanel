<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AppException;
use App\Core\Database;

final class ConfirmationService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<string, mixed> $preview */
    public function issue(int $userId, ?int $accountId, string $action, string $target, array $preview, int $ttl = 300): string
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->database->execute(
            'INSERT INTO confirmation_nonces (nonce_hash, user_id, account_id, action, target_hash, operation_preview, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [hash('sha256', $nonce), $userId, $accountId, $action, hash('sha256', $target), json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s', time() + $ttl)]
        );
        return $nonce;
    }

    public function consume(string $nonce, int $userId, ?int $accountId, string $action, string $target): void
    {
        $hash = hash('sha256', $nonce);
        $this->database->transaction(function (Database $db) use ($hash, $userId, $accountId, $action, $target): void {
            $row = $db->one('SELECT * FROM confirmation_nonces WHERE nonce_hash = ? FOR UPDATE', [$hash]);
            $valid = $row !== null
                && (int) $row['user_id'] === $userId
                && (($row['account_id'] === null && $accountId === null) || (int) $row['account_id'] === $accountId)
                && hash_equals((string) $row['action'], $action)
                && hash_equals((string) $row['target_hash'], hash('sha256', $target))
                && $row['consumed_at'] === null
                && strtotime((string) $row['expires_at']) >= time();
            if (!$valid) {
                throw new AppException('This confirmation is invalid, expired, used, or belongs to another session.', 403, 'confirmation_invalid', [], 'security.confirmations');
            }
            $db->execute('UPDATE confirmation_nonces SET consumed_at = CURRENT_TIMESTAMP WHERE id = ?', [$row['id']]);
        });
    }
}

