<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Finds the command-line PHP binary that matches the web PHP version.
 *
 * Under a web SAPI, PHP_BINARY points at php-fpm, lsphp or php-cgi, which
 * cannot run cron scripts. cPanel, CloudLinux and LiteSpeed install the CLI
 * binary at predictable sibling paths.
 */
final class PhpBinary
{
    /** @param null|callable(string):bool $isExecutable */
    public static function cli(?string $hint = null, ?string $version = null, ?callable $isExecutable = null): string
    {
        $hint = $hint ?? (PHP_BINARY ?: '');
        $version = $version ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $isExecutable ??= static fn (string $path): bool => @is_file($path) && @is_executable($path);
        $compact = str_replace('.', '', $version);

        $candidates = [];
        if ($hint !== '' && preg_match('#(?:^|/)php(?:\d+(?:\.\d+)?)?$#', $hint)) {
            $candidates[] = $hint;
        }
        if ($hint !== '') {
            $directory = dirname($hint);
            $candidates[] = $directory . '/php';
            $candidates[] = dirname($directory) . '/bin/php';
            $candidates[] = (string) preg_replace('#/sbin/php-fpm$#', '/bin/php', $hint);
        }
        array_push(
            $candidates,
            '/opt/cpanel/ea-php' . $compact . '/root/usr/bin/php',
            '/opt/alt/php' . $compact . '/usr/bin/php',
            '/usr/local/lsws/lsphp' . $compact . '/bin/php',
            '/usr/bin/php' . $version,
            '/usr/local/bin/php' . $version,
            '/usr/local/bin/php',
            '/usr/bin/php',
        );
        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($candidate !== '' && $candidate !== $hint . '/php' && preg_match('#/php[\d.]*$#', $candidate) && $isExecutable($candidate)) {
                return $candidate;
            }
        }
        return '/usr/local/bin/php';
    }
}
