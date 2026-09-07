<?php

declare(strict_types=1);

namespace App\Installer;

use RuntimeException;
use Throwable;

final class InstallerException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly string $messageFa,
        public readonly string $messageEn,
        ?Throwable $previous = null,
    ) {
        parent::__construct($messageEn, 0, $previous);
    }
}
