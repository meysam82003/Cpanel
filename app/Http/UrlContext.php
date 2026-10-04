<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AppException;

/**
 * Describes how the current HTTP request reached the application.
 *
 * Nothing here is configured: the installation folder, the front-controller
 * script, the public asset directory and the routed path are derived from the
 * request itself. This works at a domain root, in any sub-folder, with or
 * without URL rewriting (pretty, PATH_INFO or ?r= query routing), behind
 * TLS-terminating proxies, and with the document root pointed at either the
 * project directory or its public/ directory.
 */
final class UrlContext
{
    public const MODE_PRETTY = 'pretty';
    public const MODE_PATHINFO = 'pathinfo';
    public const MODE_QUERY = 'query';
    public const MODES = [self::MODE_PRETTY, self::MODE_PATHINFO, self::MODE_QUERY];

    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly string $basePath,
        public readonly string $entryPath,
        public readonly string $publicPath,
        public readonly string $routePath,
        public readonly string $mode,
    ) {
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $query
     */
    public static function fromGlobals(array $server, array $query, string $projectRoot, bool $publicEntry = false): self
    {
        $script = self::scriptName($server);
        $scriptDir = self::directoryOf($script);
        if ($publicEntry) {
            // public/index.php runs either because public/ is the document
            // root, or because the project directory is the web folder and the
            // request reached public/ directly.
            $publicPath = $scriptDir;
            $basePath = $scriptDir;
            if (!self::publicIsDocumentRoot($server, $projectRoot) && (self::publicParentIsProjectRoot($server, $scriptDir, $projectRoot) || str_ends_with($scriptDir, '/public'))) {
                $basePath = self::directoryOf($scriptDir);
            }
        } else {
            $basePath = $scriptDir;
            $publicPath = $basePath . '/public';
        }

        $path = self::requestPath($server);
        $route = '/';
        $mode = self::MODE_PRETTY;
        if ($path === $script || str_starts_with($path, $script . '/')) {
            $rest = substr($path, strlen($script));
            $route = $rest === '' ? '/' : $rest;
            $mode = $rest === '' ? self::MODE_QUERY : self::MODE_PATHINFO;
        } elseif ($basePath === '' || $path === $basePath || str_starts_with($path, $basePath . '/')) {
            $route = substr($path, strlen($basePath));
            $route = $route === '' ? '/' : $route;
        } else {
            $pathInfo = is_string($server['PATH_INFO'] ?? null) ? (string) $server['PATH_INFO'] : '';
            $route = $pathInfo !== '' ? self::normalizeRoute($pathInfo) : $path;
        }

        $queryRoute = $query['r'] ?? null;
        if ($route === '/' && is_string($queryRoute) && $queryRoute !== '') {
            $route = self::normalizeRoute($queryRoute);
            $mode = self::MODE_QUERY;
        }

        return new self(self::scheme($server), self::host($server), $basePath, $script, $publicPath, $route, $mode);
    }

    public function baseUrl(): string
    {
        return $this->scheme . '://' . $this->host . $this->basePath;
    }

    public function isHttps(): bool
    {
        return $this->scheme === 'https';
    }

    /** Builds a same-origin path for a route using the given (or current) routing mode. */
    public function path(string $route, array $query = [], ?string $mode = null): string
    {
        return self::compose($this->basePath, $route, $query, $mode ?? $this->mode);
    }

    public function url(string $route, array $query = [], ?string $mode = null): string
    {
        return $this->scheme . '://' . $this->host . $this->path($route, $query, $mode);
    }

    public function assetPath(string $file): string
    {
        return $this->publicPath . '/miniapp/' . ltrim($file, '/');
    }

    public function publicUrl(?string $mode = null): PublicUrl
    {
        return new PublicUrl($this->baseUrl(), $mode ?? $this->mode);
    }

    /** @param array<string,scalar|null> $query */
    public static function compose(string $base, string $route, array $query, string $mode): string
    {
        $route = '/' . ltrim($route, '/');
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
        if ($mode === self::MODE_QUERY) {
            return $base . '/index.php?' . http_build_query(['r' => $route] + $query, '', '&', PHP_QUERY_RFC3986);
        }
        $prefix = $mode === self::MODE_PATHINFO ? $base . '/index.php' : $base;
        $suffix = $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $prefix . $route . $suffix;
    }

    /** @param array<string,mixed> $server */
    private static function scriptName(array $server): string
    {
        $script = is_string($server['SCRIPT_NAME'] ?? null) ? (string) $server['SCRIPT_NAME'] : '';
        $pathInfo = is_string($server['PATH_INFO'] ?? null) ? (string) $server['PATH_INFO'] : '';
        if ($script === '' && is_string($server['PHP_SELF'] ?? null)) {
            $script = (string) $server['PHP_SELF'];
        }
        // Some FastCGI configurations append PATH_INFO to SCRIPT_NAME/PHP_SELF.
        if ($pathInfo !== '' && $script !== $pathInfo && str_ends_with($script, $pathInfo)) {
            $script = substr($script, 0, -strlen($pathInfo));
        }
        $script = '/' . ltrim(str_replace('\\', '/', $script), '/');
        $script = (string) preg_replace('#/{2,}#', '/', $script);
        if (!preg_match('#\.php$#i', $script) || preg_match('/[\x00-\x1F\x7F]/', $script)) {
            return '/index.php';
        }
        return $script;
    }

    /** @param array<string,mixed> $server */
    private static function requestPath(array $server): string
    {
        $uri = is_string($server['REQUEST_URI'] ?? null) ? (string) $server['REQUEST_URI'] : '/';
        $raw = parse_url('http://localhost' . (str_starts_with($uri, '/') ? $uri : '/' . $uri), PHP_URL_PATH);
        $raw = is_string($raw) && $raw !== '' ? $raw : '/';
        if (strlen($raw) > 2048 || preg_match('/%(?:00|2f|5c)/i', $raw)) {
            throw new AppException('Request path is invalid.', 400, 'invalid_request_path');
        }
        $decoded = '/' . ltrim(rawurldecode($raw), '/');
        if (preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
            throw new AppException('Request path is invalid.', 400, 'invalid_request_path');
        }
        return (string) preg_replace('#/{2,}#', '/', $decoded);
    }

    private static function normalizeRoute(string $route): string
    {
        if (strlen($route) > 1024 || preg_match('/[\x00-\x1F\x7F\\\\]/', $route)) {
            throw new AppException('Request path is invalid.', 400, 'invalid_request_path');
        }
        return (string) preg_replace('#/{2,}#', '/', '/' . ltrim($route, '/'));
    }

    private static function directoryOf(string $path): string
    {
        $directory = str_replace('\\', '/', dirname($path));
        return $directory === '/' || $directory === '.' ? '' : rtrim($directory, '/');
    }

    /** @param array<string,mixed> $server */
    private static function scheme(array $server): string
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if (($https !== '' && $https !== 'off' && $https !== '0') || (string) ($server['SERVER_PORT'] ?? '') === '443' || strtolower((string) ($server['REQUEST_SCHEME'] ?? '')) === 'https') {
            return 'https';
        }
        $forwarded = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $visitor = json_decode((string) ($server['HTTP_CF_VISITOR'] ?? ''), true);
        if ($forwarded === 'https' || strtolower((string) ($server['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on' || strtolower((string) ($server['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on' || (is_array($visitor) && ($visitor['scheme'] ?? null) === 'https')) {
            return 'https';
        }
        return 'http';
    }

    /** @param array<string,mixed> $server */
    private static function host(array $server): string
    {
        foreach ([(string) ($server['HTTP_HOST'] ?? ''), (string) ($server['SERVER_NAME'] ?? '')] as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate !== '' && preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\[[0-9a-f:.]+\])(?::\d{1,5})?$/', $candidate)) {
                $port = (int) (parse_url('http://' . $candidate, PHP_URL_PORT) ?? 0);
                if ($port <= 65535) {
                    return $candidate;
                }
            }
        }
        return 'localhost';
    }

    /** @param array<string,mixed> $server */
    private static function publicIsDocumentRoot(array $server, string $projectRoot): bool
    {
        $documentRoot = is_string($server['DOCUMENT_ROOT'] ?? null) ? realpath((string) $server['DOCUMENT_ROOT']) : false;
        $public = realpath($projectRoot . '/public');
        return $documentRoot !== false && $public !== false && $documentRoot === $public;
    }

    /** @param array<string,mixed> $server */
    private static function publicParentIsProjectRoot(array $server, string $scriptDir, string $projectRoot): bool
    {
        $documentRoot = is_string($server['DOCUMENT_ROOT'] ?? null) ? (string) $server['DOCUMENT_ROOT'] : '';
        if ($documentRoot === '' || $scriptDir === '') {
            return false;
        }
        $parent = realpath(rtrim($documentRoot, '/') . self::directoryOf($scriptDir));
        $root = realpath($projectRoot);
        return $parent !== false && $root !== false && $parent === $root;
    }
}
