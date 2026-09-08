<?php

declare(strict_types=1);

use App\Audit\AuditLogger;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\Core\MigrationRunner;
use App\Help\HelpSeeder;

$root = dirname(__DIR__);
require $root . '/bootstrap/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$lock = null;
try {
    if (!is_file($root . '/storage/installed.lock')) {
        throw new RuntimeException('The application is not installed.');
    }
    Env::load($root . '/.env');
    $options = getopt('', ['secret:', 'check']);
    $supplied = is_string($options['secret'] ?? null) ? $options['secret'] : '';
    if ($supplied === '' || !hash_equals(Env::require('CRON_SECRET'), $supplied)) {
        throw new RuntimeException('Update authentication failed.');
    }

    $container = new Container($root);
    $database = $container->get(Database::class);
    $migrationFiles = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
    sort($migrationFiles, SORT_NATURAL);
    $appliedRows = $database->all('SELECT version FROM schema_migrations ORDER BY version');
    $appliedVersions = array_map(static fn (array $row): string => (string) $row['version'], $appliedRows);
    $pending = array_values(array_diff($migrationFiles, $appliedVersions));

    if (array_key_exists('check', $options)) {
        fwrite(STDOUT, json_encode([
            'ok' => true,
            'mode' => 'check',
            'app_version' => Config::packageVersion(),
            'pending_migrations' => $pending,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit($pending === [] ? 0 : 2);
    }

    $lockPath = $root . '/storage/locks/update.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another update process is active.');
    }
    @chmod($lockPath, 0600);

    $runner = new MigrationRunner($database->pdo());
    $migrations = $runner->migrate($root . '/database/migrations');
    $runner->seed($root . '/database/seeds');
    $helpCount = (new HelpSeeder($database))->seed($root . '/resources/help/topics.php');

    $installedPath = $root . '/storage/installed.lock';
    $installed = json_decode((string) file_get_contents($installedPath), true);
    if (!is_array($installed)) {
        throw new RuntimeException('The installation lock is invalid.');
    }
    $installed['app_version'] = Config::packageVersion();
    $installed['updated_at'] = gmdate('c');
    $temporary = $root . '/storage/temp/installed-' . bin2hex(random_bytes(8)) . '.lock';
    $encoded = json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('The updated installation lock could not be written.');
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $installedPath)) {
        @unlink($temporary);
        throw new RuntimeException('The updated installation lock could not be installed atomically.');
    }
    @chmod($installedPath, 0600);

    $container->get(AuditLogger::class)->record(null, null, 'system.update', 'success', 'version', Config::packageVersion(), ['migrations' => $migrations, 'help_topics' => $helpCount]);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'mode' => 'apply',
        'app_version' => Config::packageVersion(),
        'applied_migrations' => $migrations,
        'help_topics' => $helpCount,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
} catch (Throwable $exception) {
    try {
        (new Container($root))->get(Logger::class)->error($exception, ['command' => 'update']);
    } catch (Throwable) {
    }
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => 'update_failed',
        'message_fa' => 'به‌روزرسانی کامل نشد. فایل‌ها، دسترسی storage و گزارش محافظت‌شدهٔ سرور را بررسی کنید.',
        'message_en' => 'The update did not complete. Check the release files, storage permissions, and protected server log.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
