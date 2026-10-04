<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Installer\InstallerService;
use App\Installer\InstallerException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class InstallerContractTest extends TestCase
{
    public function testExactlyFiveOperatorValuesAreRequired(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Web/InstallerController.php');
        self::assertIsString($source);
        preg_match_all('/<input\b[^>]*\bname="([^"]+)"[^>]*\brequired\b/i', $source, $matches);
        $required = $matches[1];
        sort($required);
        self::assertSame(['bot_token', 'db_name', 'db_password', 'db_username', 'super_admin_id'], $required);
        preg_match_all('/<input\b[^>]*\bname="([^"]+)"/i', $source, $all);
        $optional = array_values(array_diff($all[1], $required, ['_csrf']));
        sort($optional);
        self::assertSame(['db_host', 'telegram_api_url', 'telegram_proxy', 'url_mode'], $optional);
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

    public function testOptionalAdvancedSettingsAreValidated(): void
    {
        $installer = new InstallerService(dirname(__DIR__, 2));
        $method = new ReflectionMethod($installer, 'validateInput');
        $base = ['bot_token' => '123456:' . str_repeat('A', 32), 'super_admin_id' => '1', 'db_username' => 'u', 'db_password' => 'p', 'db_name' => 'd'];
        $result = $method->invoke($installer, $base + ['db_host' => 'mysql.internal:3307', 'telegram_proxy' => 'socks5h://10.0.0.2:1080']);
        self::assertSame('mysql.internal', $result['db_host']);
        self::assertSame(3307, $result['db_port']);
        self::assertSame('socks5h://10.0.0.2:1080', $result['telegram_proxy']);
        $this->expectException(InstallerException::class);
        $method->invoke($installer, $base + ['db_host' => 'bad host!']);
    }

    public function testPublicInstallerNeverRendersRawExceptionMessages(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Web/InstallerController.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('getMessage()', $source);
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
