<?php

declare(strict_types=1);

use App\Installer\InstallerService;
use App\Installer\InstallerException;
use App\Core\Logger;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');
session_name('tcpm_installer');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

$installer = new InstallerService($root);
$inspection = $installer->inspect();
$result = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$inspection['installed']) {
    try {
        if (!isset($_POST['_csrf']) || !is_string($_POST['_csrf']) || !hash_equals($_SESSION['csrf'], $_POST['_csrf'])) {
            throw new InstallerException('installer_csrf_invalid', 'نشست نصب منقضی شده است. صفحه را تازه‌سازی و دوباره تلاش کنید.', 'The installer session expired. Refresh the page and try again.');
        }
        $allowed = ['bot_token', 'super_admin_id', 'db_username', 'db_password', 'db_name'];
        $input = [];
        foreach ($allowed as $key) {
            $input[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
        }
        $result = $installer->install($input, array_map('strval', $_SERVER));
        session_regenerate_id(true);
    } catch (Throwable $exception) {
        $requestId = bin2hex(random_bytes(12));
        (new Logger($root . '/storage/logs'))->error($exception, ['request_id' => $requestId, 'component' => 'installer']);
        $error = $exception instanceof InstallerException
            ? ['code' => $exception->safeCode, 'message_fa' => $exception->messageFa, 'message_en' => $exception->messageEn, 'request_id' => $requestId]
            : ['code' => 'installer_failed', 'message_fa' => 'نصب به‌دلیل یک خطای داخلی کامل نشد. گزارش محافظت‌شده سرور را با شناسه زیر بررسی کنید.', 'message_en' => 'Installation did not complete because of an internal error. Check the protected server log using the reference below.', 'request_id' => $requestId];
    } finally {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>نصب Telegram cPanel Manager · Installer</title>
  <style>
    :root{color-scheme:light dark;--bg:#07131f;--card:#102333;--line:#26465d;--text:#f2f7fa;--muted:#a9bfcc;--accent:#19b89a;--danger:#ff6b6b;--ok:#55d68b}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#16384d,var(--bg) 58%);color:var(--text);font-family:Vazirmatn,system-ui,-apple-system,sans-serif;min-height:100vh;padding:28px 16px}.shell{max-width:780px;margin:auto}.card{background:color-mix(in srgb,var(--card) 94%,transparent);border:1px solid var(--line);border-radius:22px;padding:clamp(20px,4vw,34px);box-shadow:0 24px 70px #0007}h1{font-size:clamp(24px,5vw,38px);margin:0 0 8px}.lead{color:var(--muted);line-height:1.8;margin:0 0 22px}.notice{padding:14px 16px;border-radius:14px;border:1px solid var(--line);margin:16px 0;line-height:1.7}.danger{border-color:#8a3e46;background:#391c25}.success{border-color:#287b60;background:#123c35}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:flex;flex-direction:column;gap:7px}.full{grid-column:1/-1}label{font-weight:700;font-size:14px}.hint{font-size:12px;color:var(--muted);direction:ltr;text-align:left}input{width:100%;border:1px solid var(--line);background:#071823;color:var(--text);border-radius:13px;padding:14px;font:inherit;direction:ltr}input:focus{outline:3px solid #19b89a44;border-color:var(--accent)}button,.button{display:inline-flex;justify-content:center;align-items:center;border:0;border-radius:14px;background:var(--accent);color:#041712;font-weight:800;font:inherit;padding:14px 22px;cursor:pointer;text-decoration:none;width:100%;margin-top:18px}.checks{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:18px 0}.check{display:flex;gap:8px;align-items:center;background:#091b28;padding:10px;border-radius:10px;border:1px solid var(--line);font-size:13px}.yes{color:var(--ok)}.no{color:var(--danger)}ol{line-height:1.9;padding-right:24px}code{direction:ltr;display:block;overflow-wrap:anywhere;background:#06111a;border-radius:10px;padding:10px;margin-top:6px;text-align:left}.ltr{direction:ltr;text-align:left}@media(max-width:640px){.grid,.checks{grid-template-columns:1fr}.full{grid-column:auto}.card{border-radius:18px}}
  </style>
</head>
<body><main class="shell"><section class="card">
  <h1>نصب مدیر cPanel</h1>
  <p class="lead">فقط پنج مقدار زیر را وارد کنید. تمام کلیدهای امنیتی، URLها، Schema، Webhook و تنظیمات Mini App خودکار ساخته می‌شوند.<br><span class="ltr">Enter only the five values below. Security keys, URLs, schema, webhook, and Mini App configuration are generated automatically.</span></p>
  <?php if ($inspection['installed']): ?>
    <div class="notice success"><strong>نصب قفل است · Installer locked</strong><br>برای نصب مجدد باید فایل <span class="ltr">storage/installed.lock</span> را به‌صورت دستی حذف کنید. هیچ دکمه Unlock اینترنتی وجود ندارد.<br><span class="ltr">To reinstall, remove storage/installed.lock manually. There is no remote unlock action.</span></div>
    <a class="button" href="<?= $e(rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/') . '/telegram-setup') ?>">راهنمای Telegram Setup</a>
  <?php elseif ($result !== null): ?>
    <div class="notice success"><strong>نصب و Verification کامل شد · Installation verified</strong></div>
    <ol><?php foreach ($result['steps'] as $step): ?><li><strong><?= $e($step['name']) ?></strong> — <?= $e($step['detail_fa']) ?><div class="ltr"><?= $e($step['detail_en']) ?></div></li><?php endforeach; ?></ol>
    <p>Mini App URL</p><code><?= $e($result['miniapp_url']) ?></code>
    <p>Webhook URL</p><code><?= $e($result['webhook_url']) ?></code>
    <p>Cron command</p><code><?= $e($result['cron_command']) ?></code>
    <a class="button" href="<?= $e($result['app_url'] . '/telegram-setup') ?>">ادامه: Telegram Setup</a>
  <?php else: ?>
    <?php if ($error !== null): ?><div class="notice danger"><strong>نصب کامل نشد · Installation failed</strong><br><?= $e($error['message_fa']) ?><br><span class="ltr"><?= $e($error['message_en']) ?></span><small class="ltr">Code: <?= $e($error['code']) ?> · Reference: <?= $e($error['request_id']) ?></small><br>Secretهای واردشده نمایش یا ثبت نشده‌اند.<br><span class="ltr">Entered secrets were neither displayed nor logged.</span></div><?php endif; ?>
    <div class="checks"><?php foreach ($inspection['requirements'] as $req): ?><div class="check"><span class="<?= $req['ok'] ? 'yes' : 'no' ?>">●</span><span><?= $e($req['name']) ?> — <?= $e($req['message_fa']) ?><small class="ltr"><?= $e($req['message_en']) ?></small></span></div><?php endforeach; ?></div>
    <form method="post" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= $e($_SESSION['csrf']) ?>">
      <div class="grid">
        <div class="field full"><label for="bot_token">۱. Telegram Bot Token</label><input id="bot_token" name="bot_token" type="password" required maxlength="128" inputmode="text" autocomplete="off"><span class="hint">123456789:AA...</span></div>
        <div class="field full"><label for="super_admin_id">۲. Telegram Numeric ID سوپر ادمین</label><input id="super_admin_id" name="super_admin_id" type="text" required maxlength="20" inputmode="numeric" pattern="[0-9]+"></div>
        <div class="field"><label for="db_username">۳. Database Username</label><input id="db_username" name="db_username" type="text" required maxlength="64" autocomplete="username"></div>
        <div class="field"><label for="db_password">۴. Database Password</label><input id="db_password" name="db_password" type="password" required maxlength="1024" autocomplete="current-password"></div>
        <div class="field full"><label for="db_name">۵. Database Name</label><input id="db_name" name="db_name" type="text" required maxlength="64"></div>
      </div>
      <button type="submit">نصب، تست و ثبت Webhook · Install & verify</button>
    </form>
  <?php endif; ?>
</section></main></body></html>
