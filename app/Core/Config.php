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
            'version' => self::packageVersion(),
            'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
            default => $default,
        };
    }

    public static function packageVersion(): string
    {
        $path = dirname(__DIR__, 2) . '/VERSION';
        $version = is_file($path) && is_readable($path) ? trim((string) file_get_contents($path)) : '';
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1) {
            return $version;
        }
        return (string) Env::get('APP_VERSION', '1.0.0');
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
