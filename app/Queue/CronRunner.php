<?php

declare(strict_types=1);

namespace App\Queue;

use App\Backup\BackupService;
use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Notifications\NotificationService;
use App\Telegram\TelegramSetupService;
use App\Telegram\UpdateProcessor;

/**
 * One bounded scheduler run, shared by the CLI cron entry point and the
 * authenticated web-cron URL. In polling mode it also receives Telegram
 * updates, interleaved with queue work, for the whole time budget.
 */
final class CronRunner
{
    public function __construct(private readonly Container $container)
    {
    }

    /** @return array<string,mixed> heartbeat */
    public function run(int $budgetSeconds, string $trigger = 'cli'): array
    {
        $started = time();
        $deadline = $started + max(10, $budgetSeconds);
        $database = $this->container->get(Database::class);
        $logger = $this->container->get(Logger::class);
        $worker = $this->container->get(QueueWorker::class);
        $setting = $database->one("SELECT setting_value FROM settings WHERE setting_key = 'worker_max_jobs'");
        $configured = $setting === null ? null : json_decode((string) $setting['setting_value'], true);
        $maxJobs = is_int($configured) ? max(1, min(100, $configured)) : 20;

        $telegram = $this->container->get(TelegramSetupService::class);
        $monitor = [];
        try {
            $monitor = $telegram->monitor();
        } catch (\Throwable $exception) {
            $logger->error($exception, ['component' => 'telegram_monitor']);
            $monitor = ['error' => 'telegram_unreachable'];
        }

        $transport = $telegram->transport();
        $processed = 0;
        $updates = 0;
        if ($transport === TelegramSetupService::TRANSPORT_POLLING) {
            $processor = $this->container->get(UpdateProcessor::class);
            $handle = static fn (array $update): string => $processor->process($update);
            while ($deadline - time() > 6) {
                try {
                    $updates += $telegram->poll(min(15, $deadline - time() - 5), $handle);
                } catch (\Throwable $exception) {
                    $logger->error($exception, ['component' => 'telegram_polling']);
                    sleep(2);
                }
                $processed += $worker->work('default', min($maxJobs, 5), max(1, min(8, $deadline - time() - 4)));
            }
        } else {
            $processed = $worker->work('default', $maxJobs, max(5, $deadline - time() - 5));
        }

        $backupReconciliation = $this->container->get(BackupService::class)->reconcilePendingFull(20);
        $notificationSetting = $database->one("SELECT setting_value FROM settings WHERE setting_key = 'notification_batch'");
        $notificationValue = $notificationSetting === null ? null : json_decode((string) $notificationSetting['setting_value'], true);
        $notificationBatch = is_int($notificationValue) ? max(1, min(100, $notificationValue)) : 25;
        $notifications = $this->container->get(NotificationService::class)->deliverPending($notificationBatch);
        $cleanup = $this->container->get(CleanupService::class)->run();
        $heartbeat = [
            'ran_at' => gmdate('c'),
            'trigger' => $trigger,
            'duration_seconds' => time() - $started,
            'telegram_transport' => $transport,
            'telegram_updates' => $updates,
            'telegram_monitor' => $monitor,
            'processed_jobs' => $processed,
            'backup_reconciliation' => $backupReconciliation,
            'sent_notifications' => $notifications,
            'cleanup' => $cleanup,
        ];
        $database->execute('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)', ['cron_heartbeat', json_encode($heartbeat, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        return $heartbeat;
    }

    /** @return resource|null An exclusive lock, or null when another run is active. */
    public static function lock(string $root): mixed
    {
        $directory = $root . '/storage/locks';
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $path = $directory . '/cron.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return null;
        }
        @chmod($path, 0600);
        return $handle;
    }

    /** @param resource|null $handle */
    public static function unlock(mixed $handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
