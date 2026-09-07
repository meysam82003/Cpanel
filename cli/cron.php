<?php

declare(strict_types=1);

use App\Core\Container;
use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\Backup\BackupService;
use App\Notifications\NotificationService;
use App\Queue\CleanupService;
use App\Queue\QueueWorker;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$lock = null;
try {
    Env::load($root . '/.env');
    $options = getopt('', ['secret:']);
    $supplied = is_string($options['secret'] ?? null) ? $options['secret'] : '';
    if ($supplied === '' || !hash_equals(Env::require('CRON_SECRET'), $supplied)) {
        throw new RuntimeException('Cron authentication failed.');
    }
    $lockPath = $root . '/storage/locks/cron.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => 'already_running'], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }
    @chmod($lockPath, 0600);
    date_default_timezone_set((string) (Env::get('APP_TIMEZONE', 'UTC') ?: 'UTC'));
    $container = new Container($root);
    $database = $container->get(Database::class);
    $setting = $database->one("SELECT setting_value FROM settings WHERE setting_key = 'worker_max_jobs'");
    $configured = $setting === null ? null : json_decode((string) $setting['setting_value'], true);
    $maxJobs = is_int($configured) ? max(1, min(100, $configured)) : 20;
    $processed = $container->get(QueueWorker::class)->work('default', $maxJobs, 45);
    $backupReconciliation = $container->get(BackupService::class)->reconcilePendingFull(20);
    $notificationSetting = $database->one("SELECT setting_value FROM settings WHERE setting_key = 'notification_batch'");
    $notificationValue = $notificationSetting === null ? null : json_decode((string) $notificationSetting['setting_value'], true);
    $notificationBatch = is_int($notificationValue) ? max(1, min(100, $notificationValue)) : 25;
    $notifications = $container->get(NotificationService::class)->deliverPending($notificationBatch);
    $cleanup = $container->get(CleanupService::class)->run();
    $heartbeat = ['ran_at' => gmdate('c'), 'processed_jobs' => $processed, 'backup_reconciliation' => $backupReconciliation, 'sent_notifications' => $notifications, 'cleanup' => $cleanup];
    $database->execute("INSERT INTO settings (setting_key, setting_value) VALUES ('cron_heartbeat', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [json_encode($heartbeat, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    fwrite(STDOUT, json_encode(['ok' => true] + $heartbeat, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
} catch (\Throwable $exception) {
    try {
        (new Container($root))->get(Logger::class)->error($exception, ['command' => 'cron']);
    } catch (\Throwable) {
    }
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'cron_failed'], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
