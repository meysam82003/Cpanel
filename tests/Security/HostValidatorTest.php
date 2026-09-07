<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\AppException;
use App\Security\HostValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostValidatorTest extends TestCase
{
    public function testAcceptsPublicLiteralAndPinsItsAddress(): void
    {
        $result = (new HostValidator(false, [2083, 443]))->validate('https://8.8.8.8:2083');
        self::assertSame('8.8.8.8', $result['host']);
        self::assertSame(['8.8.8.8'], $result['ips']);
        self::assertSame('https://8.8.8.8:2083', $result['base_url']);
    }

    public function testAcceptsPublicIpv6Literal(): void
    {
        $result = (new HostValidator(false, [2083]))->validate('https://[2001:4860:4860::8888]:2083');
        self::assertSame('2001:4860:4860::8888', $result['host']);
        self::assertSame('https://[2001:4860:4860::8888]:2083', $result['base_url']);
    }

    #[DataProvider('alwaysBlockedTargets')]
    public function testBlocksLoopbackLinkLocalMetadataAndReservedEvenWhenPrivateOptInIsEnabled(string $url): void
    {
        foreach ([false, true] as $allowPrivate) {
            try {
                (new HostValidator($allowPrivate, [2083]))->validate($url);
                self::fail('SSRF target was accepted: ' . $url);
            } catch (AppException $exception) {
                self::assertContains($exception->safeCode, ['ssrf_target_blocked', 'invalid_cpanel_host']);
            }
        }
    }

    public static function alwaysBlockedTargets(): array
    {
        return [
            ['https://127.0.0.1:2083'],
            ['https://169.254.169.254:2083'],
            ['https://0.0.0.0:2083'],
            ['https://[::1]:2083'],
            ['https://[fe80::1]:2083'],
            ['https://metadata.google.internal:2083'],
        ];
    }

    public function testPrivateOptInAllowsOnlyRfc1918AndUniqueLocalRanges(): void
    {
        $validator = new HostValidator(true, [2083]);
        self::assertSame(['10.25.1.9'], $validator->validate('https://10.25.1.9:2083')['ips']);
        self::assertSame(['192.168.50.2'], $validator->validate('https://192.168.50.2:2083')['ips']);
        self::assertSame(['fd00::123'], $validator->validate('https://[fd00::123]:2083')['ips']);
    }

    #[DataProvider('malformedUrls')]
    public function testRejectsUnsafeUrlShapes(string $url): void
    {
        $this->expectException(AppException::class);
        (new HostValidator(false, [2083]))->validate($url);
    }

    public static function malformedUrls(): array
    {
        return [
            ['http://8.8.8.8:2083'],
            ['https://user:pass@8.8.8.8:2083'],
            ['https://8.8.8.8:22'],
            ['https://8.8.8.8:2083/evil'],
            ['https://8.8.8.8:2083?next=x'],
        ];
    }
}
