<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Core\Database;
use App\Core\Translator;
use App\Telegram\TelegramClient;

final class NotificationService
{
    public function __construct(private readonly Database $database, private readonly Translator $translator, private readonly TelegramClient $telegram)
    {
    }

    /** @param array<string,scalar> $parameters */
    public function queue(int $userId, string $type, string $titleKey, string $bodyKey, array $parameters = []): int
    {
        $this->database->execute('INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) VALUES (?, ?, ?, ?, ?)', [$userId, $type, $titleKey, $bodyKey, json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return $this->database->lastInsertId();
    }

    public function deliverPending(int $limit = 25): int
    {
        $rows = $this->database->all('SELECT n.*, u.telegram_id, u.language FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.sent_at IS NULL AND u.status = \'active\' ORDER BY n.id LIMIT ' . max(1, min(100, $limit)));
        $sent = 0;
        foreach ($rows as $row) {
            $parameters = json_decode((string) ($row['parameters_json'] ?? '{}'), true);
            $parameters = is_array($parameters) ? array_filter($parameters, 'is_scalar') : [];
            $language = $row['language'] === 'en' ? 'en' : 'fa';
            try {
                $this->telegram->call('sendMessage', ['chat_id' => $row['telegram_id'], 'text' => '<b>' . htmlspecialchars($this->translator->get((string) $row['title_key'], $language, $parameters), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n" . htmlspecialchars($this->translator->get((string) $row['body_key'], $language, $parameters), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'parse_mode' => 'HTML']);
                $this->database->execute('UPDATE notifications SET sent_at = CURRENT_TIMESTAMP WHERE id = ?', [$row['id']]);
                $sent++;
            } catch (\Throwable) {
                // Retained for the next cron run; Telegram failures never expose message contents in logs.
            }
        }
        return $sent;
    }
}
