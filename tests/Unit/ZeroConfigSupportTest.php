<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Env;
use App\Core\EnvFile;
use App\Installer\InstallerService;
use App\Support\PhpBinary;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramSetupService;
use PHPUnit\Framework\TestCase;

final class ZeroConfigSupportTest extends TestCase
{
    public function testFindsCliPhpNextToWebSapiBinaries(): void
    {
        $exists = static fn (array $paths): \Closure => static fn (string $path): bool => in_array($path, $paths, true);
        self::assertSame('/opt/cpanel/ea-php82/root/usr/bin/php', PhpBinary::cli('/opt/cpanel/ea-php82/root/usr/sbin/php-fpm', '8.2', $exists(['/opt/cpanel/ea-php82/root/usr/bin/php', '/usr/local/bin/php'])));
        self::assertSame('/usr/local/lsws/lsphp83/bin/php', PhpBinary::cli('/usr/local/lsws/lsphp83/bin/lsphp', '8.3', $exists(['/usr/local/lsws/lsphp83/bin/php'])));
        self::assertSame('/opt/alt/php84/usr/bin/php', PhpBinary::cli('', '8.4', $exists(['/opt/alt/php84/usr/bin/php', '/usr/bin/php'])));
        self::assertSame('/usr/bin/php8.3', PhpBinary::cli('/usr/bin/php8.3', '8.3', $exists(['/usr/bin/php8.3'])));
        self::assertSame('/usr/local/bin/php', PhpBinary::cli('/nonexistent/php-cgi', '8.2', $exists([])));
    }

    public function testEnvFileUpdatesKeysInPlaceAndRejectsLineBreaks(): void
    {
        $directory = sys_get_temp_dir() . '/tcpm-env-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $path = $directory . '/.env';
        try {
            EnvFile::write($path, ['APP_URL' => 'https://old.test/a', 'APP_KEY' => 'base64:x']);
            EnvFile::update($path, ['APP_URL' => 'https://new.test/b', 'APP_URL_MODE' => 'query']);
            $content = (string) file_get_contents($path);
            self::assertStringContainsString("APP_URL=https://new.test/b\nAPP_KEY=base64:x\nAPP_URL_MODE=query", str_replace(PHP_EOL, "\n", $content));
            self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
            Env::load($path);
            self::assertSame('query', Env::get('APP_URL_MODE'));
            $this->expectException(\RuntimeException::class);
            EnvFile::update($path, ['APP_URL' => "x\nINJECTED=1"]);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testDetectsCpanelAccountPrefixFromInstallPath(): void
    {
        self::assertSame(['user' => 'meysam', 'prefix' => 'meysam_'], (new InstallerService('/home/meysam/public_html/bot'))->cpanelAccount());
        self::assertSame(['user' => 'acme', 'prefix' => 'acme_'], (new InstallerService('/home3/acme/sub.example.com'))->cpanelAccount());
    }

    public function testTelegramClientValidatesProxyAndRelaySettings(): void
    {
        self::assertSame('socks5h://127.0.0.1:1080', TelegramClient::normalizeProxy(' socks5h://127.0.0.1:1080 '));
        self::assertNull(TelegramClient::normalizeProxy(''));
        self::assertSame('https://relay.example.com/tg', TelegramClient::normalizeApiBase('https://relay.example.com/tg/'));
        self::assertSame(TelegramClient::DEFAULT_API_BASE, TelegramClient::normalizeApiBase(null));
        foreach ([static fn () => TelegramClient::normalizeProxy('ftp://x:1'), static fn () => TelegramClient::normalizeProxy('socks5://host'), static fn () => TelegramClient::normalizeApiBase('http://insecure.test')] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid Telegram transport setting was accepted.');
            } catch (TelegramApiException) {
                self::assertTrue(true);
            }
        }
    }

    public function testBotCommandsAreLocalizedAndConsistent(): void
    {
        $default = array_column(TelegramSetupService::commands(), 'command');
        self::assertSame($default, array_column(TelegramSetupService::commands('fa'), 'command'));
        self::assertSame($default, array_column(TelegramSetupService::commands('en'), 'command'));
        self::assertContains('start', $default);
        self::assertSame('تنظیمات', TelegramSetupService::commands('fa')[5]['description']);
    }
}
