<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Core\Database;
use App\Http\PublicUrl;
use Closure;

/**
 * Owns every Telegram-side registration (webhook, commands, menu button,
 * descriptions) and the delivery transport.
 *
 * Two transports are supported and switched automatically:
 *  - webhook: Telegram pushes updates to the public HTTPS address;
 *  - polling: the one-minute cron pulls updates with getUpdates, which keeps
 *    the bot working when HTTPS, the certificate, the port or the firewall
 *    prevent Telegram from reaching the server.
 */
final class TelegramSetupService
{
    public const TRANSPORT_WEBHOOK = 'webhook';
    public const TRANSPORT_POLLING = 'polling';
    public const ALLOWED_UPDATES = ['message', 'callback_query', 'my_chat_member'];

    private const SETTING_TRANSPORT = 'telegram_transport';
    private const SETTING_OFFSET = 'telegram_poll_offset';
    private const SETTING_MONITOR = 'telegram_monitor';
    private const MONITOR_INTERVAL = 300;
    private const FAILOVER_AFTER = 900;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ?Database $database,
        private readonly PublicUrl $urls,
        private readonly string $webhookSecret,
    ) {
    }

    /**
     * @return array{transport:string,steps:list<array{name:string,ok:bool,detail_fa:string,detail_en:string}>,warnings:list<array{fa:string,en:string}>}
     */
    public function configure(string $preferred = self::TRANSPORT_WEBHOOK): array
    {
        $steps = [];
        $warnings = [];

        foreach ([null, 'fa', 'en'] as $languageCode) {
            $parameters = ['commands' => self::commands($languageCode)];
            if ($languageCode !== null) {
                $parameters['language_code'] = $languageCode;
            }
            $this->telegram->call('setMyCommands', $parameters);
        }
        $steps[] = ['name' => 'Bot commands', 'ok' => true, 'detail_fa' => 'دستورات ربات (فارسی و انگلیسی) ثبت شدند', 'detail_en' => 'Bot commands registered (Persian and English)'];

        if ($this->urls->isHttps()) {
            $this->telegram->call('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => 'cPanel', 'web_app' => ['url' => $this->urls->miniApp()]]]);
            $steps[] = ['name' => 'Mini App menu button', 'ok' => true, 'detail_fa' => $this->urls->miniApp(), 'detail_en' => $this->urls->miniApp()];
        } else {
            $this->telegram->call('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
            $warnings[] = ['fa' => 'آدرس سایت HTTPS نیست؛ تلگرام Mini App را فقط روی HTTPS باز می‌کند. ربات کار می‌کند ولی دکمه پنل تا فعال شدن SSL پنهان است.', 'en' => 'The site is not served over HTTPS; Telegram opens Mini Apps only over HTTPS. The bot works, but the panel button stays hidden until SSL is enabled.'];
        }

        foreach (['fa' => ['مدیریت امن چند هاست cPanel از داخل تلگرام: فایل، دیتابیس، دامنه، ایمیل، SSL، کرون، بکاپ و دیپلوی.', 'مدیریت امن cPanel در تلگرام'], 'en' => ['Securely manage multiple cPanel hosts from Telegram: files, databases, domains, email, SSL, cron, backups and deployments.', 'Secure cPanel management in Telegram']] as $languageCode => [$description, $short]) {
            try {
                $this->telegram->call('setMyDescription', ['description' => $description, 'language_code' => $languageCode], 15);
                $this->telegram->call('setMyShortDescription', ['short_description' => $short, 'language_code' => $languageCode], 15);
            } catch (\Throwable) {
                // Descriptions are cosmetic; never block setup on them.
            }
        }

        $transport = self::TRANSPORT_POLLING;
        $reason = 'requested';
        if ($preferred === self::TRANSPORT_WEBHOOK) {
            if (!$this->urls->isHttps()) {
                $reason = 'https_unavailable';
            } else {
                try {
                    $this->registerWebhook();
                    $transport = self::TRANSPORT_WEBHOOK;
                    $reason = 'verified';
                    $steps[] = ['name' => 'Webhook', 'ok' => true, 'detail_fa' => 'Webhook ثبت و توسط تلگرام تأیید شد', 'detail_en' => 'Webhook registered and confirmed by Telegram'];
                } catch (TelegramApiException $exception) {
                    $reason = 'webhook_rejected';
                    $warnings[] = ['fa' => 'تلگرام Webhook را نپذیرفت (' . $exception->getMessage() . '). ربات به‌صورت خودکار روی حالت Polling از طریق Cron تنظیم شد و بدون Webhook هم کار می‌کند.', 'en' => 'Telegram rejected the webhook (' . $exception->getMessage() . '). The bot was switched automatically to cron-driven polling and works without a webhook.'];
                }
            }
        }
        if ($transport === self::TRANSPORT_POLLING) {
            $this->telegram->call('deleteWebhook', ['drop_pending_updates' => false]);
            $steps[] = ['name' => 'Polling transport', 'ok' => true, 'detail_fa' => 'دریافت پیام‌ها از طریق Cron هر دقیقه (getUpdates)', 'detail_en' => 'Updates are pulled by the one-minute cron (getUpdates)'];
        }
        $this->saveTransport($transport, $reason);
        return ['transport' => $transport, 'steps' => $steps, 'warnings' => $warnings];
    }

    public function registerWebhook(): void
    {
        $url = $this->urls->webhook($this->webhookSecret);
        $this->telegram->call('setWebhook', [
            'url' => $url,
            'secret_token' => $this->webhookSecret,
            'allowed_updates' => self::ALLOWED_UPDATES,
            'max_connections' => 40,
            'drop_pending_updates' => false,
        ]);
        $info = $this->telegram->call('getWebhookInfo');
        if (!is_array($info) || !hash_equals($url, (string) ($info['url'] ?? ''))) {
            throw new TelegramApiException('Telegram did not confirm the registered webhook URL', 'telegram_webhook_verification_failed');
        }
    }

    public function transport(): string
    {
        $value = $this->setting(self::SETTING_TRANSPORT);
        return is_array($value) && ($value['mode'] ?? null) === self::TRANSPORT_POLLING ? self::TRANSPORT_POLLING : self::TRANSPORT_WEBHOOK;
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $transport = $this->setting(self::SETTING_TRANSPORT);
        $status = [
            'transport' => $this->transport(),
            'transport_reason' => is_array($transport) ? (string) ($transport['reason'] ?? '') : '',
            'transport_changed_at' => is_array($transport) ? ($transport['changed_at'] ?? null) : null,
            'miniapp_url' => $this->urls->isHttps() ? $this->urls->miniApp() : null,
            'reachable' => false,
        ];
        try {
            $bot = $this->telegram->call('getMe', [], 15);
            $info = $this->telegram->call('getWebhookInfo', [], 15);
            $status['reachable'] = true;
            $status['bot_username'] = is_array($bot) ? (string) ($bot['username'] ?? '') : '';
            if (is_array($info)) {
                $status['webhook_registered'] = (string) ($info['url'] ?? '') !== '';
                $status['webhook_matches'] = hash_equals($this->urls->webhook($this->webhookSecret), (string) ($info['url'] ?? ''));
                $status['pending_updates'] = (int) ($info['pending_update_count'] ?? 0);
                $status['last_error_at'] = isset($info['last_error_date']) ? gmdate('c', (int) $info['last_error_date']) : null;
                $status['last_error_message'] = isset($info['last_error_message']) ? mb_substr((string) $info['last_error_message'], 0, 300) : null;
            }
        } catch (\Throwable $exception) {
            $status['error'] = mb_substr($exception->getMessage(), 0, 300);
        }
        return $status;
    }

    /**
     * Long-polls Telegram for up to $seconds and hands each update to $process.
     *
     * @param Closure(array<string,mixed>):mixed $process
     */
    public function poll(int $seconds, Closure $process): int
    {
        if ($seconds < 2) {
            return 0;
        }
        $deadline = time() + $seconds;
        $offset = $this->setting(self::SETTING_OFFSET);
        $offset = is_int($offset) ? $offset : 0;
        $handled = 0;
        while (($remaining = $deadline - time()) >= 2) {
            $wait = max(1, min(20, $remaining - 1));
            try {
                $updates = $this->telegram->call('getUpdates', ['offset' => $offset, 'timeout' => $wait, 'limit' => 50, 'allowed_updates' => self::ALLOWED_UPDATES], $wait + 15);
            } catch (TelegramApiException $exception) {
                if ((int) ($exception->context['error_code'] ?? 0) === 409) {
                    // A webhook is still registered; polling requires removing it.
                    $this->telegram->call('deleteWebhook', ['drop_pending_updates' => false]);
                    continue;
                }
                throw $exception;
            }
            if (!is_array($updates) || $updates === []) {
                continue;
            }
            foreach ($updates as $update) {
                if (!is_array($update) || !isset($update['update_id'])) {
                    continue;
                }
                $offset = max($offset, (int) $update['update_id'] + 1);
                $this->saveSetting(self::SETTING_OFFSET, $offset);
                $process($update);
                $handled++;
            }
        }
        return $handled;
    }

    /**
     * Cron health check (throttled): restores a webhook that was changed
     * elsewhere and fails over to polling when Telegram cannot deliver.
     *
     * @return array<string,mixed>
     */
    public function monitor(bool $force = false): array
    {
        $state = $this->setting(self::SETTING_MONITOR);
        $state = is_array($state) ? $state : [];
        $now = time();
        if (!$force && isset($state['checked_at']) && $now - (int) $state['checked_at'] < self::MONITOR_INTERVAL) {
            return ['skipped' => true];
        }
        $state['checked_at'] = $now;
        $action = 'none';
        if ($this->transport() === self::TRANSPORT_WEBHOOK) {
            $info = $this->telegram->call('getWebhookInfo', [], 15);
            $info = is_array($info) ? $info : [];
            $expected = $this->urls->webhook($this->webhookSecret);
            $lastError = isset($info['last_error_date']) ? (int) $info['last_error_date'] : 0;
            $pending = (int) ($info['pending_update_count'] ?? 0);
            if (!hash_equals($expected, (string) ($info['url'] ?? ''))) {
                try {
                    $this->registerWebhook();
                    $action = 'webhook_restored';
                } catch (\Throwable) {
                    $this->switchToPolling('webhook_restore_failed');
                    $action = 'failover_polling';
                }
                $state['failing_since'] = null;
            } elseif ($lastError > 0 && $now - $lastError < 600 && $pending > 0) {
                $state['failing_since'] = (int) ($state['failing_since'] ?? $now);
                $state['last_error'] = mb_substr((string) ($info['last_error_message'] ?? ''), 0, 300);
                if ($now - (int) $state['failing_since'] >= self::FAILOVER_AFTER) {
                    $this->switchToPolling('webhook_delivery_failing');
                    $action = 'failover_polling';
                    $state['failing_since'] = null;
                }
            } else {
                $state['failing_since'] = null;
            }
        }
        $state['action'] = $action;
        $this->saveSetting(self::SETTING_MONITOR, $state);
        return ['action' => $action];
    }

    public function switchToPolling(string $reason): void
    {
        $this->telegram->call('deleteWebhook', ['drop_pending_updates' => false]);
        $this->saveTransport(self::TRANSPORT_POLLING, $reason);
    }

    /** @return list<array{command:string,description:string}> */
    public static function commands(?string $languageCode = null): array
    {
        $descriptions = [
            'start' => ['Start / شروع', 'شروع و منوی اصلی', 'Start and main menu'],
            'panel' => ['Open control panel / باز کردن پنل', 'باز کردن پنل مدیریت', 'Open the control panel'],
            'hosts' => ['My hosts / هاست‌های من', 'هاست‌های من', 'My hosts'],
            'help' => ['Help / راهنما', 'راهنما', 'Help'],
            'security' => ['Security / امنیت', 'مرکز امنیت', 'Security center'],
            'settings' => ['Settings / تنظیمات', 'تنظیمات', 'Settings'],
            'cancel' => ['Cancel current operation / لغو عملیات', 'لغو عملیات جاری', 'Cancel the current operation'],
            'admin' => ['Super-admin panel / پنل مدیریت', 'پنل مدیریت کل', 'Super-admin panel'],
        ];
        $index = match ($languageCode) {
            'fa' => 1,
            'en' => 2,
            default => 0,
        };
        $commands = [];
        foreach ($descriptions as $command => $texts) {
            $commands[] = ['command' => $command, 'description' => $texts[$index]];
        }
        return $commands;
    }

    private function saveTransport(string $mode, string $reason): void
    {
        $this->saveSetting(self::SETTING_TRANSPORT, ['mode' => $mode, 'reason' => $reason, 'changed_at' => gmdate('c')]);
    }

    private function setting(string $key): mixed
    {
        if ($this->database === null) {
            return null;
        }
        $row = $this->database->one('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        return $row === null ? null : json_decode((string) $row['setting_value'], true);
    }

    private function saveSetting(string $key, mixed $value): void
    {
        $this->database?->execute('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
