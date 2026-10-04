<?php

declare(strict_types=1);

namespace App\Web;

use App\Core\Logger;
use App\Http\UrlContext;
use App\Installer\InstallerException;
use App\Installer\InstallerService;

/** Web installer: one page, no folder or URL configuration required. */
final class InstallerController
{
    private const FIELDS = ['bot_token', 'super_admin_id', 'db_name', 'db_username', 'db_password', 'db_host', 'telegram_proxy', 'telegram_api_url', 'url_mode'];

    private readonly InstallerService $installer;

    public function __construct(private readonly string $root, private readonly UrlContext $context)
    {
        $this->installer = new InstallerService($root);
    }

    public function handle(string $method): never
    {
        @set_time_limit(180);
        $inspection = $this->installer->inspect();
        if ($inspection['installed']) {
            $this->locked();
        }
        $error = null;
        $values = [];
        if ($method === 'POST') {
            foreach (self::FIELDS as $field) {
                $values[$field] = is_string($_POST[$field] ?? null) ? (string) $_POST[$field] : '';
            }
            try {
                $this->assertToken(is_string($_POST['_csrf'] ?? null) ? (string) $_POST['_csrf'] : '');
                $result = $this->installer->install($values, $this->context);
                $this->renderResult($result);
            } catch (\Throwable $exception) {
                $error = $this->safeError($exception);
            }
        }
        $this->renderForm($inspection, $values, $error);
    }

    public function detectAdmin(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $input = json_decode((string) file_get_contents('php://input'), true);
            $input = is_array($input) ? array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $input) : [];
            $this->assertToken($input['_csrf'] ?? '');
            if ($this->installer->inspect()['installed']) {
                throw new InstallerException('installer_locked', 'نصب قبلاً انجام شده است.', 'Already installed.');
            }
            $result = $this->installer->detectAdmin($input);
            echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $this->safeError($exception)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function locked(): never
    {
        $body = '<section class="card">' . $this->brand('نصب قبلاً انجام شده است', 'Already installed')
            . '<div class="notice good">نصب کامل و قفل است. برای وضعیت سیستم، تعمیر اتصال تلگرام یا جابجایی پوشه/دامنه از صفحه Setup استفاده کنید.<div class="ltr">Installation is complete and locked. Use Setup for status, Telegram repair, or after moving the folder/domain.</div></div>'
            . '<div class="notice">برای نصب مجدد فقط به‌صورت دستی فایل <code class="ltr">storage/installed.lock</code> را حذف کنید.<div class="ltr">To reinstall, remove storage/installed.lock manually.</div></div>'
            . '<a class="button wide" href="' . View::e($this->context->path('/setup', [], UrlContext::MODE_QUERY)) . '">Setup &amp; diagnostics</a></section>';
        View::send(View::page('Telegram cPanel Manager', $body), 200, '', $this->context->isHttps());
    }

    /**
     * @param array{installed:bool,ready:bool,requirements:list<array{name:string,ok:bool,message_fa:string,message_en:string}>} $inspection
     * @param array<string,string> $values
     * @param array<string,string>|null $error
     */
    private function renderForm(array $inspection, array $values, ?array $error): never
    {
        $nonce = View::nonce();
        $account = $this->installer->cpanelAccount();
        $value = static fn (string $key): string => View::e($values[$key] ?? '');
        $html = '<section class="card">' . $this->brand('نصب Telegram cPanel Manager', 'Zero-configuration installer');
        $html .= '<p class="lead">فقط اطلاعات ربات و دیتابیس را وارد کنید؛ پوشه نصب، آدرس سایت، Webhook، Mini App، کلیدهای امنیتی و Cron به‌صورت خودکار تشخیص و تنظیم می‌شوند.</p><p class="lead ltr">Enter only the bot and database details. The install folder, site address, webhook, Mini App, security keys and cron are detected and configured automatically.</p>';
        $html .= '<dl class="kv" style="margin-top:14px"><dt>آدرس تشخیص‌داده‌شده · Detected address</dt><dd class="ltr">' . View::e($this->context->baseUrl()) . '/</dd>'
            . '<dt>HTTPS</dt><dd>' . ($this->context->isHttps() ? '<span class="badge ok">✓ فعال · enabled</span>' : '<span class="badge bad">✗ غیرفعال — ربات با Polling کار می‌کند؛ Mini App به SSL نیاز دارد</span>') . '</dd>'
            . '<dt>آدرس‌دهی · Routing</dt><dd><span id="probe-status" class="badge">در حال بررسی… · checking…</span></dd></dl>';
        $failed = array_filter($inspection['requirements'], static fn (array $requirement): bool => !$requirement['ok']);
        $html .= '<details' . ($failed !== [] ? ' open' : '') . '><summary>' . ($failed === [] ? '✅ همه پیش‌نیازهای سرور آماده است · All server requirements met' : '❌ برخی پیش‌نیازها آماده نیست · Some requirements are missing') . '</summary><div class="checks">';
        foreach ($inspection['requirements'] as $requirement) {
            $html .= '<div class="check"><span class="dot ' . ($requirement['ok'] ? 'yes' : 'no') . '">●</span><span><b class="ltr">' . View::e($requirement['name']) . '</b><br>' . View::e($requirement['message_fa']) . '</span></div>';
        }
        $html .= '</div></details></section>';

        if (!is_file($this->root . '/.htaccess')) {
            $html .= '<div class="notice bad"><b>فایل مخفی .htaccess در پوشه برنامه نیست</b><br>هنگام Extract یا آپلود، فایل‌های مخفی (که با نقطه شروع می‌شوند) جا افتاده‌اند. ZIP را در cPanel → File Manager آپلود و همان‌جا Extract کنید، یا در File Manager از Settings گزینه Show Hidden Files را روشن و .htaccess را بررسی کنید. بدون آن، فایل .env از وب قابل دسترسی می‌شود.<div class="ltr">The hidden .htaccess file is missing (dotfiles were skipped while extracting/uploading). Upload the ZIP in cPanel File Manager and extract it there; without it .env may be web-readable.</div></div>';
        }
        if ($error !== null) {
            $html .= '<div class="notice bad"><b>نصب کامل نشد · Installation failed</b><br>' . View::e($error['message_fa']) . '<div class="ltr">' . View::e($error['message_en']) . '</div><small class="ltr muted">Code: ' . View::e($error['code']) . ' · Ref: ' . View::e($error['request_id']) . '</small></div>';
        }

        $prefixHint = $account['prefix'] !== null ? 'اگر پیشوند ' . $account['prefix'] . ' را ننویسید، خودکار امتحان می‌شود · The ' . $account['prefix'] . ' prefix is tried automatically' : 'همان نامی که در cPanel → MySQL Databases ساخته‌اید';
        $html .= '<form method="post" action="" autocomplete="off" id="install-form" class="card"><input type="hidden" name="_csrf" value="' . View::e($this->token()) . '"><input type="hidden" name="url_mode" id="url_mode" value="">'
            . '<h2>۱. ربات تلگرام · Telegram bot</h2><div class="grid">'
            . '<div class="field full"><label for="bot_token">Bot Token</label><div class="row"><input id="bot_token" name="bot_token" type="password" required maxlength="128" title="123456789:AA…" spellcheck="false"><button type="button" class="ghost" id="toggle-token" aria-label="Show">👁</button></div><span class="hint">از @BotFather → /newbot یا /token · From @BotFather</span></div>'
            . '<div class="field full"><label for="super_admin_id">Telegram ID عددی مدیر کل · Super admin numeric ID</label><div class="row"><input id="super_admin_id" name="super_admin_id" type="text" required maxlength="20" inputmode="numeric" pattern="[0-9]+" value="' . $value('super_admin_id') . '"><button type="button" class="ghost" id="detect-admin">🔎 تشخیص خودکار · Detect</button></div><span class="hint">به ربات خود یک پیام (مثلاً /start) بفرستید و «تشخیص خودکار» را بزنید · Message your bot, then press Detect</span><div id="admin-candidates" class="candidates"></div></div>'
            . '</div><h2 style="margin-top:22px">۲. دیتابیس · Database</h2><div class="grid">'
            . '<div class="field full"><label for="db_name">نام دیتابیس · Database name</label><input id="db_name" name="db_name" type="text" required maxlength="64" value="' . $value('db_name') . '" spellcheck="false"><span class="hint">' . View::e($prefixHint) . '</span></div>'
            . '<div class="field"><label for="db_username">نام کاربری دیتابیس · Database user</label><input id="db_username" name="db_username" type="text" required maxlength="64" value="' . $value('db_username') . '" spellcheck="false"></div>'
            . '<div class="field"><label for="db_password">رمز دیتابیس · Database password</label><input id="db_password" name="db_password" type="password" required maxlength="1024"></div>'
            . '</div><details><summary>تنظیمات پیشرفته (اختیاری) · Advanced (optional)</summary><div class="grid" style="margin-top:12px">'
            . '<div class="field full"><label for="db_host">سرور دیتابیس · Database server</label><input id="db_host" name="db_host" type="text" maxlength="255" title="localhost" value="' . $value('db_host') . '"><span class="hint">خالی = localhost و سپس 127.0.0.1 · Empty tries localhost then 127.0.0.1</span></div>'
            . '<div class="field full"><label for="telegram_proxy">پروکسی تلگرام · Telegram proxy</label><input id="telegram_proxy" name="telegram_proxy" type="text" maxlength="255" title="socks5h://127.0.0.1:1080" value="' . $value('telegram_proxy') . '"><span class="hint">فقط اگر هاست به api.telegram.org دسترسی ندارد · Only if the host cannot reach api.telegram.org</span></div>'
            . '<div class="field full"><label for="telegram_api_url">آدرس Bot API واسط · Bot API relay URL</label><input id="telegram_api_url" name="telegram_api_url" type="url" maxlength="255" title="https://api.telegram.org" value="' . $value('telegram_api_url') . '"></div>'
            . '</div></details><button type="submit" class="wide" id="install-submit">🚀 نصب و راه‌اندازی خودکار · Install</button></form>';

        $config = [
            'probe' => InstallerService::probeId($this->root),
            'probes' => [
                'pretty' => $this->context->path('/_probe', [], UrlContext::MODE_PRETTY),
                'pathinfo' => $this->context->path('/_probe', [], UrlContext::MODE_PATHINFO),
            ],
            'detectUrl' => $this->context->path('/install/detect-admin', [], UrlContext::MODE_QUERY),
            'labels' => [
                'pretty' => '✓ آدرس‌های تمیز (.htaccess) · clean URLs',
                'pathinfo' => '✓ index.php/… (بدون Rewrite) · no rewrite',
                'query' => '✓ index.php?r=… (سازگار با همه سرورها) · universal',
            ],
        ];
        $html .= '<script type="application/json" id="install-config">' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        View::send(View::page('نصب · Telegram cPanel Manager', $html, $nonce, $this->script()), $error === null ? 200 : 422, $nonce, $this->context->isHttps());
    }

    /** @param array<string,mixed> $result */
    private function renderResult(array $result): never
    {
        $nonce = View::nonce();
        $bot = (string) ($result['bot']['username'] ?? '');
        $html = '<section class="card">' . $this->brand('نصب با موفقیت انجام شد 🎉', 'Installation completed')
            . '<div class="notice good">ربات <b class="ltr">@' . View::e($bot) . '</b> آماده است. ' . ($result['welcome_sent'] ? 'یک پیام خوش‌آمد برای شما در تلگرام ارسال شد.' : 'ربات را باز و /start را بزنید.') . '<div class="ltr">The bot is ready. ' . ($result['welcome_sent'] ? 'A welcome message was sent to you in Telegram.' : 'Open it and send /start.') . '</div></div>'
            . View::warnings($result['warnings'])
            . '<div class="actions"><a class="button" href="https://t.me/' . View::e($bot) . '" target="_blank" rel="noopener">باز کردن ربات · Open bot</a><a class="button ghost" href="' . View::e($result['setup_url']) . '">Setup &amp; diagnostics</a></div></section>';
        $html .= '<section class="card"><h2>⏱ آخرین قدم: Cron · Last step: cron</h2><p>در cPanel → <b>Cron Jobs</b> یک Cron با زمان‌بندی <b class="ltr">* * * * *</b> (هر دقیقه) بسازید و این دستور را وارد کنید:<span class="ltr muted" style="display:block">In cPanel → Cron Jobs add a job every minute (* * * * *) with this command:</span></p>'
            . View::copyField('دستور Cron · Cron command', (string) $result['cron_command'])
            . ($result['transport'] === 'polling' ? '<div class="notice warn">ربات در حالت Polling است؛ پیام‌ها فقط با اجرای Cron پاسخ داده می‌شوند. · The bot is in polling mode; messages are answered by the cron job.</div>' : '')
            . '<details><summary>اگر Cron خط فرمان کار نکرد · If the CLI cron does not work</summary>' . View::copyField('Web cron (curl/wget)', (string) $result['web_cron_command']) . '<p class="hint">می‌توانید همین آدرس را در سرویس‌هایی مثل cron-job.org هم هر دقیقه فراخوانی کنید. · You can also call this URL every minute from an external cron service.</p></details></section>';
        $html .= '<section class="card"><h2>آدرس‌ها · Addresses</h2>' . View::copyField('Mini App URL', (string) $result['miniapp_url']) . '<details><summary>گزارش مراحل نصب · Installation steps</summary>' . View::steps($result['steps']) . '</details></section>';
        View::send(View::page('نصب کامل شد · Installed', $html, $nonce, View::copyScript()), 200, $nonce, $this->context->isHttps());
    }

    private function brand(string $fa, string $en): string
    {
        return '<div class="brand"><div class="logo">C</div><div><h1>' . View::e($fa) . '</h1><div class="muted ltr">' . View::e($en) . '</div></div></div>';
    }

    /** @return array{code:string,message_fa:string,message_en:string,request_id:string} */
    private function safeError(\Throwable $exception): array
    {
        $requestId = bin2hex(random_bytes(8));
        (new Logger($this->root . '/storage/logs'))->error($exception->getPrevious() ?? $exception, ['request_id' => $requestId, 'component' => 'installer']);
        if ($exception instanceof InstallerException) {
            return ['code' => $exception->safeCode, 'message_fa' => $exception->messageFa, 'message_en' => $exception->messageEn, 'request_id' => $requestId];
        }
        return ['code' => 'installer_failed', 'message_fa' => 'نصب به‌دلیل یک خطای داخلی کامل نشد. فایل لاگ storage/logs را با شناسه زیر بررسی کنید.', 'message_en' => 'Installation did not complete because of an internal error. Check storage/logs using the reference below.', 'request_id' => $requestId];
    }

    /** Stateless CSRF token (no PHP session or cookie needed, so it works on every host). */
    private function token(): string
    {
        $time = (string) time();
        return $time . '.' . hash_hmac('sha256', 'installer|' . $time, $this->key());
    }

    private function assertToken(string $token): void
    {
        $parts = explode('.', $token, 2);
        $valid = count($parts) === 2 && ctype_digit($parts[0]) && (int) $parts[0] > time() - 7200 && hash_equals(hash_hmac('sha256', 'installer|' . $parts[0], $this->key()), $parts[1]);
        if (!$valid) {
            throw new InstallerException('installer_csrf_invalid', 'فرم نصب منقضی شده است. صفحه را تازه‌سازی و دوباره تلاش کنید.', 'The installer form expired. Refresh the page and try again.');
        }
    }

    private function key(): string
    {
        $path = $this->root . '/storage/cache/installer.key';
        if (is_file($path) && strlen((string) @file_get_contents($path)) === 64) {
            return (string) file_get_contents($path);
        }
        if (is_dir(dirname($path)) || @mkdir(dirname($path), 0700, true)) {
            $key = bin2hex(random_bytes(32));
            if (@file_put_contents($path, $key, LOCK_EX) !== false) {
                @chmod($path, 0600);
                return $key;
            }
        }
        return hash('sha256', __FILE__ . '|' . (string) @filemtime(__FILE__) . '|' . php_uname());
    }

    private function script(): string
    {
        return <<<'JS'
(function(){
  var cfg=JSON.parse(document.getElementById('install-config').textContent);
  var mode=document.getElementById('url_mode'),status=document.getElementById('probe-status');
  function probe(url){return fetch(url,{cache:'no-store',credentials:'same-origin'}).then(function(r){return r.ok?r.json():null}).then(function(j){return !!j&&j.probe===cfg.probe}).catch(function(){return false})}
  probe(cfg.probes.pretty).then(function(ok){return ok?'pretty':probe(cfg.probes.pathinfo).then(function(ok2){return ok2?'pathinfo':'query'})}).then(function(m){mode.value=m;status.textContent=cfg.labels[m];status.className='badge ok'});
  var token=document.getElementById('bot_token');
  document.getElementById('toggle-token').addEventListener('click',function(){token.type=token.type==='password'?'text':'password'});
  var box=document.getElementById('admin-candidates'),admin=document.getElementById('super_admin_id');
  document.getElementById('detect-admin').addEventListener('click',function(){
    var b=this;box.textContent='…';b.disabled=true;
    var f=document.getElementById('install-form');
    fetch(cfg.detectUrl,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},credentials:'same-origin',body:JSON.stringify({_csrf:f.elements._csrf.value,bot_token:token.value.trim(),telegram_proxy:f.elements.telegram_proxy.value,telegram_api_url:f.elements.telegram_api_url.value})})
    .then(function(r){return r.json()}).then(function(j){
      box.textContent='';
      if(!j.ok){box.textContent='⚠️ '+(j.error&&j.error.message_fa||'خطا')+' — '+(j.error&&j.error.message_en||'Error');return}
      var c=j.data.candidates||[];
      if(!c.length){box.textContent='هنوز پیامی به @'+j.data.bot+' نفرستاده‌اید. یک پیام بفرستید و دوباره بزنید. · Send any message to @'+j.data.bot+' and try again.';return}
      c.forEach(function(u){var x=document.createElement('button');x.type='button';x.className='ghost';x.textContent=(u.name||'?')+(u.username?' @'+u.username:'')+' — '+u.id;x.addEventListener('click',function(){admin.value=String(u.id)});box.appendChild(x)});
      if(c.length===1)admin.value=String(c[0].id);
    }).catch(function(){box.textContent='⚠️ Network error'}).finally(function(){b.disabled=false});
  });
  document.getElementById('install-form').addEventListener('submit',function(){var s=document.getElementById('install-submit');setTimeout(function(){s.disabled=true;s.textContent='⏳ در حال نصب… (تا ۱ دقیقه) · Installing…'},0)});
})();
JS;
    }
}
