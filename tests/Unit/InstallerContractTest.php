<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Installer\InstallerService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class InstallerContractTest extends TestCase
{
    public function testExactlyFiveUserSuppliedFieldsAreRendered(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/install.php');
        self::assertIsString($source);
        preg_match_all('/<input\b[^>]*\bname="([^"]+)"/i', $source, $matches);
        $fields = array_values(array_filter($matches[1], static fn (string $name): bool => $name !== '_csrf'));
        sort($fields);
        self::assertSame(['bot_token', 'db_name', 'db_password', 'db_username', 'super_admin_id'], $fields);
    }

    public function testInputValidationAcceptsOnlyWellFormedFiveValues(): void
    {
        $installer = new InstallerService(dirname(__DIR__, 2));
        $method = new ReflectionMethod($installer, 'validateInput');
        $result = $method->invoke($installer, [
            'bot_token' => '123456:' . str_repeat('A', 32),
            'super_admin_id' => '123456789',
            'db_username' => 'panel_user',
            'db_password' => 'correct horse battery staple',
            'db_name' => 'panel_database',
        ]);
        self::assertSame(123456789, $result['super_admin_id']);
        self::assertSame('panel_database', $result['db_name']);
    }

    public function testPublicUrlRequiresHttpsAndValidPort(): void
    {
        $installer = new InstallerService(dirname(__DIR__, 2));
        $method = new ReflectionMethod($installer, 'detectUrls');
        $result = $method->invoke($installer, ['HTTPS' => 'on', 'SERVER_PORT' => '443', 'HTTP_HOST' => 'panel.example.com', 'REQUEST_URI' => '/manager/install']);
        self::assertSame('https://panel.example.com/manager', $result['app_url']);
        $this->expectException(RuntimeException::class);
        $method->invoke($installer, ['HTTPS' => 'on', 'HTTP_HOST' => 'panel.example.com:99999', 'REQUEST_URI' => '/install']);
    }
}
