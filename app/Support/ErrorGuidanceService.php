<?php

declare(strict_types=1);

namespace App\Support;

final class ErrorGuidanceService
{
    /** @return array{title:string,causes:list<string>,actions:list<string>} */
    public function for(string $code, string $language): array
    {
        $language = $language === 'en' ? 'en' : 'fa';
        $group = match (true) {
            str_contains($code, 'auth'), str_contains($code, 'session'), str_contains($code, 'csrf'), str_contains($code, 'init_data') => 'auth',
            str_contains($code, 'cpanel'), str_contains($code, 'host'), str_contains($code, 'network'), str_contains($code, 'timeout') => 'connection',
            str_contains($code, 'permission'), str_contains($code, 'capability'), str_contains($code, 'feature') => 'capability',
            str_contains($code, 'path'), str_contains($code, 'file'), str_contains($code, 'upload'), str_contains($code, 'download'), str_contains($code, 'zip') => 'file',
            str_contains($code, 'sql'), str_contains($code, 'database'), str_contains($code, 'mysql') => 'database',
            str_contains($code, 'rate'), str_contains($code, 'limit') => 'limit',
            str_contains($code, 'deploy'), str_contains($code, 'rollback'), str_contains($code, 'health') => 'deployment',
            default => 'generic',
        };
        $catalogue = $this->catalogue()[$language];
        return $catalogue[$group];
    }

    /** @return array<string,array<string,array{title:string,causes:list<string>,actions:list<string>}>> */
    private function catalogue(): array
    {
        return [
            'fa' => [
                'auth' => ['title' => 'Session معتبر نیست.', 'causes' => ['Mini App خارج از Telegram باز شده است.', 'initData منقضی یا امضای آن نامعتبر است.', 'Session لغو یا Rotate شده است.'], 'actions' => ['Mini App را از دکمه داخل Bot دوباره باز کنید.', 'اگر ادامه داشت، Sessionهای قبلی را از Security Center ببندید.']],
                'connection' => ['title' => 'ارتباط امن با cPanel کامل نشد.', 'causes' => ['آدرس یا پورت Host نادرست است.', 'Token حذف شده یا دسترسی لازم ندارد.', 'Firewall یا Provider دسترسی API را بسته است.', 'TLS Host معتبر نیست.'], 'actions' => ['Health Check را دوباره اجرا کنید.', 'Host و Token را بررسی یا Rotate کنید.', 'راهنمای خطای اتصال را باز کنید.']],
                'capability' => ['title' => 'این قابلیت روی Host فعلی در دسترس نیست.', 'causes' => ['Feature در Package هاست غیرفعال است.', 'Token مجوز آن Module را ندارد.', 'نسخه cPanel این Action را ارائه نمی‌کند.'], 'actions' => ['Capabilityها را Refresh کنید.', 'مجوز Token و تنظیمات Provider را بررسی کنید.']],
                'file' => ['title' => 'عملیات فایل کامل نشد.', 'causes' => ['مسیر تغییر کرده یا خارج از Home است.', 'Permission فایل کافی نیست.', 'حجم یا نوع فایل از Policy عبور کرده است.'], 'actions' => ['پوشه را Refresh کنید.', 'نام، Permission و محدودیت Upload را بررسی کنید.', 'برای فایل حذف‌شده Trash/Version History را ببینید.']],
                'database' => ['title' => 'عملیات دیتابیس کامل نشد.', 'causes' => ['Remote MySQL بسته است.', 'Privilege کافی نیست.', 'SQL یا نام Database/Table معتبر نیست.'], 'actions' => ['اتصال مستقیم Database را Test کنید.', 'مدیریت cPanel-level همچنان قابل استفاده است.', 'برای عملیات مخرب Backup را بازیابی کنید.']],
                'limit' => ['title' => 'محدودیت درخواست فعال شده است.', 'causes' => ['تعداد درخواست این بازه زیاد بوده است.', 'سقف Plan یا Upload رد شده است.'], 'actions' => ['تا زمان Retry-After صبر کنید.', 'Plan و اندازه فایل را بررسی کنید.']],
                'deployment' => ['title' => 'Deploy یا Health Check کامل نشد.', 'causes' => ['ZIP نامعتبر یا دارای مسیر ناامن است.', 'Permission مقصد کافی نیست.', 'سایت بعد از Deploy پاسخ سالم نداده است.'], 'actions' => ['Timeline و کد خطا را بررسی کنید.', 'Rollback Point را Restore کنید.', 'Health URL و Error Log سایت را ببینید.']],
                'generic' => ['title' => 'عملیات کامل نشد.', 'causes' => ['ورودی، اتصال یا مجوز سرویس ممکن است مشکل داشته باشد.'], 'actions' => ['دوباره تلاش کنید.', 'راهنمای همین صفحه و Audit ID را بررسی کنید.']],
            ],
            'en' => [
                'auth' => ['title' => 'The session is not valid.', 'causes' => ['The Mini App was opened outside Telegram.', 'initData expired or its signature is invalid.', 'The session was revoked or rotated.'], 'actions' => ['Open the Mini App again from its bot button.', 'If it continues, revoke old sessions in Security Center.']],
                'connection' => ['title' => 'The secure cPanel connection did not complete.', 'causes' => ['The host address or port is wrong.', 'The token was revoked or lacks permission.', 'A firewall or provider blocked API access.', 'Host TLS is invalid.'], 'actions' => ['Run Health Check again.', 'Review or rotate the host token.', 'Open connection troubleshooting help.']],
                'capability' => ['title' => 'This capability is unavailable on the current host.', 'causes' => ['The feature is disabled in the hosting package.', 'The token lacks module permission.', 'This cPanel version does not expose the action.'], 'actions' => ['Refresh capabilities.', 'Review token permissions and provider settings.']],
                'file' => ['title' => 'The file operation did not complete.', 'causes' => ['The path changed or is outside account home.', 'File permissions are insufficient.', 'Size or type exceeded policy.'], 'actions' => ['Refresh the directory.', 'Review filename, permissions, and upload limit.', 'Check Trash or Version History for deleted files.']],
                'database' => ['title' => 'The database operation did not complete.', 'causes' => ['Remote MySQL is blocked.', 'Privileges are insufficient.', 'SQL or a database/table name is invalid.'], 'actions' => ['Test the direct database connection.', 'cPanel-level database management remains available.', 'Restore the backup after a destructive failure.']],
                'limit' => ['title' => 'A request limit is active.', 'causes' => ['Too many requests were made in this window.', 'A plan or upload limit was exceeded.'], 'actions' => ['Wait for Retry-After.', 'Review the plan and file size.']],
                'deployment' => ['title' => 'Deployment or health check did not complete.', 'causes' => ['The ZIP is invalid or contains an unsafe path.', 'Destination permission is insufficient.', 'The site was unhealthy after deployment.'], 'actions' => ['Review the timeline and error code.', 'Restore the rollback point.', 'Check the health URL and site error log.']],
                'generic' => ['title' => 'The operation did not complete.', 'causes' => ['Input, connectivity, or service permission may be the cause.'], 'actions' => ['Try again.', 'Review contextual help and the audit ID.']],
            ],
        ];
    }
}
