<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Core\AppException;
use App\Core\Database;
use App\Core\Logger;
use Closure;

/**
 * Processes one Telegram update exactly once, for both webhook and long
 * polling delivery. A failing update is recorded and answered with a safe
 * message instead of being thrown back to Telegram: a non-2xx webhook reply
 * makes Telegram retry the same update forever and blocks every later one,
 * which looks like a bot that "does not start".
 */
final class UpdateProcessor
{
    public const PROCESSED = 'processed';
    public const DUPLICATE = 'duplicate';
    public const FAILED = 'failed';
    public const IGNORED = 'ignored';

    /** @param Closure():BotHandler $bot */
    public function __construct(
        private readonly Database $database,
        private readonly Closure $bot,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string,mixed> $update */
    public function process(array $update): string
    {
        $updateId = filter_var($update['update_id'] ?? null, FILTER_VALIDATE_INT);
        if ($updateId === false || $updateId < 0) {
            return self::IGNORED;
        }
        $updateId = (int) $updateId;
        $inserted = $this->database->execute("INSERT IGNORE INTO telegram_updates (update_id, status) VALUES (?, 'processing')", [$updateId])->rowCount();
        if ($inserted === 0) {
            return self::DUPLICATE;
        }
        $handler = ($this->bot)();
        try {
            $handler->handle($update);
            $this->database->execute("UPDATE telegram_updates SET status = 'processed', processed_at = CURRENT_TIMESTAMP WHERE update_id = ?", [$updateId]);
            return self::PROCESSED;
        } catch (\Throwable $exception) {
            $code = $exception instanceof AppException ? $exception->safeCode : 'bot_update_failed';
            $this->logger->error($exception, ['update_id' => $updateId, 'error_code' => $code]);
            try {
                $this->database->execute("UPDATE telegram_updates SET status = 'failed', error_code = ?, processed_at = CURRENT_TIMESTAMP WHERE update_id = ?", [substr($code, 0, 100), $updateId]);
            } catch (\Throwable) {
            }
            $handler->replyFailure($update);
            return self::FAILED;
        }
    }
}
