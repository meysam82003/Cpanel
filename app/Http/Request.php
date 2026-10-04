<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AppException;

final class Request
{
    /** @param array<string, mixed> $query
     *  @param array<string, mixed> $body
     *  @param array<string, string> $headers
     *  @param array<string, mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly array $files,
        public readonly string $rawBody,
        public readonly ?string $ip,
    ) {
    }

    /** Captures the current request; $path is the routed path resolved by UrlContext. */
    public static function capture(string $path): self
    {
        $raw = file_get_contents('php://input') ?: '';
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        $body = $_POST;
        if ($raw !== '' && (str_contains($contentType, 'application/json') || ($body === [] && ($raw[0] ?? '') === '{'))) {
            if (strlen($raw) > 1_048_576) {
                throw new AppException('JSON request body exceeds the 1 MiB limit.', 413, 'request_body_too_large');
            }
            try {
                $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new AppException('JSON request body is malformed.', 400, 'invalid_json');
            }
            if (!is_array($decoded)) {
                throw new AppException('JSON request body must be an object.', 400, 'invalid_json');
            }
            $body = $decoded;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        // PHP-FPM/CGI behind Apache drop Authorization unless it is re-exported.
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!isset($headers['authorization']) && is_string($_SERVER[$key] ?? null) && $_SERVER[$key] !== '') {
                $headers['authorization'] = (string) $_SERVER[$key];
            }
        }
        if (!isset($headers['authorization']) && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (is_string($name) && strtolower($name) === 'authorization' && is_string($value)) {
                    $headers['authorization'] = $value;
                }
            }
        }
        $query = $_GET;
        unset($query['r']);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $query,
            $body,
            $headers,
            $_FILES,
            $raw,
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
