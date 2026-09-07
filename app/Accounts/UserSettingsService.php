<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Core\AppException;
use App\Core\Database;

final class UserSettingsService
{
    public function __construct(private readonly Database $database, private readonly UserRepository $users)
    {
    }

    public function language(int $userId, string $language): void
    {
        $this->users->requireActive($userId);
        $this->users->setLanguage($userId, $language);
    }

    public function uxMode(int $userId, string $mode): void
    {
        if (!in_array($mode, ['beginner', 'advanced'], true)) {
            throw new AppException('UX mode must be beginner or advanced.', 422, 'invalid_ux_mode', [], 'settings');
        }
        $this->database->execute('UPDATE users SET ux_mode = ? WHERE id = ?', [$mode, $userId]);
    }

    /** @return list<array<string,mixed>> */
    public function favorites(int $userId): array
    {
        return $this->database->all('SELECT id, account_id, resource_type, resource_ref, label, created_at FROM favorites WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$userId]);
    }

    public function addFavorite(int $userId, ?int $accountId, string $type, string $reference, ?string $label): int
    {
        if (!in_array($type, ['host', 'file', 'directory', 'database', 'table', 'domain', 'page'], true) || trim($reference) === '' || strlen($reference) > 1024) {
            throw new AppException('Favorite target is invalid.', 422, 'invalid_favorite');
        }
        if ($accountId !== null) {
            $owned = $this->database->one('SELECT id FROM cpanel_accounts WHERE id = ? AND user_id = ?', [$accountId, $userId]);
            if ($owned === null) {
                throw new AppException('Favorite host is not owned by this user.', 404, 'host_not_found', [], 'security.idor');
            }
        }
        $this->database->execute('INSERT INTO favorites (user_id, account_id, resource_type, resource_ref, label) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), label = VALUES(label)', [$userId, $accountId, $type, $reference, $label === null ? null : mb_substr(trim($label), 0, 191)]);
        return $this->database->lastInsertId();
    }

    public function removeFavorite(int $userId, int $favoriteId): void
    {
        $statement = $this->database->execute('DELETE FROM favorites WHERE id = ? AND user_id = ?', [$favoriteId, $userId]);
        if ($statement->rowCount() !== 1) {
            throw new AppException('Favorite was not found or does not belong to you.', 404, 'favorite_not_found', [], 'security.idor');
        }
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $userId): array
    {
        return $this->database->all('SELECT id, account_id, action, resource_type, resource_ref, metadata_json, created_at FROM recent_actions WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$userId]);
    }
}
