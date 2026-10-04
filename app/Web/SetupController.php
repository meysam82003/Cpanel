<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\AppException;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Env;
use App\Core\EnvFile;
use App\Http\PublicUrl;
use App\Http\UrlContext;
use App\Installer\InstallerService;
use App\Security\RateLimiter;
use App\Support\PhpBinary;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramSetupService;

/**
 * Status, diagnostics and self-repair after installation. Moving the
 * application to another folder or domain only needs opening this page at the
 * new address and pressing Repair (authenticated with the bot token).
 */
final class SetupController
{
    private const COOKIE = 'tcpm_setup';
    private const SESSION_SECONDS = 1800;

    private ?Container $container = null;

    public function __construct(private readonly string $root, private readonly UrlContext $context)
    {
    }

    private function container(): Container
    {
        return $this->container ??= new Container($this->root);
    }

    public function handle(string $method): never
    {
        $nonce = View::nonce();
        $notice = '';
        $result = null;
        $authenticated = $this->authenticated();
        if ($method === 'POST') {
            $action = is_string($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
            try {
                $this->assertSameOrigin();
                if ($action === 'login') {
                    $this->limiter()?->hit('setup.login', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 8, 600);
                    $token = trim(is_string($_POST['bot_token'] ?? null) ? (string) $_POST['bot_token'] : '');
                    if ($token === '' || !hash_equals(Env::require('TELEGRAM_BOT_TOKEN'), $token)) {
                        throw new AppException('Bot token does not match.', 403, 'setup_auth_failed');
                    }
                    $this->issueCookie();
                    $authenticated = true;
                } elseif ($action === 'logout') {
                    $this->clearCookie();
                    $authenticated = false;
                } elseif (!$authenticated) {
                    throw new AppException('Authentication required.', 401, 'setup_auth_required');
                } elseif ($action === 'repair') {
                    $result = $this->repair(is_string($_POST['url_mode'] ?? null) ? (string) $_POST['url_mode'] : '');
                } elseif ($action === 'polling') {
                    $this->service(InstallerService::configuredUrls())->switchToPolling('manual');
                    $notice = '<div class="notice good">حالت Polling فعال شد؛ پیام‌ها با Cron هر دقیقه دریافت می‌شوند. · Polling enabled; the cron job receives updates.</div>';
                } elseif ($action === 'webhook') {
                    $configured = $this->service(InstallerService::configuredUrls())->configure(TelegramSetupService::TRANSPORT_WEBHOOK);
                    $notice = View::warnings($configured['warnings']) . '<div class="notice good">حالت فعلی · Current transport: <b>' . View::e($configured['transport']) . '</b></div>';
                }
            } catch (\Throwable $exception) {
                $code = $exception instanceof AppException ? $exception->safeCode : 'setup_failed';
                $message = match ($code) {
                    'setup_auth_failed' => 'Bot Token درست نیست. · The bot token does not match.',
                    'setup_auth_required' => 'ابتدا با Bot Token وارد شوید. · Sign in with the bot token first.',
                    'rate_limit_exceeded' => 'تلاش زیاد؛ چند دقیقه صبر کنید. · Too many attempts; wait a few minutes.',
                    'setup_origin_invalid' => 'درخواست از مبدأ نامعتبر. صفحه را تازه کنید. · Invalid origin. Reload the page.',
                    default => 'عملیات کامل نشد: ' . mb_substr($exception->getMessage(), 0, 300),
                };
                $notice = '<div class="notice bad">' . View::e($message) . '</div>';
            }
        }

        $configured = InstallerService::configuredUrls();
        $detected = $this->context->baseUrl();
        $moved = rtrim($configured->baseUrl, '/') !== rtrim($detected, '/');
        $heartbeat = $this->heartbeat();
        $html = '<section class="card"><div class="brand"><div class="logo">C</div><div><h1>Setup &amp; diagnostics</h1><div class="muted">وضعیت سیستم و تعمیر خودکار اتصال تلگرام</div></div></div>'
            . '<dl class="kv" style="margin-top:12px"><dt>نسخه · Version</dt><dd>' . View::e(Config::packageVersion()) . '</dd>'
            . '<dt>ربات · Bot</dt><dd class="ltr">@' . View::e((string) Env::get('TELEGRAM_BOT_USERNAME', '')) . '</dd>'
            . '<dt>آدرس ثبت‌شده · Configured URL</dt><dd class="ltr">' . View::e($configured->baseUrl) . ' <span class="badge">' . View::e($configured->mode) . '</span></dd>'
            . '<dt>آدرس فعلی · Current URL</dt><dd class="ltr">' . View::e($detected) . ' ' . ($moved ? '<span class="badge bad">changed</span>' : '<span class="badge ok">✓</span>') . '</dd>'
            . '<dt>Cron</dt><dd>' . $heartbeat . '</dd></dl>'
            . ($moved ? '<div class="notice warn">آدرس یا پوشه برنامه تغییر کرده است. وارد شوید و «تعمیر خودکار» را بزنید تا Webhook و Mini App روی آدرس جدید تنظیم شوند.<div class="ltr">The address or folder changed. Sign in and press Repair to move the webhook and Mini App to the new address.</div></div>' : '')
            . $notice . '</section>';

        if ($result !== null) {
            $html .= '<section class="card"><h2>نتیجه تعمیر · Repair result</h2>' . View::warnings($result['warnings']) . View::steps($result['steps']) . '</section>';
        }

        if (!$authenticated) {
            $html .= '<form method="post" action="" class="card" autocomplete="off"><h2>ورود مدیر · Administrator sign-in</h2><p class="muted">برای مشاهده جزئیات و تعمیر، Bot Token همین ربات را وارد کنید. · Enter this bot\'s token to view details and repair.</p><input type="hidden" name="action" value="login"><input name="bot_token" type="password" required maxlength="128" title="123456789:AA…"><button class="wide" type="submit">ورود · Sign in</button></form>';
        } else {
            $html .= $this->panel();
        }
        $config = ['probe' => InstallerService::probeId($this->root), 'probes' => ['pretty' => $this->context->path('/_probe', [], UrlContext::MODE_PRETTY), 'pathinfo' => $this->context->path('/_probe', [], UrlContext::MODE_PATHINFO)]];
        $html .= '<script type="application/json" id="setup-config">' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        $script = View::copyScript() . "(function(){var c=JSON.parse(document.getElementById('setup-config').textContent),m=document.getElementById('repair_mode');if(!m)return;function p(u){return fetch(u,{cache:'no-store',credentials:'same-origin'}).then(function(r){return r.ok?r.json():null}).then(function(j){return !!j&&j.probe===c.probe}).catch(function(){return false})}p(c.probes.pretty).then(function(o){return o?'pretty':p(c.probes.pathinfo).then(function(x){return x?'pathinfo':'query'})}).then(function(v){m.value=v})})();";
        View::send(View::page('Setup · Telegram cPanel Manager', $html, $nonce, $script), 200, $nonce, $this->context->isHttps());
    }

    private function panel(): string
    {
        $urls = InstallerService::configuredUrls();
        $status = $this->service($urls)->status();
        $installer = new InstallerService($this->root);
        $cronSecret = Env::require('CRON_SECRET');
        $badge = static fn (bool $ok, string $yes, string $no): string => $ok ? '<span class="badge ok">' . View::e($yes) . '</span>' : '<span class="badge bad">' . View::e($no) . '</span>';
        $html = '<section class="card"><h2>تلگرام · Telegram</h2><dl class="kv">'
            . '<dt>اتصال به API · API reachable</dt><dd>' . $badge((bool) $status['reachable'], '✓', '✗ ' . (string) ($status['error'] ?? '')) . '</dd>'
            . '<dt>روش دریافت · Transport</dt><dd><b>' . View::e($status['transport']) . '</b> <span class="muted">' . View::e($status['transport_reason']) . '</span></dd>';
        if ($status['transport'] === TelegramSetupService::TRANSPORT_WEBHOOK) {
            $html .= '<dt>Webhook</dt><dd>' . $badge((bool) ($status['webhook_matches'] ?? false), '✓ registered', '✗ not matching') . '</dd>'
                . '<dt>در صف · Pending</dt><dd>' . View::e((string) ($status['pending_updates'] ?? '—')) . '</dd>'
                . '<dt>آخرین خطا · Last error</dt><dd class="ltr">' . View::e(($status['last_error_message'] ?? null) ? $status['last_error_message'] . ' (' . $status['last_error_at'] . ')' : '—') . '</dd>';
        }
        $html .= '<dt>Mini App</dt><dd class="ltr">' . View::e((string) ($status['miniapp_url'] ?? 'HTTPS required')) . '</dd></dl>'
            . '<form method="post" action="" class="actions"><input type="hidden" name="action" value="repair"><input type="hidden" name="url_mode" id="repair_mode" value=""><button type="submit">🛠 تعمیر خودکار با آدرس فعلی · Repair using current address</button></form>'
            . '<form method="post" action="" class="actions">'
            . ($status['transport'] === TelegramSetupService::TRANSPORT_WEBHOOK
                ? '<input type="hidden" name="action" value="polling"><button type="submit" class="ghost">تغییر به Polling (بدون Webhook) · Switch to polling</button>'
                : '<input type="hidden" name="action" value="webhook"><button type="submit" class="ghost">تلاش برای Webhook · Try webhook</button>')
            . '</form></section>';
        $html .= '<section class="card"><h2>Cron</h2><p class="muted">هر دقیقه (* * * * *) در cPanel → Cron Jobs · Every minute in cPanel → Cron Jobs</p>'
            . View::copyField('CLI', $installer->cronCommand($cronSecret))
            . View::copyField('Web cron', $installer->webCronCommand($urls, $cronSecret))
            . '<p class="hint ltr">PHP CLI: ' . View::e(PhpBinary::cli()) . '</p></section>'
            . '<form method="post" action="" class="card"><input type="hidden" name="action" value="logout"><button type="submit" class="ghost">خروج · Sign out</button></form>';
        return $html;
    }

    /** @return array{transport:string,steps:list<array{name:string,ok:bool,detail_fa:string,detail_en:string}>,warnings:list<array{fa:string,en:string}>} */
    private function repair(string $clientMode): array
    {
        $mode = in_array($clientMode, UrlContext::MODES, true) ? $clientMode : (string) Env::get('APP_URL_MODE', UrlContext::MODE_QUERY);
        $urls = $this->context->publicUrl($mode);
        EnvFile::update($this->root . '/.env', ['APP_URL' => $urls->baseUrl, 'APP_URL_MODE' => $urls->mode]);
        Env::load($this->root . '/.env');
        $configured = $this->service($urls)->configure(TelegramSetupService::TRANSPORT_WEBHOOK);
        array_unshift($configured['steps'], ['name' => 'Public address', 'ok' => true, 'detail_fa' => $urls->baseUrl . ' (' . $urls->mode . ')', 'detail_en' => $urls->baseUrl . ' (' . $urls->mode . ')']);
        return $configured;
    }

    private function service(PublicUrl $urls): TelegramSetupService
    {
        return new TelegramSetupService(TelegramClient::fromEnv(), $this->container()->get(Database::class), $urls, Env::require('WEBHOOK_SECRET'));
    }

    private function heartbeat(): string
    {
        try {
            $row = $this->container()->get(Database::class)->one("SELECT setting_value FROM settings WHERE setting_key = 'cron_heartbeat'");
            $value = $row === null ? null : json_decode((string) $row['setting_value'], true);
            $time = is_array($value) && is_string($value['ran_at'] ?? null) ? strtotime($value['ran_at']) : false;
            if ($time === false) {
                return '<span class="badge bad">هرگز اجرا نشده · never ran</span>';
            }
            $age = time() - $time;
            return $age < 180 ? '<span class="badge ok">✓ ' . $age . 's</span>' : '<span class="badge bad">' . (int) floor($age / 60) . ' min ago</span>';
        } catch (\Throwable) {
            return '<span class="badge bad">DB ✗</span>';
        }
    }

    private function limiter(): ?RateLimiter
    {
        try {
            return $this->container()->get(RateLimiter::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertSameOrigin(): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && $origin !== 'null' && strtolower(rtrim($origin, '/')) !== strtolower($this->context->scheme . '://' . $this->context->host)) {
            throw new AppException('Invalid origin.', 403, 'setup_origin_invalid');
        }
    }

    private function authenticated(): bool
    {
        $cookie = is_string($_COOKIE[self::COOKIE] ?? null) ? (string) $_COOKIE[self::COOKIE] : '';
        $parts = explode('.', $cookie, 2);
        return count($parts) === 2 && ctype_digit($parts[0]) && (int) $parts[0] > time() && hash_equals($this->sign($parts[0]), $parts[1]);
    }

    private function issueCookie(): void
    {
        $expires = (string) (time() + self::SESSION_SECONDS);
        setcookie(self::COOKIE, $expires . '.' . $this->sign($expires), ['expires' => (int) $expires, 'path' => $this->context->basePath === '' ? '/' : $this->context->basePath . '/', 'secure' => $this->context->isHttps(), 'httponly' => true, 'samesite' => 'Strict']);
    }

    private function clearCookie(): void
    {
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => $this->context->basePath === '' ? '/' : $this->context->basePath . '/', 'secure' => $this->context->isHttps(), 'httponly' => true, 'samesite' => 'Strict']);
    }

    private function sign(string $expires): string
    {
        return hash_hmac('sha256', 'setup|' . $expires . '|' . Env::require('TELEGRAM_BOT_TOKEN'), Env::require('APP_KEY'));
    }
}
