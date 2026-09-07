<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Core\Database;
use App\Security\Crypto;

final class BotSessionService
{
    public function __construct(private readonly Database $database, private readonly Crypto $crypto, private readonly int $ttlSeconds = 1800)
    {
    }

    /** @param array<string,mixed> $payload */
    public function set(int $userId, string $state, array $payload = [], ?int $activeAccountId = null): void
    {
        $encrypted = $this->crypto->encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'bot-state:' . $userId);
        $this->database->execute(
            "INSERT INTO user_sessions (user_id, channel, state, state_payload, active_account_id, expires_at) VALUES (?, 'bot', ?, ?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), state_payload = VALUES(state_payload), active_account_id = COALESCE(VALUES(active_account_id), active_account_id), expires_at = VALUES(expires_at)",
            [$userId, $state, $encrypted, $activeAccountId, gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds)]
        );
    }

    /** @return array{state:?string,payload:array<string,mixed>,active_account_id:?int}|null */
    public function get(int $userId): ?array
    {
        $row = $this->database->one("SELECT state, state_payload, active_account_id, expires_at FROM user_sessions WHERE user_id = ? AND channel = 'bot'", [$userId]);
        if ($row === null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            $this->cancel($userId);
            return null;
        }
        $payload = [];
        if (is_string($row['state_payload']) && $row['state_payload'] !== '') {
            $decoded = json_decode($this->crypto->decrypt($row['state_payload'], 'bot-state:' . $userId), true, 128, JSON_THROW_ON_ERROR);
            $payload = is_array($decoded) ? $decoded : [];
        }
        return ['state' => is_string($row['state']) ? $row['state'] : null, 'payload' => $payload, 'active_account_id' => $row['active_account_id'] === null ? null : (int) $row['active_account_id']];
    }

    public function setActiveAccount(int $userId, int $accountId): void
    {
        $this->database->execute(
            "INSERT INTO user_sessions (user_id, channel, active_account_id, expires_at) VALUES (?, 'bot', ?, ?) ON DUPLICATE KEY UPDATE active_account_id = VALUES(active_account_id), expires_at = VALUES(expires_at)",
            [$userId, $accountId, gmdate('Y-m-d H:i:s', time() + 86_400)]
        );
    }

    public function cancel(int $userId): void
    {
        $this->database->execute("DELETE FROM user_sessions WHERE user_id = ? AND channel = 'bot'", [$userId]);
    }
}
