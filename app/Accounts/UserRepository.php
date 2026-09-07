<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Core\AppException;
use App\Core\Database;

final class UserRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<string,mixed> $telegramUser
     *  @return array<string,mixed>
     */
    public function upsertTelegram(array $telegramUser, ?string $language = null): array
    {
        $telegramId = filter_var($telegramUser['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($telegramId === false) {
            throw new AppException('Telegram user ID is invalid.', 422, 'invalid_telegram_user');
        }
        $language = in_array($language, ['fa', 'en'], true) ? $language : null;
        $this->database->execute(
            'INSERT INTO users (telegram_id, username, first_name, last_name, language, last_seen_at) VALUES (?, ?, ?, ?, COALESCE(?, \'fa\'), CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name), last_seen_at = CURRENT_TIMESTAMP',
            [(int) $telegramId, $this->clean($telegramUser['username'] ?? null, 64), $this->clean($telegramUser['first_name'] ?? null), $this->clean($telegramUser['last_name'] ?? null), $language]
        );
        $user = $this->byTelegramId((int) $telegramId);
        if ($user === null) {
            throw new AppException('User registration failed.', 500, 'user_registration_failed');
        }
        $this->ensureFreePlan((int) $user['id']);
        return $user;
    }

    /** @return array<string,mixed>|null */
    public function byTelegramId(int $telegramId): ?array
    {
        return $this->database->one('SELECT * FROM users WHERE telegram_id = ?', [$telegramId]);
    }

    /** @return array<string,mixed> */
    public function requireActive(int $id): array
    {
        $row = $this->database->one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($row === null || $row['status'] !== 'active') {
            throw new AppException('User is unavailable or inactive.', 403, 'user_not_active');
        }
        return $row;
    }

    public function setLanguage(int $userId, string $language): void
    {
        if (!in_array($language, ['fa', 'en'], true)) {
            throw new AppException('Unsupported language.', 422, 'unsupported_language');
        }
        $this->database->execute('UPDATE users SET language = ? WHERE id = ?', [$language, $userId]);
    }

    private function ensureFreePlan(int $userId): void
    {
        $this->database->execute(
            'INSERT INTO user_plans (user_id, plan_id, status) SELECT ?, id, \'active\' FROM plans WHERE slug = \'free\' AND NOT EXISTS (SELECT 1 FROM user_plans WHERE user_id = ? AND status = \'active\') LIMIT 1',
            [$userId, $userId]
        );
    }

    private function clean(mixed $value, int $max = 255): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return mb_substr(trim($value), 0, $max);
    }
}
