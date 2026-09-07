<?php

declare(strict_types=1);

use App\Core\AppException;
use App\Core\Container;
use App\Core\Env;
use App\Http\Application;
use App\Http\Request;
use App\Http\Response;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://telegram.org; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors https://web.telegram.org https://*.telegram.org; base-uri 'self'; form-action 'self'; object-src 'none'");
if (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

try {
    $request = Request::capture();
    if (!is_file($root . '/storage/installed.lock') || !is_file($root . '/.env')) {
        if ($request->method === 'GET' && !str_starts_with($request->path, '/api/') && !str_starts_with($request->path, '/webhook/')) {
            header('Location: install', true, 302);
            exit;
        }
        Response::json(['ok' => false, 'error' => ['code' => 'not_installed', 'message_fa' => 'ابتدا نصب‌کننده وب را اجرا کنید.', 'message_en' => 'Run the web installer first.']], 503)->send();
    }
    Env::load($root . '/.env');
    date_default_timezone_set((string) (Env::get('APP_TIMEZONE', 'UTC') ?: 'UTC'));
    (new Application(new Container($root)))->handle($request)->send();
} catch (AppException $exception) {
    Response::json(['ok' => false, 'error' => ['code' => $exception->safeCode, 'message' => $exception->getMessage(), 'message_fa' => 'درخواست کامل نشد. ورودی و راهنمای صفحه را بررسی کنید.', 'message_en' => 'The request could not be completed. Check the input and contextual help.', 'request_id' => bin2hex(random_bytes(16))]], $exception->httpStatus)->send();
} catch (\Throwable) {
    Response::json(['ok' => false, 'error' => ['code' => 'bootstrap_failed', 'message_fa' => 'راه‌اندازی برنامه کامل نشد. تنظیمات نصب و گزارش سرور را بررسی کنید.', 'message_en' => 'Application bootstrap failed. Check installation settings and the server log.', 'request_id' => bin2hex(random_bytes(16))]], 500)->send();
}
