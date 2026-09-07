<?php

declare(strict_types=1);

namespace App\Installer;

use App\Accounts\UserRepository;
use App\Core\Database;
use App\Core\MigrationRunner;
use App\Help\HelpSeeder;
use App\Telegram\TelegramClient;
use PDO;
use RuntimeException;

final class InstallerService
{
    private const REQUIRED_EXTENSIONS = ['curl', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'zlib', 'zip'];

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{installed:bool,requirements:list<array{name:string,ok:bool,message_fa:string,message_en:string>>} */
    public function inspect(): array
    {
        $requirements = [[
            'name' => 'PHP >= 8.2',
            'ok' => PHP_VERSION_ID >= 80200,
            'message_fa' => PHP_VERSION,
            'message_en' => PHP_VERSION,
        ]];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $requirements[] = [
                'name' => 'ext-' . $extension,
                'ok' => extension_loaded($extension),
                'message_fa' => extension_loaded($extension) ? 'فعال' : 'نصب/فعال نیست',
                'message_en' => extension_loaded($extension) ? 'Enabled' : 'Missing or disabled',
            ];
        }
        $requirements[] = [
            'name' => 'Project directory writable',
            'ok' => is_writable($this->root),
            'message_fa' => is_writable($this->root) ? 'قابل نوشتن' : 'Permission نوشتن ندارد',
            'message_en' => is_writable($this->root) ? 'Writable' : 'Not writable',
        ];
        return ['installed' => is_file($this->root . '/storage/installed.lock'), 'requirements' => $requirements];
    }

    /**
     * @param array{bot_token:string,super_admin_id:string,db_username:string,db_password:string,db_name:string} $input
     * @param array<string,string> $server
     * @return array{steps:list<array{name:string,ok:bool,detail_fa:string,detail_en:string>>,bot:array<string,mixed>,app_url:string,miniapp_url:string,webhook_url:string,cron_command:string}
     */
    public function install(array $input, array $server): array
    {
        if (is_file($this->root . '/storage/installed.lock')) {
            throw new RuntimeException('Installer is locked. Remove storage/installed.lock manually to reinstall.');
        }
        $steps = [];
        $inspection = $this->inspect();
        foreach ($inspection['requirements'] as $requirement) {
            $steps[] = ['name' => $requirement['name'], 'ok' => $requirement['ok'], 'detail_fa' => $requirement['message_fa'], 'detail_en' => $requirement['message_en']];
            if (!$requirement['ok']) {
                throw new RuntimeException('Server requirement failed: ' . $requirement['name']);
            }
        }
        $validated = $this->validateInput($input);
        $urls = $this->detectUrls($server);
        $steps[] = ['name' => 'Public URL / HTTPS', 'ok' => true, 'detail_fa' => $urls['app_url'], 'detail_en' => $urls['app_url']];

        $telegram = new TelegramClient($validated['bot_token']);
        $bot = $telegram->call('getMe');
        if (!is_array($bot) || empty($bot['id']) || empty($bot['username'])) {
            throw new RuntimeException('Telegram returned incomplete bot information.');
        }
        $steps[] = ['name' => 'Telegram Bot Token', 'ok' => true, 'detail_fa' => '@' . $bot['username'] . ' تأیید شد', 'detail_en' => '@' . $bot['username'] . ' verified'];

        $pdo = new PDO(
            sprintf('mysql:host=localhost;port=3306;dbname=%s;charset=utf8mb4', $validated['db_name']),
            $validated['db_username'],
            $validated['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->query('SELECT 1')->fetchColumn();
        $steps[] = ['name' => 'MySQL / MariaDB', 'ok' => true, 'detail_fa' => 'اتصال با localhost برقرار شد', 'detail_en' => 'Connected through localhost'];

        $this->prepareStorage();
        $steps[] = ['name' => 'Storage', 'ok' => true, 'detail_fa' => 'مسیرهای امن ساخته شدند', 'detail_en' => 'Secure runtime directories created'];

        $secrets = $this->generateSecrets();
        $environment = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $urls['app_url'],
            'APP_TIMEZONE' => 'UTC',
            'APP_KEY' => 'base64:' . base64_encode($secrets['app_key']),
            'APP_VERSION' => '1.0.0',
            'ENCRYPTION_KEY_V1' => 'base64:' . base64_encode($secrets['encryption_key']),
            'ENCRYPTION_CURRENT_VERSION' => '1',
            'WEBHOOK_SECRET' => $secrets['webhook_secret'],
            'CALLBACK_SECRET' => $secrets['callback_secret'],
            'SESSION_SECRET' => $secrets['session_secret'],
            'CRON_SECRET' => $secrets['cron_secret'],
            'TELEGRAM_BOT_TOKEN' => $validated['bot_token'],
            'TELEGRAM_BOT_USERNAME' => (string) $bot['username'],
            'SUPER_ADMIN_TELEGRAM_ID' => (string) $validated['super_admin_id'],
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
            'DB_NAME' => $validated['db_name'],
            'DB_USER' => $validated['db_username'],
            'DB_PASSWORD_B64' => base64_encode($validated['db_password']),
            'DB_CHARSET' => 'utf8mb4',
            'ALLOW_PRIVATE_CPANEL_HOSTS' => 'false',
            'CPANEL_ALLOWED_PORTS' => '2083,443',
            'CPANEL_CONNECT_TIMEOUT' => '8',
            'CPANEL_REQUEST_TIMEOUT' => '30',
            'TELEGRAM_INITDATA_TTL' => '900',
            'MINIAPP_SESSION_TTL' => '3600',
            'QUEUE_STALE_AFTER_SECONDS' => '3600',
            'MAX_UPLOAD_BYTES' => '20971520',
            'TELEGRAM_SEND_MAX_BYTES' => '50000000',
            'LOG_LEVEL' => 'warning',
        ];
        $webhookUrl = $urls['app_url'] . '/webhook/' . $secrets['webhook_secret'];
        $miniAppUrl = $urls['app_url'] . '/miniapp/';
        $this->writeEnvironment($environment);
        $steps[] = ['name' => 'Security keys / configuration', 'ok' => true, 'detail_fa' => 'کلیدها تولید و فایل با Permission محدود ذخیره شد', 'detail_en' => 'Keys generated and configuration stored with restricted permissions'];

        $runner = new MigrationRunner($pdo);
        $migrations = $runner->migrate($this->root . '/database/migrations');
        $runner->seed($this->root . '/database/seeds');
        $database = Database::fromPdo($pdo);
        $helpCount = (new HelpSeeder($database))->seed($this->root . '/resources/help/topics.php');
        $steps[] = ['name' => 'Database schema', 'ok' => true, 'detail_fa' => count($migrations) . ' migration و ' . $helpCount . ' راهنما آماده شد', 'detail_en' => count($migrations) . ' migrations and ' . $helpCount . ' help topics prepared'];

        $users = new UserRepository($database);
        $admin = $users->upsertTelegram(['id' => $validated['super_admin_id']], 'fa');
        $database->execute('UPDATE users SET is_super_admin = 1, status = \'active\' WHERE id = ?', [$admin['id']]);
        $database->execute("UPDATE user_plans SET plan_id = (SELECT id FROM plans WHERE slug = 'business') WHERE user_id = ? AND status = 'active'", [$admin['id']]);
        $steps[] = ['name' => 'Super Admin', 'ok' => true, 'detail_fa' => 'Telegram ID ثبت شد', 'detail_en' => 'Telegram ID registered'];

        $telegram->call('setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secrets['webhook_secret'],
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
            'drop_pending_updates' => false,
        ]);
        $commands = [
            ['command' => 'start', 'description' => 'Start / شروع'],
            ['command' => 'panel', 'description' => 'Open control panel / باز کردن پنل'],
            ['command' => 'hosts', 'description' => 'My hosts / هاست‌های من'],
            ['command' => 'help', 'description' => 'Help / راهنما'],
            ['command' => 'security', 'description' => 'Security / امنیت'],
            ['command' => 'settings', 'description' => 'Settings / تنظیمات'],
            ['command' => 'cancel', 'description' => 'Cancel current operation / لغو عملیات'],
            ['command' => 'admin', 'description' => 'Super-admin panel / پنل مدیریت'],
        ];
        $telegram->call('setMyCommands', ['commands' => $commands]);
        $telegram->call('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => 'cPanel Panel', 'web_app' => ['url' => $miniAppUrl]]]);
        $webhookInfo = $telegram->call('getWebhookInfo');
        if (!is_array($webhookInfo) || ($webhookInfo['url'] ?? '') !== $webhookUrl) {
            throw new RuntimeException('Telegram webhook registration could not be verified.');
        }
        $steps[] = ['name' => 'Telegram Webhook / Menu Button', 'ok' => true, 'detail_fa' => 'Webhook و دکمه Mini App ثبت شدند', 'detail_en' => 'Webhook and Mini App menu button registered'];

        $this->writeLock((int) $bot['id'], (string) $bot['username']);
        $steps[] = ['name' => 'Installer lock', 'ok' => true, 'detail_fa' => 'نصب قفل شد؛ Unlock فقط با حذف دستی فایل Lock ممکن است', 'detail_en' => 'Installer locked; only manual lock-file removal can unlock it'];
        $cronCommand = sprintf('%s %s/cli/cron.php --secret=%s', PHP_BINARY ?: '/usr/local/bin/php', $this->root, $secrets['cron_secret']);
        return compact('steps', 'bot') + ['app_url' => $urls['app_url'], 'miniapp_url' => $miniAppUrl, 'webhook_url' => $webhookUrl, 'cron_command' => $cronCommand];
    }

    /** @param array<string,string> $input
     *  @return array{bot_token:string,super_admin_id:int,db_username:string,db_password:string,db_name:string}
     */
    private function validateInput(array $input): array
    {
        $token = trim($input['bot_token'] ?? '');
        $admin = filter_var($input['super_admin_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $username = trim($input['db_username'] ?? '');
        $password = (string) ($input['db_password'] ?? '');
        $database = trim($input['db_name'] ?? '');
        if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,}$/', $token)) {
            throw new RuntimeException('Telegram Bot Token format is invalid.');
        }
        if ($admin === false) {
            throw new RuntimeException('Super Admin Telegram ID must be a positive numeric ID.');
        }
        foreach (['Database username' => $username, 'Database name' => $database] as $label => $value) {
            if (!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $value)) {
                throw new RuntimeException($label . ' contains unsupported characters.');
            }
        }
        if ($password === '' || strlen($password) > 1024 || str_contains($password, "\0")) {
            throw new RuntimeException('Database password is empty or invalid.');
        }
        return ['bot_token' => $token, 'super_admin_id' => (int) $admin, 'db_username' => $username, 'db_password' => $password, 'db_name' => $database];
    }

    /** @param array<string,string> $server
     *  @return array{app_url:string}
     */
    private function detectUrls(array $server): array
    {
        $forwarded = strtolower(trim(explode(',', $server['HTTP_X_FORWARDED_PROTO'] ?? '')[0] ?? ''));
        $https = ($server['HTTPS'] ?? '') === 'on' || ($server['SERVER_PORT'] ?? '') === '443' || $forwarded === 'https';
        if (!$https) {
            throw new RuntimeException('HTTPS is required before Telegram webhook installation.');
        }
        $host = strtolower(trim($server['HTTP_HOST'] ?? ''));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::\d{1,5})?$/', $host)) {
            throw new RuntimeException('The detected public hostname is invalid.');
        }
        $hostParts = parse_url('https://' . $host);
        $hostname = is_array($hostParts) ? strtolower((string) ($hostParts['host'] ?? '')) : '';
        $port = is_array($hostParts) && isset($hostParts['port']) ? (int) $hostParts['port'] : 443;
        if ((!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && !filter_var($hostname, FILTER_VALIDATE_IP)) || $port < 1 || $port > 65535) {
            throw new RuntimeException('The detected public hostname or port is invalid.');
        }
        $uri = parse_url($server['REQUEST_URI'] ?? '/install', PHP_URL_PATH) ?: '/install';
        $basePath = preg_replace('#/(?:public/)?install(?:\.php)?/?$#i', '', $uri) ?? '';
        $basePath = '/' . trim($basePath, '/');
        $basePath = $basePath === '/' ? '' : $basePath;
        return ['app_url' => 'https://' . $host . $basePath];
    }

    private function prepareStorage(): void
    {
        foreach (['cache', 'logs', 'temp', 'sessions', 'locks', 'backups', 'downloads'] as $directory) {
            $path = $this->root . '/storage/' . $directory;
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException('Cannot create storage directory: ' . $directory);
            }
            @chmod($path, 0700);
        }
        $deny = "Options -Indexes\nRequire all denied\n";
        if (file_put_contents($this->root . '/storage/.htaccess', $deny, LOCK_EX) === false) {
            throw new RuntimeException('Cannot protect storage from web access.');
        }
    }

    /** @return array{app_key:string,encryption_key:string,webhook_secret:string,callback_secret:string,session_secret:string,cron_secret:string} */
    private function generateSecrets(): array
    {
        $token = static fn (int $bytes): string => rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        return [
            'app_key' => random_bytes(32),
            'encryption_key' => random_bytes(32),
            'webhook_secret' => $token(32),
            'callback_secret' => $token(32),
            'session_secret' => $token(32),
            'cron_secret' => $token(32),
        ];
    }

    /** @param array<string,string> $values */
    private function writeEnvironment(array $values): void
    {
        $lines = ['# Generated by the locked web installer on ' . gmdate('c')];
        foreach ($values as $key => $value) {
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException('A configuration value contains a line break.');
            }
            $lines[] = $key . '=' . $value;
        }
        $temporary = $this->root . '/storage/temp/env-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Configuration file could not be written.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->root . '/.env')) {
            @unlink($temporary);
            throw new RuntimeException('Configuration file could not be installed atomically.');
        }
        @chmod($this->root . '/.env', 0600);
    }

    private function writeLock(int $botId, string $username): void
    {
        $content = json_encode(['installed_at' => gmdate('c'), 'app_version' => '1.0.0', 'bot_id' => $botId, 'bot_username' => $username], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->root . '/storage/installed.lock', $content . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Installer lock could not be written.');
        }
        @chmod($this->root . '/storage/installed.lock', 0600);
    }
}
