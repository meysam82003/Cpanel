<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    public static function app(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'env' => Env::get('APP_ENV', 'production'),
            'debug' => Env::bool('APP_DEBUG'),
            'url' => rtrim((string) Env::get('APP_URL', ''), '/'),
            'version' => Env::get('APP_VERSION', '1.0.0'),
            'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
            default => $default,
        };
    }

    /** @return array<string, int|string> */
    public static function database(): array
    {
        return [
            'host' => Env::get('DB_HOST', 'localhost'),
            'port' => Env::int('DB_PORT', 3306),
            'name' => Env::require('DB_NAME'),
            'user' => Env::require('DB_USER'),
            'password' => self::decodeDatabasePassword(),
            'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
        ];
    }

    private static function decodeDatabasePassword(): string
    {
        $decoded = base64_decode(Env::require('DB_PASSWORD_B64'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Database password encoding is invalid.');
        }
        return $decoded;
    }
}
