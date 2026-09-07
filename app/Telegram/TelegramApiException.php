<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Core\AppException;

final class TelegramApiException extends AppException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $message, string $safeCode = 'telegram_api_error', array $context = [], int $status = 502)
    {
        parent::__construct($message, $status, $safeCode, $context, 'errors.telegram');
    }
}

