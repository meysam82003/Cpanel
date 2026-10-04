<?php

declare(strict_types=1);

use App\Core\Container;
use App\Core\Env;
use App\Core\Logger;
use App\Queue\CronRunner;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$lock = null;
try {
    Env::load($root . '/.env');
    $options = getopt('', ['secret:', 'budget:']);
    $supplied = is_string($options['secret'] ?? null) ? $options['secret'] : '';
    if ($supplied === '' || !hash_equals(Env::require('CRON_SECRET'), $supplied)) {
        throw new RuntimeException('Cron authentication failed.');
    }
    $lock = CronRunner::lock($root);
    if ($lock === null) {
        fwrite(STDOUT, json_encode(['ok' => true, 'skipped' => 'already_running'], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }
    date_default_timezone_set('UTC');
    @set_time_limit(120);
    $budget = isset($options['budget']) && is_string($options['budget']) ? max(10, min(55, (int) $options['budget'])) : 50;
    $heartbeat = (new CronRunner(new Container($root)))->run($budget, 'cli');
    fwrite(STDOUT, json_encode(['ok' => true] + $heartbeat, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    CronRunner::unlock($lock);
    exit(0);
} catch (\Throwable $exception) {
    try {
        (new Container($root))->get(Logger::class)->error($exception, ['command' => 'cron']);
    } catch (\Throwable) {
    }
    CronRunner::unlock($lock);
    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'cron_failed'], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
