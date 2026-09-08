<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class SubdirectoryInstallContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $_GET = [];
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        parent::tearDown();
    }

    public function testRequestPathStripsConfiguredApplicationSubdirectory(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/cpanel-telegram/webhook/abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        $request = Request::capture('https://panel.example.com/cpanel-telegram');

        self::assertSame('/webhook/abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN', $request->path);
    }

    public function testRootInstallRequestPathRemainsUnchanged(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/health?probe=1',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        $request = Request::capture('https://panel.example.com');

        self::assertSame('/api/v1/health', $request->path);
    }

    public function testMiniAppAssetsAreRelativeToMiniAppDirectory(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/public/miniapp/index.html');
        self::assertIsString($html);
        self::assertStringContainsString('href="./styles.css"', $html);
        self::assertStringContainsString('src="./app.js"', $html);
        self::assertStringNotContainsString('href="/miniapp/', $html);
        self::assertStringNotContainsString('src="/miniapp/', $html);
    }

    public function testMiniAppApiResolvesRequestsAgainstInstallBasePath(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/miniapp/api.js');
        self::assertIsString($source);
        self::assertStringContainsString('const APP_BASE_PATH = detectAppBasePath();', $source);
        self::assertStringContainsString('fetch(resolveAppPath(path)', $source);
        self::assertStringContainsString("xhr.open('POST', resolveAppPath(path), true);", $source);
    }
}
