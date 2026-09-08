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
