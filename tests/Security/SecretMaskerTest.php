<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\SecretMasker;
use PHPUnit\Framework\TestCase;

final class SecretMaskerTest extends TestCase
{
    public function testMasksNestedSensitiveValuesAndAuthorizationStrings(): void
    {
        $masked = SecretMasker::mask([
            'username' => 'alice',
            'api_token' => 'cpanel-secret',
            'nested' => ['csrf_token' => 'csrf-secret', 'payload_encrypted' => 'ciphertext'],
            'message' => 'Authorization: cpanel alice:ABC_def-123456789 and Bearer abcdefghijklmnopqrstuvwxyz.123456',
        ]);
        self::assertSame('alice', $masked['username']);
        self::assertSame('[REDACTED]', $masked['api_token']);
        self::assertSame('[REDACTED]', $masked['nested']['csrf_token']);
        self::assertSame('[REDACTED]', $masked['nested']['payload_encrypted']);
        self::assertStringNotContainsString('ABC_def', $masked['message']);
        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $masked['message']);
    }

    public function testMasksTelegramBotTokenShape(): void
    {
        $value = SecretMasker::mask('failure for 123456789:' . str_repeat('A', 35));
        self::assertSame('failure for [TELEGRAM_TOKEN_REDACTED]', $value);
    }
}
