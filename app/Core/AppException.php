<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class AppException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly string $safeCode = 'operation_failed',
        public readonly array $context = [],
        public readonly ?string $helpSlug = null,
    ) {
        parent::__construct($message);
    }
}

