<?php

declare(strict_types=1);

/*
 * Telegram cPanel Manager — single front controller.
 *
 * Upload the files to any folder (domain root or a sub-folder) and open that
 * address in a browser; the installer detects everything else. No path,
 * RewriteBase or URL needs to be configured.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Telegram cPanel Manager requires PHP 8.2 or newer. Current version: ' . PHP_VERSION . "\nSelect PHP 8.2+ in cPanel → Select PHP Version / MultiPHP Manager.";
    exit;
}

require __DIR__ . '/bootstrap/autoload.php';

(new App\Web\FrontController(__DIR__, defined('TCPM_PUBLIC_ENTRY')))->run();
