<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testAuthenticatedEncryptionRoundTripAndRandomNonce(): void
    {
        $crypto = new Crypto([1 => str_repeat('a', 32)], 1);
        $first = $crypto->encrypt('sensitive value', 'tenant:7');
        $second = $crypto->encrypt('sensitive value', 'tenant:7');
        self::assertNotSame($first, $second);
        self::assertSame('sensitive value', $crypto->decrypt($first, 'tenant:7'));
    }

    public function testCiphertextIsBoundToContextAndAuthenticated(): void
    {
        $crypto = new Crypto([1 => str_repeat('b', 32)], 1);
        $encrypted = $crypto->encrypt('token', 'account:1');
        $this->assertAppError('decryption_failed', fn (): string => $crypto->decrypt($encrypted, 'account:2'));
        $tampered = substr($encrypted, 0, -1) . ($encrypted[-1] === 'A' ? 'B' : 'A');
        $this->assertAppError('decryption_failed', fn (): string => $crypto->decrypt($tampered, 'account:1'));
    }

    public function testOldKeyVersionRemainsDecryptableAfterRotation(): void
    {
        $old = str_repeat('o', 32);
        $new = str_repeat('n', 32);
        $envelope = (new Crypto([1 => $old], 1))->encrypt('rotated secret', 'rotation');
        self::assertSame('rotated secret', (new Crypto([1 => $old, 2 => $new], 2))->decrypt($envelope, 'rotation'));
    }

    public function testDecodeKeyRequiresExactlyThirtyTwoBytes(): void
    {
        self::assertSame(str_repeat('k', 32), Crypto::decodeKey('base64:' . base64_encode(str_repeat('k', 32))));
        $this->assertAppError('invalid_encryption_key', fn (): string => Crypto::decodeKey(base64_encode('short')));
    }

    private function assertAppError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected AppException was not thrown.');
        } catch (AppException $exception) {
            self::assertSame($code, $exception->safeCode);
        }
    }
}
