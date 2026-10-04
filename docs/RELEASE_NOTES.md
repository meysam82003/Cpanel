# Telegram cPanel Manager v1.1.1

## فارسی

- فایل‌های ورودی مستقیم `install.php` و `setup.php` اضافه شدند؛ نصب‌کننده و صفحهٔ Setup حتی وقتی Rewrite در دسترس نیست، `.htaccess` Extract نشده یا قوانین پوشهٔ والد درخواست را منحرف می‌کنند، بدون هیچ Redirect باز می‌شوند (`https://example.com/folder/install.php`).
- اگر فایل مخفی `.htaccess` هنگام آپلود/Extract جا افتاده باشد، نصب‌کننده هشدار امنیتی دقیق نشان می‌دهد.

## English

- Added direct `install.php` and `setup.php` entry files so the installer and Setup open without any redirect even when rewriting is unavailable, `.htaccess` was not extracted, or parent-folder rules intercept requests.
- The installer warns when the hidden `.htaccess` file is missing after upload/extraction.

---

# Telegram cPanel Manager v1.1.0

## فارسی

بازطراحی کامل نصب، مسیریابی، اتصال ربات و بارگذاری Mini App؛ بدون نیاز به هیچ تنظیم پوشه.

### نصب بدون تنظیم

- یک Front Controller واحد (`index.php`) پوشهٔ نصب، آدرس عمومی، HTTPS پشت پروکسی/Cloudflare و روش آدرس‌دهی را در هر درخواست خودش تشخیص می‌دهد؛ در ریشهٔ دامنه، هر زیرپوشه، با یا بدون `mod_rewrite` و با Document Root روی `public` کار می‌کند.
- `.htaccess` جدید بدون `RewriteBase` و بدون حلقهٔ Redirect؛ در نسخهٔ قبل روی Apache صفحهٔ `/install` به خودش Redirect می‌شد و CSS/JS های Mini App به‌جای فایل، JSON خطا برمی‌گرداندند.
- نصب‌کننده در همان آدرسی که باز می‌کنید ظاهر می‌شود، تشخیص خودکار Telegram ID مدیر، امتحان خودکار پیشوند `cpuser_` دیتابیس و `127.0.0.1`، پیدا کردن مسیر صحیح PHP خط فرمان برای Cron، تنظیمات اختیاری پروکسی تلگرام و Bot API واسط، پیام خوش‌آمد و حفظ کلیدهای قبلی هنگام نصب دوباره.
- صفحهٔ جدید `/setup` برای وضعیت، عیب‌یابی، تعمیر خودکار پس از جابجایی پوشه/دامنه و تغییر Webhook/Polling.

### ربات

- رفع اصلی «استارت نخوردن»: اتصال دیتابیس روی UTC قفل شد؛ روی سرورهای با منطقهٔ زمانی ایران دکمه‌ها و نشست‌ها فوراً منقضی می‌شدند.
- خطای یک پیام دیگر با پاسخ غیر 200 به تلگرام برگردانده نمی‌شود (که باعث تکرار بی‌پایان و قفل صف می‌شد)؛ کاربر یک پاسخ امن دریافت می‌کند.
- حالت Polling از طریق Cron، سوییچ خودکار در صورت خرابی Webhook، بازگردانی خودکار Webhook تغییر یافته، Web cron، دستورات فارسی/انگلیسی.

### Mini App

- حذف وابستگی به `telegram.org/js` (در ایران فیلتر است و باعث باز شدن پنل بدون احراز هویت می‌شد)؛ پل داخلی WebApp جایگزین شد.
- انتقال هدر Authorization در PHP-FPM/CGI و هدر جایگزین `X-Session-Token`.
- بارگذاری مجدد Mini App دیگر خطای «already used» نمی‌دهد؛ اعتبار initData پیش‌فرض ۲۴ ساعت.
- آدرس API، دانلود و ناوبری در هر سه روش آدرس‌دهی؛ Asset های نسخه‌دار ضد Cache قدیمی؛ صفحهٔ خطای دقیق با کد و راه‌حل به‌جای پیام گمراه‌کنندهٔ «Session منقضی شده».

## English

A full redesign of installation, routing, bot delivery and Mini App loading — no folder configuration is needed anywhere.

- Single front controller with runtime detection of folder, public URL, HTTPS (proxies/Cloudflare) and routing mode (rewrite, PATH_INFO, `?r=`); new loop-free `.htaccess` without `RewriteBase` (v1.0.x looped on `/install` and served Mini App assets as JSON errors on Apache).
- Installer on the opened address, admin-ID detection, automatic `cpuser_` prefix and `127.0.0.1` retries, CLI PHP discovery for cron, optional Telegram proxy / Bot API relay, welcome message, key preservation on re-run, and a new `/setup` page for diagnostics and one-click repair after moving.
- Bot: UTC-pinned database sessions (fixes instantly expiring buttons/sessions on +03:30 servers), failed updates no longer trigger endless Telegram retries, cron polling transport with automatic webhook failover/restore, web cron, localized commands.
- Mini App: self-hosted WebApp bridge instead of `telegram.org/js`, Authorization header recovery plus `X-Session-Token`, reload-safe initData (24 h freshness), routing-aware API/download/navigation, versioned assets, and an actionable diagnostics screen.

### Verification

- PHPUnit (154 tests), static contract verifier and PHP lint pass.
- End-to-end on Apache 2.4 + MariaDB (server time zone `+03:30`) with a TLS fake Bot API: installation in a sub-folder (clean URLs) and in a folder without `mod_rewrite` (`index.php/…`), `/start` and button callbacks, Mini App authentication in all three routing modes (Bearer and `X-Session-Token`), CLI and web cron, automatic webhook→polling failover with backlog delivery, and Setup repair after moving the folder.

---

# Telegram cPanel Manager v1.0.1

## فارسی

این نسخه یک Patch Release برای رفع مشکل نصب داخل Subdirectory است؛ مخصوص حالتی مثل:

`https://example.com/cpanel-telegram`

### اصلاحات اصلی

- رفع مشکل لود نشدن CSS و JavaScript در Telegram Mini App هنگام نصب داخل پوشه
- اصلاح مسیر Assetهای Mini App برای کار با مسیر نسبی به‌جای فرض نصب در Root دامنه
- اصلاح Base Path تمام درخواست‌های Mini App API در نصب‌های Subdirectory
- اصلاح Routing داخلی Webhook ربات وقتی `APP_URL` شامل مسیر پوشه است
- اصلاح پردازش Request Path تا Prefix نصب، مانند `/cpanel-telegram`، قبل از Route Matching حذف شود
- حفظ سازگاری با نصب مستقیم روی Root دامنه
- هماهنگ‌سازی تست‌های Static با هر دو حالت Root و Subdirectory

### نتیجه تست

- PHP 8.2: موفق
- PHP 8.3: موفق
- PHP 8.4: موفق
- تست نصب تازه و تکراری روی MariaDB: موفق
- Build و Verify فایل ZIP قابل‌نصب: موفق

کاربرانی که v1.0.0 را داخل یک پوشه نصب کرده‌اند می‌توانند v1.0.1 را جایگزین فایل‌های برنامه کنند و تنظیمات `.env`، دیتابیس و `storage` فعلی خود را حفظ کنند.

## English

v1.0.1 is a patch release focused on correct operation when Telegram cPanel Manager is installed under a subdirectory such as `https://example.com/cpanel-telegram`.

### Fixes

- Fixed Mini App CSS/JavaScript assets for subdirectory deployments.
- Fixed Mini App API requests so they resolve against the configured application base path.
- Fixed Telegram webhook routing when `APP_URL` contains a path prefix.
- Fixed request path normalization before internal route matching.
- Preserved compatibility with root-domain installations.
- Updated static verification for both root and subdirectory deployment layouts.

### Verification

- PHP 8.2, 8.3 and 8.4 test matrices passed.
- Fresh and repeated MariaDB installation verification passed.
- Installable ZIP build and integrity verification passed.
