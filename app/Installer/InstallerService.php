<?php

declare(strict_types=1);

namespace App\Installer;

use App\Accounts\UserRepository;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\EnvFile;
use App\Core\MigrationRunner;
use App\Help\HelpSeeder;
use App\Http\PublicUrl;
use App\Http\UrlContext;
use App\Support\PhpBinary;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramSetupService;
use PDO;
use PDOException;
use Throwable;

/**
 * Zero-configuration installer. The installation folder, public URL, routing
 * mode (rewrite / PATH_INFO / query), database host and cPanel name prefix,
 * PHP CLI binary and Telegram transport are all detected; the operator only
 * supplies the bot token, the admin Telegram ID and the database credentials.
 */
final class InstallerService
{
    private const REQUIRED_EXTENSIONS = ['curl', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'zlib', 'zip'];
    private const CONNECTION_ERRORS = [2002, 2003, 2005, 2006];

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{installed:bool,ready:bool,requirements:list<array{name:string,ok:bool,message_fa:string,message_en:string}>} */
    public function inspect(): array
    {
        $requirements = [[
            'name' => 'PHP >= 8.2',
            'ok' => PHP_VERSION_ID >= 80200,
            'message_fa' => PHP_VERSION,
            'message_en' => PHP_VERSION,
        ]];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $loaded = extension_loaded($extension);
            $requirements[] = [
                'name' => 'ext-' . $extension,
                'ok' => $loaded,
                'message_fa' => $loaded ? 'فعال' : 'نصب/فعال نیست — در cPanel → Select PHP Version فعالش کنید',
                'message_en' => $loaded ? 'Enabled' : 'Missing — enable it in cPanel → Select PHP Version',
            ];
        }
        $writable = is_writable($this->root) || (is_dir($this->root . '/storage') && is_writable($this->root . '/storage') && (is_file($this->root . '/.env') ? is_writable($this->root . '/.env') : is_writable($this->root)));
        $requirements[] = [
            'name' => 'Writable project directory',
            'ok' => $writable,
            'message_fa' => $writable ? 'قابل نوشتن' : 'پوشه برنامه قابل نوشتن نیست (Permission 755 و مالکیت کاربر هاست)',
            'message_en' => $writable ? 'Writable' : 'The application folder is not writable (use 755 and the hosting user as owner)',
        ];
        $ready = !in_array(false, array_column($requirements, 'ok'), true);
        return ['installed' => is_file($this->root . '/storage/installed.lock'), 'ready' => $ready, 'requirements' => $requirements];
    }

    /** @return array{prefix:?string,user:?string} */
    public function cpanelAccount(): array
    {
        $user = null;
        if (preg_match('#^/home\d*/([a-z][a-z0-9_]{0,31})(?:/|$)#', str_replace('\\', '/', $this->root), $match)) {
            $user = $match[1];
        } elseif (function_exists('get_current_user')) {
            $candidate = (string) get_current_user();
            $user = preg_match('/^[a-z][a-z0-9_]{0,31}$/', $candidate) && !in_array($candidate, ['root', 'nobody', 'www-data', 'apache', 'nginx'], true) ? $candidate : null;
        }
        return ['user' => $user, 'prefix' => $user === null ? null : $user . '_'];
    }

    /**
     * @param array<string,string> $input
     * @return array<string,mixed>
     */
    public function install(array $input, UrlContext $context): array
    {
        $lock = $this->acquireInstallLock();
        try {
            return $this->performInstallation($input, $context);
        } finally {
            $this->releaseInstallLock($lock);
        }
    }

    /**
     * Finds the numeric IDs of people who recently messaged the bot, so the
     * operator does not need to look up their Telegram ID elsewhere.
     *
     * @param array<string,string> $input
     * @return array{bot:string,candidates:list<array{id:int,name:string,username:string}>}
     */
    public function detectAdmin(array $input): array
    {
        $token = trim((string) ($input['bot_token'] ?? ''));
        if (!TelegramClient::isValidToken($token)) {
            throw new InstallerException('invalid_bot_token', 'فرمت Bot Token معتبر نیست. Token را بدون فاصله از BotFather کپی کنید.', 'The Bot Token format is invalid. Copy it from BotFather without spaces.');
        }
        $telegram = $this->telegramClient($token, $input);
        try {
            $bot = $telegram->call('getMe', [], 20);
            $telegram->call('deleteWebhook', ['drop_pending_updates' => false], 20);
            $updates = $telegram->call('getUpdates', ['limit' => 100, 'timeout' => 0], 20);
        } catch (Throwable $exception) {
            throw $this->telegramFailure($exception);
        }
        $candidates = [];
        foreach (array_reverse(is_array($updates) ? $updates : []) as $update) {
            $source = $update['message'] ?? $update['callback_query'] ?? $update['my_chat_member'] ?? null;
            $from = is_array($source) && is_array($source['from'] ?? null) ? $source['from'] : null;
            if ($from === null || !empty($from['is_bot']) || !isset($from['id']) || isset($candidates[(int) $from['id']])) {
                continue;
            }
            $candidates[(int) $from['id']] = [
                'id' => (int) $from['id'],
                'name' => trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? '')),
                'username' => (string) ($from['username'] ?? ''),
            ];
        }
        return ['bot' => is_array($bot) ? (string) ($bot['username'] ?? '') : '', 'candidates' => array_values($candidates)];
    }

    /**
     * @param array<string,string> $input
     * @return array<string,mixed>
     */
    private function performInstallation(array $input, UrlContext $context): array
    {
        if (is_file($this->root . '/storage/installed.lock')) {
            throw new InstallerException('installer_locked', 'نصب قبلاً کامل و قفل شده است. برای نصب مجدد، فایل storage/installed.lock را فقط به‌صورت دستی حذف کنید.', 'Installation is already complete and locked. To reinstall, remove storage/installed.lock manually.');
        }
        $steps = [];
        $warnings = [];
        foreach ($this->inspect()['requirements'] as $requirement) {
            if (!$requirement['ok']) {
                throw new InstallerException('requirement_failed', 'پیش‌نیاز سرور فراهم نیست: ' . $requirement['name'] . ' — ' . $requirement['message_fa'], 'Server requirement is unavailable: ' . $requirement['name'] . ' — ' . $requirement['message_en']);
            }
        }
        $steps[] = $this->step('Server requirements', 'PHP ' . PHP_VERSION . ' و همه افزونه‌ها آماده‌اند', 'PHP ' . PHP_VERSION . ' and all extensions are ready');

        $validated = $this->validateInput($input);
        $urlMode = $this->resolveUrlMode($context, (string) ($input['url_mode'] ?? ''));
        $urls = $context->publicUrl($urlMode);
        $steps[] = $this->step('Public address', $urls->baseUrl . ' (' . $this->modeLabel($urlMode, 'fa') . ')', $urls->baseUrl . ' (' . $this->modeLabel($urlMode, 'en') . ')');
        if ($urlMode !== UrlContext::MODE_PRETTY) {
            $warnings[] = ['fa' => 'وب‌سرور فایل .htaccess را اجرا نمی‌کند؛ برنامه با آدرس‌دهی index.php کار می‌کند. برای امنیت بیشتر Document Root دامنه را روی پوشه public بگذارید یا دسترسی وب به .env و storage را ببندید.', 'en' => 'The web server ignores .htaccess; the application works through index.php addressing. For stronger protection point the document root to the public folder or deny web access to .env and storage.'];
        }
        if (!$urls->isHttps()) {
            $warnings[] = ['fa' => 'سایت روی HTTPS باز نشده است. نصب ادامه پیدا کرد و ربات با Polling کار می‌کند؛ برای Mini App، SSL را فعال و سپس صفحه Setup را باز و «تعمیر» را بزنید.', 'en' => 'The site was not opened over HTTPS. Installation continued and the bot works through polling; enable SSL for the Mini App, then open Setup and run Repair.'];
        }

        $telegram = $this->telegramClient($validated['bot_token'], $validated);
        try {
            $bot = $telegram->call('getMe', [], 20);
        } catch (Throwable $exception) {
            throw $this->telegramFailure($exception);
        }
        if (!is_array($bot) || empty($bot['id']) || empty($bot['username'])) {
            throw new InstallerException('telegram_invalid_response', 'Telegram اطلاعات کامل ربات را برنگرداند. چند دقیقه بعد دوباره تلاش کنید.', 'Telegram did not return complete bot information. Try again in a few minutes.');
        }
        $steps[] = $this->step('Telegram Bot', '@' . $bot['username'] . ' تأیید شد', '@' . $bot['username'] . ' verified');

        $connection = $this->connectDatabase($validated);
        $pdo = $connection['pdo'];
        $steps[] = $this->step('MySQL / MariaDB', 'اتصال برقرار شد: ' . $connection['user'] . '@' . $connection['host'] . ' / ' . $connection['name'], 'Connected: ' . $connection['user'] . '@' . $connection['host'] . ' / ' . $connection['name']);

        $this->prepareStorage();
        $steps[] = $this->step('Storage', 'مسیرهای امن storage ساخته شدند', 'Secure runtime directories created');

        $existing = $this->existingEnvironment();
        $secrets = $this->generateSecrets($existing);
        $environment = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $urls->baseUrl,
            'APP_URL_MODE' => $urls->mode,
            'APP_TIMEZONE' => 'UTC',
            'APP_KEY' => $secrets['app_key'],
            'APP_VERSION' => Config::packageVersion(),
            'ENCRYPTION_KEY_V1' => $secrets['encryption_key'],
            'ENCRYPTION_CURRENT_VERSION' => $existing['ENCRYPTION_CURRENT_VERSION'] ?? '1',
            'WEBHOOK_SECRET' => $secrets['webhook_secret'],
            'CALLBACK_SECRET' => $secrets['callback_secret'],
            'SESSION_SECRET' => $secrets['session_secret'],
            'CRON_SECRET' => $secrets['cron_secret'],
            'TELEGRAM_BOT_TOKEN' => $validated['bot_token'],
            'TELEGRAM_BOT_USERNAME' => (string) $bot['username'],
            'TELEGRAM_PROXY' => $validated['telegram_proxy'],
            'TELEGRAM_API_URL' => $validated['telegram_api_url'],
            'SUPER_ADMIN_TELEGRAM_ID' => (string) $validated['super_admin_id'],
            'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_NAME' => $connection['name'],
            'DB_USER' => $connection['user'],
            'DB_PASSWORD_B64' => base64_encode($validated['db_password']),
            'DB_CHARSET' => 'utf8mb4',
            'ALLOW_PRIVATE_CPANEL_HOSTS' => $existing['ALLOW_PRIVATE_CPANEL_HOSTS'] ?? 'false',
            'CPANEL_ALLOWED_PORTS' => $existing['CPANEL_ALLOWED_PORTS'] ?? '2083,443',
            'CPANEL_CONNECT_TIMEOUT' => '8',
            'CPANEL_REQUEST_TIMEOUT' => '30',
            'TELEGRAM_INITDATA_TTL' => '86400',
            'MINIAPP_SESSION_TTL' => '3600',
            'QUEUE_STALE_AFTER_SECONDS' => '3600',
            'MAX_UPLOAD_BYTES' => '20971520',
            'TELEGRAM_DOWNLOAD_MAX_BYTES' => '20000000',
            'MAX_DOWNLOAD_BYTES' => '104857600',
            'TELEGRAM_SEND_MAX_BYTES' => '50000000',
            'MAX_ARCHIVE_INSPECTION_BYTES' => '268435456',
            'MAX_ARCHIVE_EXPANDED_BYTES' => '1073741824',
            'MAX_DEPLOY_BACKUP_VERIFY_BYTES' => '1073741824',
            'MAX_ARCHIVE_FILES' => '10000',
            'MAX_ARCHIVE_EXPANSION_RATIO' => '200',
            'MAX_ARCHIVE_TOP_LEVEL_ENTRIES' => '500',
            'LOG_LEVEL' => 'warning',
        ];
        foreach ($existing as $key => $value) {
            if (preg_match('/^ENCRYPTION_KEY_V\d+$/', $key) && !isset($environment[$key])) {
                $environment[$key] = $value;
            }
        }
        try {
            EnvFile::write($this->root . '/.env', $environment, 'Generated by the Telegram cPanel Manager installer');
        } catch (Throwable $exception) {
            throw new InstallerException('configuration_write_failed', 'فایل تنظیمات .env نوشته نشد. Permission پوشه برنامه را بررسی کنید.', 'The .env configuration file could not be written. Check the application folder permissions.', $exception);
        }
        $steps[] = $this->step('Security keys / configuration', 'کلیدها ساخته و فایل .env با Permission محدود ذخیره شد', 'Keys generated and .env stored with restricted permissions');

        try {
            $runner = new MigrationRunner($pdo);
            $migrations = $runner->migrate($this->root . '/database/migrations');
            $runner->seed($this->root . '/database/seeds');
            $database = Database::fromPdo($pdo);
            $helpCount = (new HelpSeeder($database))->seed($this->root . '/resources/help/topics.php');
            $users = new UserRepository($database);
            $admin = $users->upsertTelegram(['id' => $validated['super_admin_id']], 'fa');
            $database->execute('UPDATE users SET is_super_admin = 1, status = \'active\' WHERE id = ?', [$admin['id']]);
            $database->execute("UPDATE user_plans SET plan_id = (SELECT id FROM plans WHERE slug = 'business') WHERE user_id = ? AND status = 'active'", [$admin['id']]);
        } catch (Throwable $exception) {
            throw new InstallerException('database_setup_failed', 'ساخت جداول دیتابیس کامل نشد. در cPanel → MySQL Databases به کاربر دیتابیس دسترسی ALL PRIVILEGES بدهید و دوباره نصب را بزنید (ادامه از همان‌جا انجام می‌شود).', 'Database schema setup did not complete. Grant ALL PRIVILEGES to the database user in cPanel → MySQL Databases and run the installer again (it resumes safely).', $exception);
        }
        $steps[] = $this->step('Database schema', count($migrations) . ' migration و ' . $helpCount . ' راهنما آماده شد', count($migrations) . ' migrations and ' . $helpCount . ' help topics prepared');
        $steps[] = $this->step('Super Admin', 'Telegram ID ' . $validated['super_admin_id'] . ' مدیر کل شد', 'Telegram ID ' . $validated['super_admin_id'] . ' is the super admin');

        try {
            $setup = new TelegramSetupService($telegram, $database, $urls, $secrets['webhook_secret']);
            $configured = $setup->configure(TelegramSetupService::TRANSPORT_WEBHOOK);
        } catch (Throwable $exception) {
            throw $this->telegramFailure($exception, 'telegram_setup_failed');
        }
        foreach ($configured['steps'] as $step) {
            $steps[] = $step;
        }
        array_push($warnings, ...$configured['warnings']);

        $welcomeSent = $this->sendWelcome($telegram, $validated['super_admin_id'], $urls);
        if (!$welcomeSent) {
            $warnings[] = ['fa' => 'پیام خوش‌آمد به مدیر ارسال نشد چون هنوز ربات را Start نکرده‌اید. ربات @' . $bot['username'] . ' را باز و /start را بزنید.', 'en' => 'The welcome message could not be delivered because you have not started the bot yet. Open @' . $bot['username'] . ' and send /start.'];
        }

        $this->writeLock((int) $bot['id'], (string) $bot['username']);
        $steps[] = $this->step('Installer lock', 'نصب قفل شد', 'Installer locked');

        return [
            'steps' => $steps,
            'warnings' => $warnings,
            'bot' => ['id' => (int) $bot['id'], 'username' => (string) $bot['username']],
            'app_url' => $urls->baseUrl,
            'url_mode' => $urls->mode,
            'transport' => $configured['transport'],
            'miniapp_url' => $urls->miniApp(),
            'setup_url' => $urls->to('/setup'),
            'cron_command' => $this->cronCommand($secrets['cron_secret']),
            'web_cron_command' => $this->webCronCommand($urls, $secrets['cron_secret']),
            'welcome_sent' => $welcomeSent,
        ];
    }

    public function cronCommand(string $cronSecret): string
    {
        return sprintf('%s %s --secret=%s >/dev/null 2>&1', PhpBinary::cli(), escapeshellarg($this->root . '/cli/cron.php'), $cronSecret);
    }

    public function webCronCommand(PublicUrl $urls, string $cronSecret): string
    {
        return sprintf("curl -fsS -m 60 '%s' >/dev/null 2>&1 || wget -q -O /dev/null '%s'", $urls->to('/cron/' . $cronSecret), $urls->to('/cron/' . $cronSecret));
    }

    /** @param array<string,string> $input
     *  @return array{bot_token:string,super_admin_id:int,db_username:string,db_password:string,db_name:string,db_host:string,db_port:int,telegram_proxy:string,telegram_api_url:string}
     */
    private function validateInput(array $input): array
    {
        $token = trim($input['bot_token'] ?? '');
        $admin = filter_var(trim((string) ($input['super_admin_id'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $username = trim($input['db_username'] ?? '');
        $password = (string) ($input['db_password'] ?? '');
        $database = trim($input['db_name'] ?? '');
        if (!TelegramClient::isValidToken($token)) {
            throw new InstallerException('invalid_bot_token', 'فرمت Bot Token معتبر نیست. Token را بدون فاصله از BotFather کپی کنید.', 'The Bot Token format is invalid. Copy the token from BotFather without extra spaces.');
        }
        if ($admin === false) {
            throw new InstallerException('invalid_admin_id', 'Telegram ID سوپر ادمین باید یک عدد مثبت باشد. از دکمه «تشخیص خودکار» استفاده کنید.', 'The Super Admin Telegram ID must be a positive number. Use the “Detect” button.');
        }
        foreach ([$username, $database] as $value) {
            if (!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $value)) {
                throw new InstallerException('invalid_database_identifier', 'نام دیتابیس و نام کاربری فقط می‌توانند شامل حروف انگلیسی، عدد و نویسه‌های _ $ . - باشند.', 'Database name and username may contain only letters, numbers, and _ $ . - characters.');
            }
        }
        if ($password === '' || strlen($password) > 1024 || str_contains($password, "\0")) {
            throw new InstallerException('invalid_database_password', 'رمز دیتابیس خالی یا نامعتبر است.', 'The database password is empty or invalid.');
        }
        $host = trim((string) ($input['db_host'] ?? ''));
        $port = 3306;
        if ($host !== '') {
            if (preg_match('/^(.+):(\d{1,5})$/', $host, $match) && !str_contains($match[1], ':')) {
                $host = $match[1];
                $port = (int) $match[2];
            }
            if ((!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && !filter_var($host, FILTER_VALIDATE_IP)) || $port < 1 || $port > 65535) {
                throw new InstallerException('invalid_database_host', 'آدرس سرور دیتابیس معتبر نیست. معمولاً خالی بگذارید (localhost).', 'The database server address is invalid. Usually leave it empty (localhost).');
            }
        }
        try {
            $proxy = (string) TelegramClient::normalizeProxy($input['telegram_proxy'] ?? null);
            $apiUrl = trim((string) ($input['telegram_api_url'] ?? '')) === '' ? '' : TelegramClient::normalizeApiBase($input['telegram_api_url'] ?? null);
        } catch (TelegramApiException $exception) {
            throw new InstallerException($exception->safeCode, 'تنظیم پروکسی/آدرس API تلگرام معتبر نیست: ' . $exception->getMessage(), 'Telegram proxy/API setting is invalid: ' . $exception->getMessage());
        }
        return ['bot_token' => $token, 'super_admin_id' => (int) $admin, 'db_username' => $username, 'db_password' => $password, 'db_name' => $database, 'db_host' => $host, 'db_port' => $port, 'telegram_proxy' => $proxy, 'telegram_api_url' => $apiUrl];
    }

    /** @param array<string,mixed> $settings */
    private function telegramClient(string $token, array $settings): TelegramClient
    {
        try {
            return new TelegramClient($token, 30, (string) ($settings['telegram_api_url'] ?? '') ?: null, (string) ($settings['telegram_proxy'] ?? '') ?: null);
        } catch (TelegramApiException $exception) {
            throw new InstallerException($exception->safeCode, 'تنظیم تلگرام معتبر نیست: ' . $exception->getMessage(), 'Telegram setting is invalid: ' . $exception->getMessage());
        }
    }

    private function telegramFailure(Throwable $exception, string $code = 'telegram_auth_failed'): InstallerException
    {
        $detail = mb_substr($exception->getMessage(), 0, 300);
        if ($exception instanceof TelegramApiException && $exception->safeCode === 'telegram_network_error') {
            return new InstallerException('telegram_unreachable', 'سرور به api.telegram.org وصل نشد (' . $detail . '). اگر هاست در ایران است یا دسترسی خروجی بسته است، در «تنظیمات پیشرفته» یک پروکسی (مثل socks5h://IP:PORT) یا آدرس Bot API واسط وارد کنید.', 'The server could not reach api.telegram.org (' . $detail . '). If outbound access is blocked, set a proxy (e.g. socks5h://IP:PORT) or a Bot API relay URL under Advanced settings.', $exception);
        }
        if ($exception instanceof TelegramApiException && (int) ($exception->context['http_status'] ?? 0) === 401) {
            return new InstallerException('telegram_auth_failed', 'تلگرام Bot Token را رد کرد (Unauthorized). Token را دوباره از BotFather کپی کنید یا با /revoke یک Token جدید بگیرید.', 'Telegram rejected the Bot Token (Unauthorized). Copy it again from BotFather or issue a new one with /revoke.', $exception);
        }
        return new InstallerException($code, 'درخواست تلگرام ناموفق بود: ' . $detail, 'The Telegram request failed: ' . $detail, $exception);
    }

    /**
     * Connects with the supplied credentials, retrying the TCP host and the
     * cPanel account prefix (cpuser_) automatically when they were omitted.
     *
     * @param array{db_username:string,db_password:string,db_name:string,db_host:string,db_port:int} $input
     * @return array{pdo:PDO,host:string,port:int,user:string,name:string}
     */
    private function connectDatabase(array $input): array
    {
        $hosts = $input['db_host'] !== '' ? [$input['db_host']] : ['localhost', '127.0.0.1'];
        $prefix = $this->cpanelAccount()['prefix'];
        $variants = static function (string $value) use ($prefix): array {
            $values = [$value];
            if ($prefix !== null && !str_starts_with($value, $prefix)) {
                $values[] = $prefix . $value;
                $short = substr(rtrim($prefix, '_'), 0, 8) . '_';
                if ($short !== $prefix && !str_starts_with($value, $short)) {
                    $values[] = $short . $value;
                }
            }
            return $values;
        };
        $last = null;
        foreach ($hosts as $host) {
            foreach ($variants($input['db_username']) as $user) {
                foreach ($variants($input['db_name']) as $name) {
                    try {
                        $pdo = Database::connect(['host' => $host, 'port' => $input['db_port'], 'name' => $name, 'user' => $user, 'password' => $input['db_password'], 'charset' => 'utf8mb4']);
                        $pdo->query('SELECT 1')->fetchColumn();
                        return ['pdo' => $pdo, 'host' => $host, 'port' => $input['db_port'], 'user' => $user, 'name' => $name];
                    } catch (PDOException $exception) {
                        $last = $exception;
                        $code = (int) ($exception->errorInfo[1] ?? $exception->getCode());
                        if (in_array($code, self::CONNECTION_ERRORS, true)) {
                            continue 3;
                        }
                        if ($code === 1045) {
                            continue 2;
                        }
                    }
                }
            }
        }
        $code = $last === null ? 0 : (int) ($last->errorInfo[1] ?? $last->getCode());
        $detail = $last === null ? '' : mb_substr(preg_replace('/^SQLSTATE\[[^\]]+\]\s*(\[\d+\]\s*)?/', '', $last->getMessage()) ?? '', 0, 240);
        [$fa, $en] = match ($code) {
            1045 => ['نام کاربری یا رمز دیتابیس اشتباه است.', 'The database username or password is wrong.'],
            1044 => ['کاربر دیتابیس به این دیتابیس دسترسی ندارد. در cPanel → MySQL Databases بخش «Add User To Database» کاربر را با ALL PRIVILEGES اضافه کنید.', 'The database user has no access to this database. In cPanel → MySQL Databases use “Add User To Database” with ALL PRIVILEGES.'],
            1049 => ['دیتابیسی با این نام وجود ندارد. ابتدا آن را در cPanel → MySQL Databases بسازید.', 'No database with this name exists. Create it first in cPanel → MySQL Databases.'],
            2002, 2003, 2005, 2006 => ['سرور MySQL در دسترس نیست. در «تنظیمات پیشرفته» آدرس سرور دیتابیس را از پشتیبانی هاست بپرسید و وارد کنید.', 'The MySQL server is not reachable. Ask your provider for the database server address and enter it under Advanced settings.'],
            default => ['اتصال دیتابیس برقرار نشد.', 'The database connection failed.'],
        };
        $hint = $prefix !== null ? ' (پیشوند ' . $prefix . ' هم امتحان شد / prefix ' . $prefix . ' was also tried)' : '';
        throw new InstallerException('database_connection_failed', $fa . $hint . ' — ' . $detail, $en . ' — ' . $detail, $last);
    }

    private function resolveUrlMode(UrlContext $context, string $clientMode): string
    {
        if (in_array($clientMode, UrlContext::MODES, true)) {
            return $clientMode;
        }
        foreach ([UrlContext::MODE_PRETTY, UrlContext::MODE_PATHINFO] as $mode) {
            if ($this->probe($context->url('/_probe', [], $mode))) {
                return $mode;
            }
        }
        return UrlContext::MODE_QUERY;
    }

    /** Server-side loopback check that a routing style reaches this installation. */
    private function probe(string $url): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $expected = self::probeId($this->root);
        $parts = parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80)) : 0;
        foreach ([null, '127.0.0.1'] as $resolve) {
            $curl = curl_init($url);
            $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
            if ($resolve !== null) {
                // Loopback to this same server; the response is matched against a local secret.
                $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $resolve];
                $options[CURLOPT_SSL_VERIFYPEER] = false;
                $options[CURLOPT_SSL_VERIFYHOST] = 0;
            }
            curl_setopt_array($curl, $options);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            $decoded = is_string($body) ? json_decode($body, true) : null;
            if ($status === 200 && is_array($decoded) && hash_equals($expected, (string) ($decoded['probe'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    public static function probeId(string $root): string
    {
        return substr(hash('sha256', 'tcpm-probe|' . (realpath($root) ?: $root)), 0, 24);
    }

    private function sendWelcome(TelegramClient $telegram, int $adminId, PublicUrl $urls): bool
    {
        $text = "✅ <b>Telegram cPanel Manager</b>\n\nنصب با موفقیت انجام شد. برای شروع /start را بزنید.\nInstallation completed. Send /start to begin.";
        $parameters = ['chat_id' => $adminId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($urls->isHttps()) {
            $parameters['reply_markup'] = ['inline_keyboard' => [[['text' => '🌐 پنل مدیریت / Control panel', 'web_app' => ['url' => $urls->miniApp()]]]]];
        }
        try {
            $telegram->call('sendMessage', $parameters, 15);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function modeLabel(string $mode, string $language): string
    {
        return match ($mode) {
            UrlContext::MODE_PRETTY => $language === 'fa' ? 'آدرس‌های تمیز با .htaccess' : 'clean URLs via .htaccess',
            UrlContext::MODE_PATHINFO => $language === 'fa' ? 'آدرس‌دهی index.php/…' : 'index.php/… addressing',
            default => $language === 'fa' ? 'آدرس‌دهی ?r= بدون نیاز به Rewrite' : '?r= addressing, no rewrite needed',
        };
    }

    /** @return array{name:string,ok:bool,detail_fa:string,detail_en:string} */
    private function step(string $name, string $fa, string $en): array
    {
        return ['name' => $name, 'ok' => true, 'detail_fa' => $fa, 'detail_en' => $en];
    }

    private function prepareStorage(): void
    {
        foreach (['cache', 'logs', 'temp', 'sessions', 'locks', 'backups', 'downloads'] as $directory) {
            $path = $this->root . '/storage/' . $directory;
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
                throw new InstallerException('storage_unavailable', 'مسیرهای اجرایی storage ساخته نشدند. Permission پوشه برنامه را بررسی کنید.', 'Runtime storage directories could not be created. Check the application folder permissions.');
            }
            @chmod($path, 0700);
        }
        $deny = "Options -Indexes\nRequire all denied\n";
        if (@file_put_contents($this->root . '/storage/.htaccess', $deny, LOCK_EX) === false) {
            throw new InstallerException('storage_protection_failed', 'محافظت مسیر storage ساخته نشد. Permission فایل‌ها را بررسی کنید.', 'Storage protection could not be created. Check file permissions.');
        }
    }

    /** @return array<string,string> */
    private function existingEnvironment(): array
    {
        $path = $this->root . '/.env';
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $match)) {
                $values[$match[1]] = $match[2];
            }
        }
        return $values;
    }

    /**
     * Re-running the installer (for example after fixing a database error)
     * keeps the existing keys so previously encrypted data stays readable.
     *
     * @param array<string,string> $existing
     * @return array{app_key:string,encryption_key:string,webhook_secret:string,callback_secret:string,session_secret:string,cron_secret:string}
     */
    private function generateSecrets(array $existing): array
    {
        $token = static fn (int $bytes): string => rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        $keep = static fn (string $key, string $pattern, string $fallback): string => isset($existing[$key]) && preg_match($pattern, $existing[$key]) ? $existing[$key] : $fallback;
        return [
            'app_key' => $keep('APP_KEY', '#^base64:[A-Za-z0-9+/]{43}=$#', 'base64:' . base64_encode(random_bytes(32))),
            'encryption_key' => $keep('ENCRYPTION_KEY_V1', '#^base64:[A-Za-z0-9+/]{43}=$#', 'base64:' . base64_encode(random_bytes(32))),
            'webhook_secret' => $keep('WEBHOOK_SECRET', '/^[A-Za-z0-9_-]{40,64}$/', $token(32)),
            'callback_secret' => $keep('CALLBACK_SECRET', '/^[A-Za-z0-9_-]{40,64}$/', $token(32)),
            'session_secret' => $keep('SESSION_SECRET', '/^[A-Za-z0-9_-]{40,64}$/', $token(32)),
            'cron_secret' => $keep('CRON_SECRET', '/^[A-Za-z0-9_-]{40,64}$/', $token(32)),
        ];
    }

    private function writeLock(int $botId, string $username): void
    {
        $content = json_encode(['installed_at' => gmdate('c'), 'app_version' => Config::packageVersion(), 'bot_id' => $botId, 'bot_username' => $username], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($this->root . '/storage/installed.lock', $content . PHP_EOL, LOCK_EX) === false) {
            throw new InstallerException('installer_lock_write_failed', 'قفل نهایی نصب نوشته نشد. Permission مسیر storage را بررسی و دوباره تلاش کنید.', 'The final installation lock could not be written. Check storage permissions and retry.');
        }
        @chmod($this->root . '/storage/installed.lock', 0600);
    }

    /** @return resource */
    private function acquireInstallLock(): mixed
    {
        $directory = $this->root . '/storage/locks';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new InstallerException('installer_lock_unavailable', 'مسیر قفل نصب ساخته نشد. Permission پوشه برنامه را بررسی کنید.', 'The installer lock directory could not be created. Check the application folder permissions.');
        }
        @chmod($directory, 0700);
        $path = $directory . '/installer.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new InstallerException('installer_lock_unavailable', 'فایل قفل نصب باز نشد. Permission storage/locks را بررسی کنید.', 'The installer lock file could not be opened. Check storage/locks permissions.');
        }
        @chmod($path, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new InstallerException('installer_busy', 'یک فرایند نصب دیگر در حال اجراست. پس از پایان آن دوباره تلاش کنید.', 'Another installation process is running. Try again after it finishes.');
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['started_at' => gmdate('c'), 'pid' => getmypid()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        fflush($handle);
        return $handle;
    }

    /** @param resource $lock */
    private function releaseInstallLock(mixed $lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Current configuration needed by the Setup page (never secrets). */
    public static function configuredUrls(): PublicUrl
    {
        return new PublicUrl((string) Env::get('APP_URL', ''), (string) Env::get('APP_URL_MODE', UrlContext::MODE_PRETTY));
    }
}
