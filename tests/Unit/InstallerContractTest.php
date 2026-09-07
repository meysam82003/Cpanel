<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Installer\InstallerService;
use App\Installer\InstallerException;
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

    public function testPublicInstallerNeverRendersRawExceptionMessages(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/install.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('$error = $exception->getMessage()', $source);
        self::assertStringContainsString("'message_fa'", $source);
        self::assertStringContainsString("'message_en'", $source);
        self::assertStringContainsString("'request_id'", $source);
    }

    public function testInstallLockRejectsConcurrentExecution(): void
    {
        $root = sys_get_temp_dir() . '/tcm-installer-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $first = new InstallerService($root);
        $second = new InstallerService($root);
        $acquire = new ReflectionMethod($first, 'acquireInstallLock');
        $release = new ReflectionMethod($first, 'releaseInstallLock');
        $lock = $acquire->invoke($first);
        try {
            $acquire->invoke($second);
            self::fail('Concurrent installer execution acquired the same lock.');
        } catch (InstallerException $exception) {
            self::assertSame('installer_busy', $exception->safeCode);
            self::assertNotSame('', $exception->messageFa);
            self::assertNotSame('', $exception->messageEn);
        } finally {
            $release->invoke($first, $lock);
            unlink($root . '/storage/locks/installer.lock');
            rmdir($root . '/storage/locks');
            rmdir($root . '/storage');
            rmdir($root);
        }
    }
}
