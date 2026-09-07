<?php

declare(strict_types=1);

namespace App\Cpanel;

use App\Core\AppException;

final class CpanelApiException extends AppException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, string $safeCode, array $context = [], int $status = 502)
    {
        parent::__construct($message, $status, $safeCode, $context, 'hosts.connection-errors');
    }
}

