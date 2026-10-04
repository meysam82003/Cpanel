<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\AppException;
use App\Http\PublicUrl;
use App\Http\UrlContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlContextTest extends TestCase
{
    /** @return iterable<string,array{array<string,string>,array<string,string>,string,string,string,string}> */
    public static function requests(): iterable
    {
        $https = ['HTTPS' => 'on', 'HTTP_HOST' => 'example.com'];
        yield 'root rewrite' => [$https + ['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/miniapp/?route=files'], [], '', '/miniapp/', 'pretty', '/public'];
        yield 'subfolder rewrite' => [$https + ['SCRIPT_NAME' => '/cpanel-bot/index.php', 'REQUEST_URI' => '/cpanel-bot/api/v1/dashboard'], [], '/cpanel-bot', '/api/v1/dashboard', 'pretty', '/cpanel-bot/public'];
        yield 'nested subfolder' => [$https + ['SCRIPT_NAME' => '/a/b/c/index.php', 'REQUEST_URI' => '/a/b/c/webhook/abc'], [], '/a/b/c', '/webhook/abc', 'pretty', '/a/b/c/public'];
        yield 'folder index' => [$https + ['SCRIPT_NAME' => '/tools/index.php', 'REQUEST_URI' => '/tools/'], [], '/tools', '/', 'pretty', '/tools/public'];
        yield 'path info' => [$https + ['SCRIPT_NAME' => '/tools/index.php', 'REQUEST_URI' => '/tools/index.php/miniapp/', 'PATH_INFO' => '/miniapp/'], [], '/tools', '/miniapp/', 'pathinfo', '/tools/public'];
        yield 'query routing' => [$https + ['SCRIPT_NAME' => '/tools/index.php', 'REQUEST_URI' => '/tools/index.php?r=%2Fapi%2Fv1%2Fhosts'], ['r' => '/api/v1/hosts'], '/tools', '/api/v1/hosts', 'query', '/tools/public'];
        yield 'fastcgi script name with path info' => [$https + ['SCRIPT_NAME' => '/tools/index.php/miniapp/', 'PATH_INFO' => '/miniapp/', 'REQUEST_URI' => '/tools/index.php/miniapp/'], [], '/tools', '/miniapp/', 'pathinfo', '/tools/public'];
        yield 'userdir' => [$https + ['SCRIPT_NAME' => '/~bob/panel/index.php', 'REQUEST_URI' => '/~bob/panel/setup'], [], '/~bob/panel', '/setup', 'pretty', '/~bob/panel/public'];
        yield 'encoded spaces' => [$https + ['SCRIPT_NAME' => '/my tools/index.php', 'REQUEST_URI' => '/my%20tools/miniapp/'], [], '/my tools', '/miniapp/', 'pretty', '/my tools/public'];
    }

    /**
     * @param array<string,string> $server
     * @param array<string,string> $query
     */
    #[DataProvider('requests')]
    public function testDetectsInstallFolderRouteAndModeWithoutConfiguration(array $server, array $query, string $base, string $route, string $mode, string $public): void
    {
        $context = UrlContext::fromGlobals($server, $query, sys_get_temp_dir());
        self::assertSame($base, $context->basePath);
        self::assertSame($route, $context->routePath);
        self::assertSame($mode, $context->mode);
        self::assertSame($public, $context->publicPath);
        self::assertSame('https://example.com' . $base, $context->baseUrl());
    }

    public function testPublicDocumentRootEntry(): void
    {
        $root = sys_get_temp_dir() . '/tcpm-url-' . bin2hex(random_bytes(4));
        mkdir($root . '/public', 0700, true);
        try {
            $context = UrlContext::fromGlobals(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => $root . '/public', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/miniapp/'], [], $root, true);
            self::assertSame('', $context->basePath);
            self::assertSame('', $context->publicPath);
            self::assertSame('/miniapp/', $context->routePath);

            $direct = UrlContext::fromGlobals(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'DOCUMENT_ROOT' => dirname($root), 'SCRIPT_NAME' => '/' . basename($root) . '/public/index.php', 'REQUEST_URI' => '/' . basename($root) . '/public/install.php'], [], $root, true);
            self::assertSame('/' . basename($root), $direct->basePath);
            self::assertSame('/public/install.php', $direct->routePath);
        } finally {
            rmdir($root . '/public');
            rmdir($root);
        }
    }

    public function testDetectsHttpsBehindProxiesAndRejectsHostileHosts(): void
    {
        $base = ['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/'];
        self::assertSame('https', UrlContext::fromGlobals($base + ['HTTP_HOST' => 'a.test', 'HTTP_X_FORWARDED_PROTO' => 'https'], [], '/')->scheme);
        self::assertSame('https', UrlContext::fromGlobals($base + ['HTTP_HOST' => 'a.test', 'HTTP_CF_VISITOR' => '{"scheme":"https"}'], [], '/')->scheme);
        self::assertSame('http', UrlContext::fromGlobals($base + ['HTTP_HOST' => 'a.test', 'HTTPS' => 'off'], [], '/')->scheme);
        self::assertSame('localhost', UrlContext::fromGlobals($base + ['HTTP_HOST' => 'evil.test/<script>'], [], '/')->host);
        self::assertSame('panel.test:8443', UrlContext::fromGlobals($base + ['HTTP_HOST' => 'Panel.Test:8443'], [], '/')->host);
    }

    public function testRejectsEncodedSeparatorsAndControlCharacters(): void
    {
        foreach (['/sub/a%2fb', '/sub/a%00', '/sub/a%5Cb'] as $uri) {
            try {
                UrlContext::fromGlobals(['HTTP_HOST' => 'a.test', 'SCRIPT_NAME' => '/sub/index.php', 'REQUEST_URI' => $uri], [], '/');
                self::fail('Accepted ' . $uri);
            } catch (AppException $exception) {
                self::assertSame('invalid_request_path', $exception->safeCode);
            }
        }
    }

    public function testPublicUrlsForEveryRoutingMode(): void
    {
        self::assertSame('https://ex.test/bot/miniapp/?route=files&host=2', (new PublicUrl('https://ex.test/bot/', 'pretty'))->miniApp(['route' => 'files', 'host' => 2]));
        self::assertSame('https://ex.test/bot/index.php/webhook/abc', (new PublicUrl('https://ex.test/bot', 'pathinfo'))->webhook('abc'));
        self::assertSame('https://ex.test/bot/index.php?r=%2Fwebhook%2Fabc', (new PublicUrl('https://ex.test/bot', 'query'))->webhook('abc'));
        self::assertSame('https://ex.test/index.php?r=%2Fminiapp%2F&route=help', (new PublicUrl('https://ex.test', 'query'))->miniApp(['route' => 'help', 'slug' => null]));
        self::assertSame('https://ex.test/download/a%2Bb', (new PublicUrl('https://ex.test', 'unknown'))->download('a+b'));
        self::assertFalse((new PublicUrl('http://ex.test'))->isHttps());
    }
}
