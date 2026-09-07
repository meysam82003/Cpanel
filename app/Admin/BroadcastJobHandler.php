<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\AppException;
use App\Core\Database;
use App\Queue\JobContext;
use App\Queue\JobHandler;
use App\Queue\QueueService;
use App\Telegram\TelegramClient;

final class BroadcastJobHandler implements JobHandler
{
    public function __construct(private readonly Database $database, private readonly QueueService $queue, private readonly TelegramClient $telegram)
    {
    }

    public function handle(JobContext $context, array $payload): array
    {
        $adminId = $context->userId() ?? throw new AppException('Broadcast job has no owner.', 500, 'queue_owner_missing');
        $broadcastId = (int) ($payload['broadcast_id'] ?? 0);
        $after = max(0, (int) ($payload['after_user_id'] ?? 0));
        $broadcast = $this->database->one('SELECT b.* FROM admin_broadcasts b JOIN users u ON u.id = b.created_by WHERE b.id = ? AND b.created_by = ? AND u.is_super_admin = 1', [$broadcastId, $adminId]);
        if ($broadcast === null) {
            throw new AppException('Broadcast was not found or is not owned by this admin.', 404, 'broadcast_not_found');
        }
        $filter = json_decode((string) ($broadcast['target_filter_json'] ?? '{}'), true);
        $filter = is_array($filter) ? $filter : [];
        $where = ["u.id > ?", "u.status = 'active'"];
        $params = [$after];
        if (isset($filter['language']) && in_array($filter['language'], ['fa', 'en'], true)) {
            $where[] = 'u.language = ?';
            $params[] = $filter['language'];
        }
        if (isset($filter['plan']) && is_string($filter['plan']) && preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $filter['plan'])) {
            $where[] = "EXISTS (SELECT 1 FROM user_plans up JOIN plans p ON p.id = up.plan_id WHERE up.user_id = u.id AND up.status = 'active' AND (up.ends_at IS NULL OR up.ends_at > CURRENT_TIMESTAMP) AND p.slug = ?)";
            $params[] = $filter['plan'];
        }
        $recipients = $this->database->all('SELECT u.id, u.telegram_id, u.language FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.id LIMIT 20', $params);
        $sent = 0;
        $failed = 0;
        $lastId = $after;
        $this->database->execute("UPDATE admin_broadcasts SET status = 'sending' WHERE id = ?", [$broadcastId]);
        foreach ($recipients as $recipient) {
            $lastId = (int) $recipient['id'];
            $this->database->execute(
                "INSERT IGNORE INTO broadcast_deliveries (broadcast_id, user_id, status) VALUES (?, ?, 'pending')",
                [$broadcastId, $lastId]
            );
            // Mark before the external call. If the process dies after Telegram accepts
            // the message, a retry skips this uncertain delivery instead of duplicating it.
            $claimed = $this->database->execute(
                "UPDATE broadcast_deliveries SET status = 'sending', attempt_count = attempt_count + 1, reserved_at = CURRENT_TIMESTAMP WHERE broadcast_id = ? AND user_id = ? AND status = 'pending'",
                [$broadcastId, $lastId]
            )->rowCount();
            if ($claimed !== 1) {
                continue;
            }
            try {
                $this->telegram->call('sendMessage', ['chat_id' => $recipient['telegram_id'], 'text' => $recipient['language'] === 'en' ? $broadcast['message_en'] : $broadcast['message_fa']]);
                $this->database->execute("UPDATE broadcast_deliveries SET status = 'sent', sent_at = CURRENT_TIMESTAMP, reserved_at = NULL WHERE broadcast_id = ? AND user_id = ? AND status = 'sending'", [$broadcastId, $lastId]);
                $sent++;
            } catch (\Throwable $exception) {
                $errorCode = $exception instanceof AppException ? $exception->safeCode : 'telegram_delivery_failed';
                $this->database->execute("UPDATE broadcast_deliveries SET status = 'failed', last_error_code = ?, reserved_at = NULL WHERE broadcast_id = ? AND user_id = ? AND status = 'sending'", [$errorCode, $broadcastId, $lastId]);
                $failed++;
            }
            usleep(40_000);
        }
        $totals = $this->database->one("SELECT SUM(status = 'sent') AS sent_count, SUM(status = 'failed') AS failed_count FROM broadcast_deliveries WHERE broadcast_id = ?", [$broadcastId]);
        $this->database->execute('UPDATE admin_broadcasts SET sent_count = ?, failed_count = ? WHERE id = ?', [(int) ($totals['sent_count'] ?? 0), (int) ($totals['failed_count'] ?? 0), $broadcastId]);
        if (count($recipients) === 20) {
            $this->queue->dispatch('admin.broadcast', $adminId, null, ['broadcast_id' => $broadcastId, 'after_user_id' => $lastId], 'broadcast:' . $broadcastId . ':' . $lastId);
        } else {
            $this->database->execute("UPDATE admin_broadcasts SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?", [$broadcastId]);
        }
        $context->progress(99, 'broadcast_batch_completed');
        return ['broadcast_id' => $broadcastId, 'sent' => $sent, 'failed' => $failed, 'last_user_id' => $lastId, 'continued' => count($recipients) === 20];
    }
}
