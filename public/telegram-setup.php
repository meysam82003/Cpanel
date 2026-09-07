<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';
if (!is_file($root . '/storage/installed.lock')) {
    header('Location: install', true, 302);
    exit;
}
Env::load($root . '/.env');
$appUrl = (string) Config::app('url');
$miniAppUrl = $appUrl . '/miniapp/';
$bot = '@' . (string) Env::get('TELEGRAM_BOT_USERNAME', '');
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram Setup</title><style>body{font-family:system-ui;background:#07131f;color:#eef6fa;margin:0;padding:24px;line-height:1.8}.card{max-width:760px;margin:auto;background:#102333;border:1px solid #29465b;border-radius:20px;padding:28px}h1,h2{margin-top:0}ol{padding-right:25px}code{direction:ltr;text-align:left;display:block;padding:12px;background:#06111a;border-radius:10px;overflow-wrap:anywhere}.en{direction:ltr;text-align:left;border-top:1px solid #29465b;margin-top:30px;padding-top:25px}</style></head><body><main class="card"><h1>Telegram Setup — <?= $e($bot) ?></h1><ol><li>در BotFather دستور <b>/mybots</b> را بزنید و <?= $e($bot) ?> را انتخاب کنید.</li><li>وارد <b>Bot Settings → Menu Button</b> شوید و URL زیر را ثبت کنید.</li><li>برای Main Mini App، بخش <b>Configure Mini App</b> را باز و همین URL را وارد کنید.</li><li>Description و About را تنظیم کنید. Commands و Menu Button قبلاً توسط Installer ثبت شده‌اند.</li></ol><code><?= $e($miniAppUrl) ?></code><section class="en"><h2>English</h2><ol><li>Send <b>/mybots</b> to BotFather and select <?= $e($bot) ?>.</li><li>Open <b>Bot Settings → Menu Button</b> and register the URL below.</li><li>Open <b>Configure Mini App</b> for the Main Mini App and use the same URL.</li><li>Set the description and About text. Commands and the menu button were already registered by the installer.</li></ol><code><?= $e($miniAppUrl) ?></code></section></main></body></html>

