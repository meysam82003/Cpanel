<?php

declare(strict_types=1);

namespace App\Core;

final class SecretMasker
{
    private const SENSITIVE_KEYS = [
        'token', 'api_token', 'password', 'secret', 'authorization', 'cookie',
        'encryption_key', 'app_key', 'database_password', 'bot_token', 'init_data',
        'initdata', 'csrf', 'csrf_token', 'session_token', 'callback_secret',
        'webhook_secret', 'encrypted_password', 'encrypted_token', 'payload_encrypted',
        'result_encrypted', 'private_key', 'client_secret', 'access_token', 'refresh_token',
        'db_password_b64', 'session_secret', 'cron_secret',
    ];

    public static function mask(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $masked = [];
            foreach ($value as $itemKey => $item) {
                $masked[$itemKey] = self::mask($item, is_string($itemKey) ? $itemKey : null);
            }
            return $masked;
        }

        if (is_string($value)) {
            $value = preg_replace('/(?:Authorization:\s*)?cpanel\s+[^:\s]+:[A-Za-z0-9_\-]{8,}/i', 'cpanel [REDACTED]', $value) ?? $value;
            $value = preg_replace('/\b\d{6,12}:[A-Za-z0-9_-]{25,}\b/', '[TELEGRAM_TOKEN_REDACTED]', $value) ?? $value;
            $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]{16,}=*\b/i', 'Bearer [REDACTED]', $value) ?? $value;
            $value = preg_replace('/\b(APP_KEY|TELEGRAM_BOT_TOKEN|DB_PASSWORD(?:_B64)?|ENCRYPTION_KEY_V\d+|WEBHOOK_SECRET|CALLBACK_SECRET|SESSION_SECRET|CRON_SECRET)\s*=\s*[^\s;]+/i', '$1=[REDACTED]', $value) ?? $value;
        }
        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        if (preg_match('/^(?:encryption_key_v\d+|db_password_b64)$/', $key)) {
            return true;
        }
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($key === $sensitive || str_ends_with($key, '_' . $sensitive)) {
                return true;
            }
        }
        return false;
    }
}
