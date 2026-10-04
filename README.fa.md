# مدیر cPanel تلگرام

سامانهٔ چندمستاجری و Production-oriented برای مدیریت cPanel از طریق Telegram Bot و Telegram Mini App موبایل‌محور.

[English README](README.md) · [فهرست پیاده‌سازی](docs/IMPLEMENTED.md) · [گزارش تست](docs/TESTED.md) · [گزارش امنیت](docs/SECURITY.md) · [ماتریس ۹۵ بند](docs/ACCEPTANCE.md) · [محدودیت‌های واقعی](docs/LIMITATIONS.md)

## اجزای پروژه

- ربات تلگرام با انتخاب زبان در اولین `/start`، Onboarding هفت‌مرحله‌ای، Help متناسب با Context، Callback state سمت سرور و Navigation مبتنی بر Session.
- Mini App واقعی با API نسخه‌بندی‌شده برای Host، فایل، ویرایش کد، Database، SQL، Domain، Email، SSL، Cron، Backup، Deploy، Security، Settings و Super Admin.
- Web Installer پنج‌ورودی که Secretها و تنظیمات را تولید می‌کند، MariaDB/MySQL را migrate و seed می‌کند، Telegram را می‌آزماید، Webhook/Command/Menu Button را ثبت می‌کند و سپس خودش را قفل می‌کند.
- Backend چندمستاجری با cPanel UAPI، Compatibility bridge مستند برای عملیات فاقد UAPI، Capability Detection، Queue/Cron، Audit، Plan، Notification و Error Guidance دوزبانه.
- انتقال امن فایل، اعتبارسنجی Archive، Import/Export دیتابیس، Backup/Restore، Deploy اتمیک، Rollback تأییدشده و Crash Recovery.

Password اصلی cPanel هرگز دریافت نمی‌شود. اتصال با API Token انجام می‌شود و کاربر میان ذخیرهٔ رمز‌شده و اتصال موقت رمز‌شده و زمان‌دار انتخاب می‌کند.

## پیش‌نیازها

| مورد | حداقل / توضیح |
|---|---|
| PHP | نسخهٔ 8.2 یا جدیدتر |
| Database | MySQL 8+ یا MariaDB 10.6+ با InnoDB و `utf8mb4` |
| Extensionها | `curl`، `fileinfo`، `json`، `mbstring`، `openssl`، `pdo`، `pdo_mysql`، `zip`، `zlib` |
| Web Server | Apache یا LiteSpeed با `.htaccess`؛ روی Nginx باید Rewrite معادل تنظیم شود |
| HTTPS | Certificate عمومی معتبر برای Webhook و Mini App الزامی است |
| دسترسی خروجی | HTTPS به Telegram و Endpointهای cPanel کاربران |
| Telegram | Bot Token از `@BotFather` و Telegram Numeric ID سوپر ادمین |
| Scheduler | Cron هر یک دقیقه در cPanel یا Worker تحت Supervisor روی VPS |

ZIP انتشار شامل کد Runtime است و برای اجرای عادی روی هاست به Composer نیاز ندارد. `composer.json` همچنان مرجع Dependencyها و PSR-4 در توسعه و CI است.

## نصب روی Shared Hosting استاندارد cPanel (بدون هیچ تنظیم مسیر)

هیچ تنظیمی دربارهٔ اینکه فایل‌ها در کدام پوشه قرار گرفته‌اند لازم نیست. برنامه در ریشهٔ دامنه، هر زیرپوشه (مثلاً `https://example.com/bot` یا `https://example.com/a/b/c`)، روی Apache/LiteSpeed با یا بدون `mod_rewrite`، و حتی وقتی Document Root روی پوشهٔ `public` است کار می‌کند و همه‌چیز را خودش تشخیص می‌دهد.

1. در cPanel → **MySQL Databases** یک دیتابیس و یک کاربر بسازید و کاربر را با **ALL PRIVILEGES** به دیتابیس اضافه کنید.
2. ZIP انتشار را در **هر پوشه‌ای** که می‌خواهید Extract کنید (فایل `.htaccess` را نگه دارید).
3. همان آدرس را در مرورگر باز کنید؛ مثلاً `https://example.com/bot/`. اگر صفحه باز نشد یا خطای Redirect داد، مستقیم `https://example.com/bot/install.php` را باز کنید (بدون نیاز به Rewrite).
4. فقط این پنج مقدار را وارد کنید:

   | شماره | ورودی |
   |---|---|
   | ۱ | Bot Token از BotFather |
   | ۲ | Telegram ID عددی مدیر کل — به ربات یک پیام بدهید و دکمهٔ **«تشخیص خودکار»** را بزنید |
   | ۳ | نام دیتابیس (پیشوند `cpuser_` اگر نوشته نشود خودکار امتحان می‌شود) |
   | ۴ | نام کاربری دیتابیس |
   | ۵ | رمز دیتابیس |

   «تنظیمات پیشرفته» اختیاری است: آدرس سرور دیتابیس (پیش‌فرض `localhost` و سپس `127.0.0.1`)، پروکسی تلگرام (مثل `socks5h://IP:PORT` برای هاست‌هایی که به `api.telegram.org` دسترسی ندارند) و آدرس Bot API واسط.
5. دکمهٔ نصب را بزنید. نصب‌کننده خودکار: آدرس عمومی و پوشه را تشخیص می‌دهد، بررسی می‌کند Rewrite کار می‌کند یا نه (آدرس‌دهی تمیز، `index.php/…` یا `index.php?r=…`)، کلیدهای امنیتی را می‌سازد، `.env` را می‌نویسد، جدول‌ها را می‌سازد، مدیر کل را ثبت می‌کند، Webhook و دستورات و دکمهٔ Mini App را در تلگرام ثبت می‌کند و یک پیام خوش‌آمد برای شما می‌فرستد.
6. دستور Cron نمایش‌داده‌شده را در **cPanel → Cron Jobs** با زمان‌بندی هر دقیقه (`* * * * *`) ثبت کنید. مسیر صحیح PHP خط فرمان (مثلاً `/opt/cpanel/ea-php82/root/usr/bin/php`) خودکار پیدا می‌شود. اگر Cron خط فرمان ممکن نبود، دستور/آدرس **Web cron** هم نمایش داده می‌شود.
7. ربات را باز و `/start` را بزنید.

### اگر Webhook کار نکند

ربات هیچ‌وقت «استارت نخوردن» را تجربه نمی‌کند:

- اگر تلگرام Webhook را نپذیرد (پورت غیرمجاز، SSL نامعتبر، نبود HTTPS) ربات خودکار روی **Polling** تنظیم می‌شود و پیام‌ها توسط Cron هر دقیقه دریافت و پاسخ داده می‌شوند.
- Cron هر ۵ دقیقه سلامت Webhook را بررسی می‌کند؛ اگر آدرس عوض شده باشد دوباره ثبتش می‌کند و اگر تلگرام بیش از ۱۵ دقیقه نتواند پیام تحویل دهد، خودکار به Polling می‌رود و پیام‌های معطل را پاسخ می‌دهد.
- خطای پردازش یک پیام دیگر باعث تکرار بی‌پایان همان پیام و قفل شدن ربات نمی‌شود؛ خطا ثبت و یک پاسخ امن برای کاربر ارسال می‌شود.

### جابجایی پوشه یا دامنه — صفحهٔ Setup

بعد از نصب، آدرس `/setup` (مثلاً `https://example.com/bot/setup` یا `index.php?r=/setup`) وضعیت سیستم را نشان می‌دهد: نسخه، آدرس ثبت‌شده و آدرس فعلی، ضربان Cron، وضعیت Webhook/Polling، آخرین خطای تلگرام. با واردکردن Bot Token همان ربات می‌توانید **«تعمیر خودکار»** را بزنید تا آدرس جدید ذخیره و Webhook و Mini App دوباره ثبت شوند، یا بین Webhook و Polling جابجا شوید. اگر برنامه را به پوشه یا دامنهٔ دیگری منتقل کردید، فقط Setup را در آدرس جدید باز و تعمیر را بزنید.

نصب‌کننده دکمه یا Endpoint برای Unlock ندارد. نصب مجدد فقط با حذف دستی `storage/installed.lock` ممکن است؛ کلیدهای رمزنگاری قبلی هنگام اجرای دوباره حفظ می‌شوند.

## مواردی که Installer خودکار می‌سازد

- `APP_KEY`، Master Key رمزنگاری AES-256-GCM و نسخهٔ Key.
- Secretهای Webhook، Callback، Session مینی‌اپ و Cron.
- `.env` امن؛ Password دیتابیس برای نگهداری line-safe کد می‌شود و دسترسی Web به فایل مسدود است.
- مسیرهای `storage/cache`، `logs`، `temp`، `sessions`، `locks`، `backups` و `downloads` با دسترسی محدود.
- تمام Migrationها، Planهای اولیه و Help catalog دوزبانه.
- Webhook تلگرام با Path/Header Secret، Bot Commands و Mini App menu button.
- کاربر Super Admin و قفل نهایی نصب.

Secretها در Git، API response یا Log ذخیره نمی‌شوند. Base64 شدن Password دیتابیس در `.env` رمزنگاری محسوب نمی‌شود؛ خود `.env` باید خارج از Web و فقط برای Hosting account قابل خواندن باشد.

## ساخت API Token در cPanel

نام منوها با Provider و Theme فرق جزئی دارد:

1. با HTTPS وارد cPanel شوید.
2. **Security → Manage API Tokens** یا منوی مستقیم **Manage API Tokens** را باز کنید.
3. **Create** را بزنید.
4. نام مشخص انتخاب کنید و اگر cPanel اجازه می‌دهد، کمترین Privilege و Expiry مناسب را تنظیم کنید.
5. Token را بسازید و همان بار اول Copy کنید.
6. در Bot یا Mini App فقط Host HTTPS، Username cPanel و API Token را وارد کنید؛ Password اصلی cPanel را وارد نکنید.
7. ذخیرهٔ رمز‌شده یا اتصال موقت ۳۰ دقیقه‌ای را انتخاب کنید.

اگر Hosting Provider ساخت Token یا Module مشخصی را بسته باشد، Connection/Capability probe پیام دقیق دوزبانه می‌دهد. Feature از پروژه حذف نشده و پس از فعال‌شدن قابلیت سمت Provider قابل استفاده می‌شود.

## Commandهای ربات

| Command | کاربرد |
|---|---|
| `/start` | انتخاب زبان، Onboarding و Start Screen همگام |
| `/panel` | بازکردن Telegram Mini App |
| `/hosts` | نمایش و انتخاب حساب‌های cPanel کاربر |
| `/help` | Help دوزبانه، Contextual و قابل جستجو |
| `/security` | وضعیت امنیت و Alertها |
| `/settings` | زبان و Beginner/Advanced Mode |
| `/cancel` | لغو Flow فعلی گفتگو |
| `/admin` | پنل Super Admin؛ برای User عادی رد می‌شود |

## Queue و Cron

در Shared Hosting، Command دقیق تولیدشده توسط Installer باید هر دقیقه اجرا شود. این Runner با Cron Secret احراز هویت می‌شود و در Batch محدود موارد زیر را انجام می‌دهد:

- اجرای Queue Job و بازیابی Lease منقضی؛
- Reconcile کردن Full Backupهای در انتظار؛
- تحویل Notification تلگرام؛
- پاک‌سازی Session، Token، فایل موقت و Log منقضی؛
- ثبت Heartbeat برای Admin Health.

روی VPS می‌توان Worker را تحت Supervisor اجرا کرد:

```bash
php cli/worker.php --queue=default --max-jobs=100 --max-seconds=300
```

حتی کنار Worker دائم، Cron یک‌دقیقه‌ای را نگه دارید چون Reconciliation، Notification، Cleanup و Heartbeat وظیفهٔ Cron هستند. مسیر `cli/` را از Web در دسترس نگذارید؛ `.htaccess` آن را می‌بندد و همهٔ Entry pointها نیز `PHP_SAPI` را کنترل می‌کنند.

## File Manager

File Manager فقط داخل Home root تشخیص‌داده‌شدهٔ همان Account کار می‌کند: Pagination و Sort سمت Server، Hidden file، Metadata، Upload، Download امن و Queue-based، ساخت/ویرایش/Save As، Rename/Move/Copy، Trash، Restore، عملیات دائمی با تأیید یک‌بارمصرف، Version History، ساخت Archive و Extract اعتبارسنجی‌شده.

پیش از Extract، یک نسخهٔ immutable برای Inspection دانلود می‌شود؛ Traversal، Link، Absolute path، Expansion ratio خطرناک، تعداد/حجم بیش از حد و Integrity بررسی می‌شوند و سپس فقط یک Provider mutation بدون Replay ارسال می‌شود. عملیات سنگین در Queue ماندگار و owner-bound اجرا می‌شوند.

## Database Manager و SQL

- مدیریت Database، User، Password، Privilege و Remote MySQL در سطح cPanel.
- ساخت خودکار Connection محدود Data Manager وقتی Provider اتصال Remote MySQL را مجاز کند.
- نمایش Structure، Column، Index، Row، Filter، Pagination پایدار، CRUD، Bulk Delete و CSV Export.
- SQL Editor با Classification، محدودیت یک Statement، Result limit، History، Saved Query، `EXPLAIN`، Preview عملیات مخرب، Confirmation یک‌بارمصرف و Backup اختیاری قبل از عملیات.
- Import/Export صف‌بندی‌شده با Upload limit، staging مدیریت‌شده، checksum، gzip و Download artifact.

مرور مستقیم داده وابسته به Provider است. ممکن است cPanel مدیریت Database را بدهد ولی Firewall اتصال خارجی MySQL را ببندد یا DB Host با cPanel Host متفاوت باشد. در این حالت مدیریت سطح cPanel فعال می‌ماند و Table/SQL تا زمان اجازهٔ Provider با پیام Capability غیرفعال می‌شود.

## Backup و Deployment

Backup Center، نسخه‌های فایل، Archive پوشه، Dump دیتابیس، Rollback Pointهای Deployment و Full Account Backup سمت Provider را گروه‌بندی می‌کند. Download/Restore/Delete فقط از State واقعی Artifact ساخته می‌شوند.

- Restore فایل ابتدا از محتوای فعلی Version جدید می‌سازد.
- Restore پوشه پس از Archive inspection در Queue انجام می‌شود.
- Restore دیتابیس فقط از Managed Root به staging انحصاری Copy می‌کند، SHA-256 را می‌سنجد و از وضعیت فعلی Pre-restore backup می‌سازد.
- Restore Deployment همان Rollback Engine تأییدشدهٔ Deployment Center را اجرا می‌کند.
- Full Backup از Operation رسمی async cPanel استفاده و نتیجهٔ مبهم را بدون تکرار درخواست Reconcile می‌کند.

Deployment ساختار و checksum ZIP را بررسی، Backup اجباری را Verify، Package را به staging ایزوله منتقل، امن Extract و در صورت پشتیبانی File System نسخه‌ها را اتمیک جابه‌جا می‌کند. Health Check در برابر SSRF محافظت شده و هر Transition ثبت می‌شود. Failure به Rollback تأییدشده می‌رود یا به‌جای حدس زدن نتیجهٔ Provider در `reconciliation_required` متوقف می‌شود.

## مدل امنیتی

- رمزنگاری Token با AES-256-GCM، Nonce تصادفی، Authentication Tag، Context و Key Version.
- Ownership predicate روی همهٔ Resourceهای Tenant و پاسخ 404 غیرقابل تفکیک برای ID متعلق به دیگری.
- اعتبارسنجی HMAC `initData` تلگرام، محدودیت عمر، Replay nonce، Session opaque سمت Server، Device binding، CSRF، Expiry و Revoke.
- دفاع SSRF: فقط HTTPS، Port whitelist، DNS check، مسدودسازی Private/Reserved/Loopback/Metadata و pin کردن IP حل‌شده در cURL. شبکه خصوصی فقط با Policy صریح Admin قابل فعال‌شدن است و Loopback/Link-local/Metadata همچنان بسته می‌مانند.
- Path normalization در Root؛ کنترل Traversal و Link در Archive/Deployment.
- Rate Limit سراسری، User، Route، Webhook، Installer و Download.
- Confirmation یک‌بارمصرف وابسته به Target و جلوگیری از Replay درخواست‌های non-idempotent cPanel.
- Secret masking، Response/Transfer limit، TLS verification، Error امن، Security Event، Alert سوپر ادمین و Audit Log.
- CSP، Frame policy تلگرام، HSTS روی HTTPS، بستن Directory listing و منع Web برای فایل‌ها و پوشه‌های خصوصی.

ماتریس Control و Evidence در [docs/SECURITY.md](docs/SECURITY.md) آمده است.

## Capability Detection

بعد از تست Connection، Backend قابلیت‌های Files، MySQL، Domain/DNS، Email، SSL، Cron، Backup، Usage و PHP را Probe و Persist می‌کند. Mini App نتیجه را مصرف می‌کند و فقط Mutation پشتیبانی‌نشده را می‌بندد. Read-only با unavailable فرق دارد و محدودیت Provider باعث حذف Feature از کد نمی‌شود.

برای چند عملیات مستند cPanel هنوز UAPI معادل وجود ندارد. این موارد از API 2 compatibility bridge مرکزی عبور می‌کنند و همان TLS، SSRF، Timeout، Response limit، Error handling و No-replay را دارند. Contract checker فراخوانی‌ها را با Specification رسمی کنترل می‌کند.

## به‌روزرسانی

1. از پنل Super Admin، Maintenance را فعال کنید.
2. Backup فایل برنامه و Backup دیتابیس را بسازید و صحتشان را بررسی کنید.
3. checksum فایل ZIP انتشار را Verify کنید.
4. نسخهٔ جدید را روی برنامه Extract کنید ولی `.env`، تمام `storage/` و `storage/installed.lock` را حفظ کنید.
5. از cPanel Terminal یا SSH با Cron Secret موجود اجرا کنید:

   ```bash
   php cli/update.php --secret='<existing-cron-secret>' --check
   php cli/update.php --secret='<existing-cron-secret>'
   ```

   Check mode وقتی Migration معوق نیست Exit Code صفر و وقتی Migration معوق است Exit Code دو می‌دهد. Apply mode Lock انحصاری می‌گیرد، Migrationهای resumable، Plan و Help را به‌روز، metadata نصب را اتمیک عوض و Audit event ثبت می‌کند.
6. در Admin → Health، Schema version، Storage، Encryption، Queue، Cron heartbeat، Telegram webhook و PHP extensionها را بررسی کنید.
7. Maintenance را خاموش و Host Health را اجرا کنید.

هرگز `.env` را با `.env.example` جایگزین، Secret را بین دو نصب Copy یا State فعال Backup/Deployment را هنگام Update حذف نکنید.

## ساخت و کنترل ZIP قابل نصب

از Git checkout تمیز:

```bash
scripts/build-release.sh
(cd dist && sha256sum -c telegram-cpanel-manager-1.1.1.zip.sha256)
```

Builder فقط Source commit‌شده را Archive می‌کند، فایل‌های CI/dev را کنار می‌گذارد، Runtime directory محافظت‌شده می‌سازد، Manifest checksum هر فایل را اضافه می‌کند، Timestampها را نرمال، Token/Private Key را Scan، وجود `.env` و Install lock را رد، خود ZIP را Test و SHA-256 بیرونی تولید می‌کند. CI همین بسته را فقط پس از موفقیت PHP و MariaDB ساخته و به‌صورت Workflow Artifact منتشر می‌کند.

## توسعه و تست

```bash
composer install
composer test
composer test:security
composer test:integration
composer test:cpanel-contract
```

CI شامل Lint، Contractهای Static برای UI/API/i18n/Help/Schema، PHPUnit روی PHP 8.2/8.3/8.4، نصب تازه و تکراری روی MariaDB 11.4 و Package verifier است. Live cPanel suite اختیاری است و فقط با Account آزمایشی disposable اجرا می‌شود؛ Credential واقعی Production وارد Repository یا CI عمومی نمی‌شود.

جزئیات و مرز میان تست Automated، Contract و Provider-live در [docs/TESTED.md](docs/TESTED.md) و [docs/INSTALLATION_TEST.md](docs/INSTALLATION_TEST.md) ثبت شده است.

## رفع اشکال

### Installer به Database وصل نمی‌شود

وجود Database/User، تخصیص User به Database، Permissionها و پشتیبانی `localhost:3306` را بررسی کنید. Installer عمداً DB Host اضافه از User نمی‌گیرد.

### Telegram Setup ناموفق است

HTTPS عمومی، Certificate معتبر، خروجی TCP 443، Bot Token و Host header صحیح Proxy را کنترل کنید. پیش از Lock دوباره نصب را اجرا کنید. پس از نصب، Admin → Health آدرس Webhook ثبت‌شده در Telegram را با URL تولیدشده مقایسه می‌کند.

### Mini App می‌گوید Session منقضی شده

آن را ببندید و از `/panel` یا Menu Button دوباره باز کنید. Session زمان‌دار و وابسته به User Agent است، برای Mutation CSRF می‌خواهد و از Security Center قابل Revoke است.

### Host وصل است ولی یک بخش باز نمی‌شود

Host Health را اجرا و Capability detail را بخوانید. Privilege Token و فعال‌بودن Module/API را از Provider بپرسید. UI پشتیبانی Provider را جعل نمی‌کند.

### Queue حرکت نمی‌کند

Cron یک‌دقیقه‌ای و Heartbeat در Admin → Health را بررسی کنید. Error امن در Queue / Failed Jobs دیده می‌شود و Job واجد شرایط قابل Retry است. در Shared Hosting Batch محدود و روی VPS Worker دائمی در کنار Cron مناسب است.

### Data Browser دیتابیس وصل نمی‌شود

ابتدا مدیریت سطح cPanel را استفاده کنید؛ سپس IP عمومی Server برنامه را در Remote MySQL مجاز کنید، Host/Port واقعی DB را از Provider بگیرید و فقط با پذیرش ریسک از `%` استفاده کنید.

### Deployment نیازمند بررسی است

Provider mutation را دستی تکرار نکنید. Timeline ماندگار را بخوانید. `reconciliation_required` یعنی Backend نتیجه را اثبات نکرده و برای جلوگیری از آسیب متوقف شده است؛ فقط Rollback Point تأییدشده را اجرا یا ابتدا Account را بررسی کنید.

## ساختار Repository

| مسیر | مسئولیت |
|---|---|
| `app/` | Serviceها، Security، cPanel client، Queue handler، Bot و Backend |
| `routes/api/` | API نسخه‌بندی‌شده و authenticated مینی‌اپ |
| `public/` | Front controller، Installer، Telegram Setup و Assetهای Mini App |
| `database/` | Migrationهای مرتب و Seedهای idempotent |
| `resources/help/` | منبع مشترک Help دوزبانه برای Bot و Mini App |
| `resources/lang/` | ترجمه Bot/Backend |
| `cli/` | Worker، Cron orchestration و Updater احرازشده |
| `tests/` | Unit، Security، Integration و تست مشروط Live Provider |
| `scripts/` | Lint، Static contract، cPanel contract و Release builder |
| `docs/` | گزارش Implementation، Test، Install، Security، Limitation و Acceptance |

## محدودیت عملیاتی

Providerها و Policyهای Shared Hosting یکسان نیستند. Full-account restore معمولاً WHM/root می‌خواهد و به همین دلیل به‌جای شبیه‌سازی تشخیص و گزارش می‌شود؛ Remote MySQL ممکن است بسته باشد؛ AutoSSL، DNS، PHP selector، Cron و Backup module ممکن است محدود شوند؛ Timeout و Process limit هاست اندازه Batch را محدود می‌کند. فهرست دقیق و رفتار امن در [docs/LIMITATIONS.md](docs/LIMITATIONS.md) آمده است.

هیچ Token، Password، Encryption Key یا Configuration نصب‌شدهٔ واقعی در این Repository وجود ندارد.
