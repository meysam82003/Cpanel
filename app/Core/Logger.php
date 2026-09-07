<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Logger
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }

        $entry = [
            'time' => gmdate('c'),
            'level' => strtolower($level),
            'message' => SecretMasker::mask($message),
            'context' => SecretMasker::mask($context),
        ];
        @file_put_contents(
            $this->directory . '/' . gmdate('Y-m-d') . '.log',
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /** @param array<string, mixed> $context */
    public function error(Throwable $error, array $context = []): void
    {
        $this->log('error', $error->getMessage(), $context + [
            'exception' => $error::class,
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ]);
    }
}

