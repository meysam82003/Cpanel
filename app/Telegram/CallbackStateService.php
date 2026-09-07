<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Core\AppException;
use App\Core\Database;

final class CallbackStateService
{
    public function __construct(private readonly Database $database, private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new AppException('Callback signing secret is invalid.', 500, 'callback_secret_invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    public function create(int $userId, string $action, array $payload = [], int $ttlSeconds = 900): string
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $action)) {
            throw new AppException('Callback action is invalid.', 500, 'invalid_callback_action');
        }
        $opaque = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $this->database->execute('INSERT INTO callback_states (opaque_id, user_id, action, payload_json, expires_at) VALUES (?, ?, ?, ?, ?)', [$opaque, $userId, $action, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s', time() + max(30, min(3600, $ttlSeconds)))]);
        $signature = rtrim(strtr(base64_encode(substr(hash_hmac('sha256', $opaque, $this->secret, true), 0, 8)), '+/', '-_'), '=');
        return 'c:' . $opaque . '.' . $signature;
    }

    /** @return array{action:string,payload:array<string,mixed>} */
    public function consume(int $userId, string $callbackData): array
    {
        if (!preg_match('/^c:([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{11})$/', $callbackData, $match)) {
            throw new AppException('This button is invalid.', 403, 'callback_invalid');
        }
        $expected = rtrim(strtr(base64_encode(substr(hash_hmac('sha256', $match[1], $this->secret, true), 0, 8)), '+/', '-_'), '=');
        if (!hash_equals($expected, $match[2])) {
            throw new AppException('This button signature is invalid.', 403, 'callback_invalid', [], 'security.callbacks');
        }
        return $this->database->transaction(function (Database $db) use ($userId, $match): array {
            $row = $db->one('SELECT * FROM callback_states WHERE opaque_id = ? FOR UPDATE', [$match[1]]);
            if ($row === null || (int) $row['user_id'] !== $userId || $row['consumed_at'] !== null || strtotime((string) $row['expires_at']) < time()) {
                throw new AppException('This button expired, was already used, or belongs to another user.', 403, 'callback_expired', [], 'errors.expired-action');
            }
            $db->execute('UPDATE callback_states SET consumed_at = CURRENT_TIMESTAMP WHERE id = ?', [$row['id']]);
            $payload = json_decode((string) $row['payload_json'], true);
            return ['action' => (string) $row['action'], 'payload' => is_array($payload) ? $payload : []];
        });
    }
}
