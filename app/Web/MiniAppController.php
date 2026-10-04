<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\Config;
use App\Core\Env;
use App\Http\UrlContext;

/**
 * Serves the Mini App shell with its runtime configuration injected, so the
 * front end never guesses the install folder or routing style, and every
 * asset URL is versioned to defeat stale Telegram WebView caches.
 */
final class MiniAppController
{
    private const ASSET_TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'txt' => 'text/plain; charset=utf-8',
    ];

    public function __construct(private readonly string $root, private readonly UrlContext $context)
    {
    }

    public function shell(): never
    {
        $version = Config::packageVersion();
        $assets = $this->context->publicPath . '/miniapp';
        $asset = static fn (string $file): string => View::e($assets . '/' . $file . '?v=' . rawurlencode($version));
        $config = [
            'base' => $this->context->basePath,
            'entry' => $this->context->entryPath,
            'mode' => $this->context->mode,
            'assets' => $assets,
            'version' => $version,
            'bot' => (string) Env::get('TELEGRAM_BOT_USERNAME', ''),
            'https' => $this->context->isHttps(),
        ];
        $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,user-scalable=no">'
            . '<meta name="color-scheme" content="light dark"><meta name="theme-color" content="#0c8576"><meta name="robots" content="noindex,nofollow">'
            . '<title>Telegram cPanel Manager</title>'
            . '<link rel="stylesheet" href="' . $asset('styles.css') . '"><link rel="stylesheet" href="' . $asset('deployment.css') . '"><link rel="stylesheet" href="' . $asset('backup.css') . '">'
            . '<script type="application/json" id="tcpm-config">' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) . '</script>'
            . '<script src="' . $asset('telegram-bridge.js') . '"></script>'
            . '</head><body>'
            . '<a class="skip-link" href="#content">Skip to content</a>'
            . '<div id="app" class="app-shell" aria-busy="true">'
            . '<header class="app-header"><div class="brand" aria-label="Telegram cPanel Manager"><span class="brand-mark">C</span><span id="page-title">cPanel</span></div>'
            . '<div class="header-actions"><label class="host-picker"><span id="host-label">Host</span><select id="host-select" aria-label="Active host"></select></label>'
            . '<button id="context-help" class="icon-button" type="button" aria-label="Help">?</button></div></header>'
            . '<main id="content" tabindex="-1"><section class="boot-card" id="boot-card" aria-live="polite"><div class="loader" aria-hidden="true"></div><h1>در حال اتصال امن…</h1><p>Securely connecting to Telegram…</p></section></main>'
            . '<nav id="bottom-nav" class="bottom-nav" aria-label="Main navigation"></nav></div>'
            . '<div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="true"></div>'
            . '<dialog id="dialog" class="sheet"><form id="dialog-form" method="dialog"><header><h2 id="dialog-title"></h2><button class="icon-button" value="cancel" aria-label="Close" type="button" data-dialog-close>×</button></header><div id="dialog-body" class="sheet-body"></div><footer id="dialog-actions"></footer></form></dialog>'
            . '<noscript><p style="padding:20px">JavaScript is required.</p></noscript>'
            . '<script type="module" src="' . $asset('app.js') . '"></script>'
            . '</body></html>';

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; worker-src 'self' blob:; frame-ancestors https://web.telegram.org https://*.telegram.org https://telegram.org; base-uri 'self'; form-action 'self'; object-src 'none'");
        if ($this->context->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        echo $html;
        exit;
    }

    /** Serves a Mini App asset through PHP when the web server did not serve it directly. */
    public function asset(string $relative): never
    {
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $base = realpath($this->root . '/public/miniapp');
        $path = $base === false ? false : realpath($base . '/' . $relative);
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path) || !isset(self::ASSET_TYPES[$extension]) || str_contains($relative, '..')) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            exit;
        }
        $etag = '"' . substr(sha1((string) filemtime($path) . '|' . (string) filesize($path) . '|' . Config::packageVersion()), 0, 20) . '"';
        header('Content-Type: ' . self::ASSET_TYPES[$extension]);
        header('X-Content-Type-Options: nosniff');
        header('ETag: ' . $etag);
        header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
