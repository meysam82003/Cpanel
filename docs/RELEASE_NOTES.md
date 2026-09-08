# Telegram cPanel Manager v1.0.0

## فارسی

نسخهٔ کامل و قابل‌نصب Telegram cPanel Manager برای cPanel Shared Hosting و VPS منتشر شد.

### قابلیت‌های اصلی

- Telegram Bot، Telegram Mini App موبایل‌محور، Web Installer پنج‌ورودی و Backend API کامل
- مدیریت فایل و آرشیو، ویرایشگر، نسخه‌ها، دانلود/آپلود امن و تأیید اجباری Overwrite
- مدیریت Database، Data Manager، SQL Console صفحه‌بندی‌شده، Import/Export و تاریخچهٔ بدون نگهداری متن Query
- Backup، Deploy هشت‌مرحله‌ای، Health Check، Rollback و Queue/Cron قابل‌استفاده روی Shared Hosting
- مدیریت Domain، DNS، Email، SSL، Cron، PHP، Usage، Logs، Security Center و Admin Panel
- رابط، راهنمای Contextual، هشدار و Error Guidance کامل به فارسی و English

### امنیت و اعتبارسنجی

- جداسازی Multi-Tenant و جلوگیری از IDOR
- AES-256-GCM برای اطلاعات حساس و عدم قرارگیری Secret واقعی در Release
- اعتبارسنجی Telegram Mini App `initData`، Session و CSRF
- محافظت SSRF، Path Traversal، Archive Traversal و Decompression Bomb
- Rate Limit اتمیک، Audit Log مرکزی و nonce یک‌بارمصرف متصل به کاربر/هاست/عملیات/هدف
- Capability Detection برای تفاوت‌های Provider بدون نمایش موفقیت جعلی

### نتیجهٔ تست

- PHP 8.2، 8.3 و 8.4: موفق
- 147 فایل PHP lint؛ 136 تست و 529 assertion در هر Matrix؛ سه Skip صریح وابسته به محیط واقعی
- نصب تازه و تکراری MariaDB 11.4.13: یک تست و 97 assertion موفق
- 154 مسیر API، 527 کلید دو‌زبانه Mini App، 116 کلید دو‌زبانه Bot، 78 موضوع Help و 12 Migration
- ماتریس Acceptance شامل تمام 95 بخش، بدون ردیف مفقود یا تکراری

نصب زنده روی Provider مشخص باید با Bot Token، دامنه HTTPS و حساب disposable cPanel همان محیط اعتبارسنجی شود؛ این اطلاعات داخل Release یا Git قرار نگرفته‌اند.

## English

This is the complete installable Telegram cPanel Manager release for standard cPanel shared hosting and VPS environments.

It includes the Telegram Bot and mobile-first Mini App, five-input Web Installer, complete backend API, file/archive/database/SQL management, backup, eight-stage deployment and rollback, queue/cron workers, administration, security, capability detection, and bilingual contextual guidance.

Automated verification passed on PHP 8.2, 8.3, and 8.4, including 136 tests and 529 assertions per matrix run, a fresh and repeated MariaDB 11.4.13 installation test with 97 assertions, release secret/integrity checks, and the complete 95-section acceptance matrix. Credential-dependent live provider checks remain an explicit deployment-environment requirement.
