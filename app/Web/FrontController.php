<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\AppException;
use App\Core\Config;
use App\Core\Container;
use App\Core\Env;
use App\Core\Logger;
use App\Http\Application;
use App\Http\Request;
use App\Http\Response;
use App\Http\UrlContext;
use App\Installer\InstallerService;
use App\Support\HtaccessGuard;

/**
 * Single entry point for every web request (index.php at the project root,
 * or public/index.php when public/ is the document root).
 */
final class FrontController
{
    public function __construct(private readonly string $root, private readonly bool $publicEntry = false)
    {
    }

    public function run(): never
    {
        date_default_timezone_set('UTC');
        HtaccessGuard::ensure($this->root);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        try {
            $context = UrlContext::fromGlobals($_SERVER, $_GET, $this->root, $this->publicEntry);
        } catch (AppException $exception) {
            $this->json(['ok' => false, 'error' => ['code' => $exception->safeCode, 'message' => $exception->getMessage()]], $exception->httpStatus);
        }
        $route = $context->routePath;
        $normalized = rtrim($route, '/') === '' ? '/' : rtrim($route, '/');

        try {
            if ($normalized === '/_probe') {
                $this->json(['ok' => true, 'app' => 'telegram-cpanel-manager', 'probe' => InstallerService::probeId($this->root), 'mode' => $context->mode]);
            }
            if (preg_match('#^/(?:miniapp|app)/((?:vendor/[a-z0-9_-]+/)?[A-Za-z0-9_.-]+\.[a-z0-9]+)$#', $route, $match) && !str_ends_with($match[1], '.php')) {
                (new MiniAppController($this->root, $context))->asset($match[1]);
            }

            $installed = is_file($this->root . '/storage/installed.lock') && is_file($this->root . '/.env');
            if (!$installed) {
                $this->beforeInstall($context, $normalized, $method);
            }

            Env::load($this->root . '/.env');
            switch ($normalized) {
                case '/':
                case '/index.php':
                    $this->landing($context);
                    // no break: landing() never returns
                case '/install':
                case '/install.php':
                case '/public/install.php':
                case '/install/detect-admin':
                    (new InstallerController($this->root, $context))->locked();
                    // no break
                case '/setup':
                case '/setup.php':
                case '/telegram-setup':
                case '/telegram-setup.php':
                case '/public/telegram-setup.php':
                    (new SetupController($this->root, $context))->handle($method);
                    // no break
                case '/miniapp':
                case '/app':
                case '/miniapp/index.html':
                case '/public/miniapp/index.html':
                    (new MiniAppController($this->root, $context))->shell();
            }
            if (str_starts_with($normalized, '/miniapp/') || str_starts_with($normalized, '/app/')) {
                (new MiniAppController($this->root, $context))->shell();
            }

            $this->securityHeaders($context);
            (new Application(new Container($this->root)))->handle(Request::capture($route))->send();
        } catch (AppException $exception) {
            $this->json(['ok' => false, 'error' => ['code' => $exception->safeCode, 'message' => $exception->getMessage(), 'message_fa' => 'درخواست کامل نشد. ورودی و راهنمای صفحه را بررسی کنید.', 'message_en' => 'The request could not be completed. Check the input and contextual help.', 'request_id' => bin2hex(random_bytes(16))]], $exception->httpStatus);
        } catch (\Throwable $exception) {
            $requestId = bin2hex(random_bytes(16));
            try {
                (new Logger($this->root . '/storage/logs'))->error($exception, ['request_id' => $requestId, 'route' => substr($normalized, 0, 64)]);
            } catch (\Throwable) {
            }
            $this->json(['ok' => false, 'error' => ['code' => 'bootstrap_failed', 'message_fa' => 'راه‌اندازی برنامه کامل نشد. صفحه Setup و لاگ storage/logs را بررسی کنید.', 'message_en' => 'Application bootstrap failed. Check the Setup page and storage/logs.', 'request_id' => $requestId]], 500);
        }
    }

    private function beforeInstall(UrlContext $context, string $route, string $method): never
    {
        if ($route === '/install/detect-admin' && $method === 'POST') {
            (new InstallerController($this->root, $context))->detectAdmin();
        }
        if (preg_match('#^/(?:api|webhook|cron|download)/#', $route . '/')) {
            $this->json(['ok' => false, 'error' => ['code' => 'not_installed', 'message_fa' => 'ابتدا نصب‌کننده وب را اجرا کنید.', 'message_en' => 'Run the web installer first.']], 503);
        }
        if ($route === '/health') {
            $this->json(['ok' => true, 'data' => ['status' => 'not_installed', 'version' => Config::packageVersion()]], 503);
        }
        // The installer is served on whatever address was opened, so it works
        // before anyone knows whether URL rewriting is available.
        (new InstallerController($this->root, $context))->handle($method);
    }

    private function landing(UrlContext $context): never
    {
        $bot = (string) Env::get('TELEGRAM_BOT_USERNAME', '');
        $configured = InstallerService::configuredUrls();
        $moved = rtrim($configured->baseUrl, '/') !== rtrim($context->baseUrl(), '/');
        $body = '<section class="card"><div class="brand"><div class="logo">C</div><div><h1>Telegram cPanel Manager</h1><div class="muted ltr">v' . View::e(Config::packageVersion()) . '</div></div></div>'
            . '<div class="notice good">✅ برنامه نصب شده و فعال است. مدیریت از داخل ربات تلگرام انجام می‌شود.<div class="ltr">Installed and running. Management happens inside the Telegram bot.</div></div>'
            . ($moved ? '<div class="notice warn">آدرس/پوشه برنامه تغییر کرده است؛ Setup را باز و «تعمیر خودکار» را بزنید. · The address changed; open Setup and run Repair.</div>' : '')
            . '<div class="actions">' . ($bot !== '' ? '<a class="button" href="https://t.me/' . View::e($bot) . '" target="_blank" rel="noopener">@' . View::e($bot) . '</a>' : '')
            . '<a class="button ghost" href="' . View::e($context->path('/setup', [], UrlContext::MODE_QUERY)) . '">Setup &amp; diagnostics</a></div></section>';
        View::send(View::page('Telegram cPanel Manager', $body), 200, '', $context->isHttps());
    }

    private function securityHeaders(UrlContext $context): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        if ($context->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): never
    {
        Response::json($payload, $status)->send();
    }
}
