<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Restores the bundled .htaccess files when they were lost during upload.
 *
 * Phone unzip tools and some upload paths skip hidden files. Without the
 * application's own .htaccess, a parent folder's rules (for example "remove
 * .php extension" redirects in public_html/.htaccess) take over the folder
 * and cause redirect loops, and private files lose their web protection.
 */
final class HtaccessGuard
{
    private const FILES = [
        '/.htaccess' => '/resources/server/root.htaccess',
        '/public/.htaccess' => '/resources/server/public.htaccess',
        '/public/miniapp/.htaccess' => '/resources/server/miniapp.htaccess',
    ];

    /** @return list<string> restored relative paths */
    public static function ensure(string $root): array
    {
        $restored = [];
        foreach (self::FILES as $target => $template) {
            if (is_file($root . $target) || !is_file($root . $template) || !is_dir(dirname($root . $target))) {
                continue;
            }
            if (@copy($root . $template, $root . $target)) {
                @chmod($root . $target, 0644);
                $restored[] = ltrim($target, '/');
            }
        }
        return $restored;
    }
}
