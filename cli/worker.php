<?php

declare(strict_types=1);

use App\Core\Container;
use App\Core\Env;
use App\Core\Logger;
use App\Queue\QueueWorker;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    Env::load($root . '/.env');
    date_default_timezone_set((string) (Env::get('APP_TIMEZONE', 'UTC') ?: 'UTC'));
    $options = getopt('', ['queue::', 'max-jobs::', 'max-seconds::']);
    $queue = isset($options['queue']) && is_string($options['queue']) && preg_match('/^[a-z][a-z0-9_.-]{0,49}$/', $options['queue']) ? $options['queue'] : 'default';
    $maxJobs = max(1, min(1000, (int) ($options['max-jobs'] ?? 100)));
    $maxSeconds = max(1, min(3500, (int) ($options['max-seconds'] ?? 300)));
    $container = new Container($root);
    $processed = $container->get(QueueWorker::class)->work($queue, $maxJobs, $maxSeconds);
    fwrite(STDOUT, json_encode(['ok' => true, 'processed' => $processed, 'queue' => $queue], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (\Throwable $exception) {
    try {
        (new Container($root))->get(Logger::class)->error($exception, ['command' => 'worker']);
    } catch (\Throwable) {
    }
    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'worker_failed'], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
