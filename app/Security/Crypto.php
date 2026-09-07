<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AppException;

final class Crypto
{
    /** @param array<int, string> $keys */
    public function __construct(
        private readonly array $keys,
        private readonly int $currentVersion,
    ) {
        foreach ($keys as $version => $key) {
            if ($version < 1 || strlen($key) !== 32) {
                throw new AppException('Invalid encryption key configuration.', 500, 'encryption_configuration_error');
            }
        }
        if (!isset($keys[$currentVersion])) {
            throw new AppException('Current encryption key is unavailable.', 500, 'encryption_key_unavailable');
        }
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $version = $this->currentVersion;
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->keys[$version],
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad($context, $version),
            16
        );
        if ($ciphertext === false) {
            throw new AppException('Sensitive data could not be encrypted.', 500, 'encryption_failed');
        }

        return self::base64UrlEncode(json_encode([
            'v' => $version,
            'n' => self::base64UrlEncode($nonce),
            't' => self::base64UrlEncode($tag),
            'c' => self::base64UrlEncode($ciphertext),
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $envelope, string $context): string
    {
        try {
            $payload = json_decode(self::base64UrlDecode($envelope), true, 8, JSON_THROW_ON_ERROR);
            $version = (int) ($payload['v'] ?? 0);
            $key = $this->keys[$version] ?? null;
            if ($key === null) {
                throw new AppException('The encryption key version is unavailable.', 500, 'encryption_key_unavailable');
            }
            $plaintext = openssl_decrypt(
                self::base64UrlDecode((string) $payload['c']),
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                self::base64UrlDecode((string) $payload['n']),
                self::base64UrlDecode((string) $payload['t']),
                $this->aad($context, $version)
            );
        } catch (\Throwable $exception) {
            throw $exception instanceof AppException
                ? $exception
                : new AppException('Sensitive data could not be decrypted.', 500, 'decryption_failed');
        }
        if ($plaintext === false) {
            throw new AppException('Encrypted data authentication failed.', 500, 'decryption_failed');
        }
        return $plaintext;
    }

    public static function decodeKey(string $value): string
    {
        $encoded = str_starts_with($value, 'base64:') ? substr($value, 7) : $value;
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new AppException('Encryption key must decode to exactly 32 bytes.', 500, 'invalid_encryption_key');
        }
        return $decoded;
    }

    private function aad(string $context, int $version): string
    {
        return 'telegram-cpanel-manager|v' . $version . '|' . $context;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new AppException('Invalid encrypted payload encoding.', 500, 'invalid_encrypted_payload');
        }
        return $decoded;
    }
}

