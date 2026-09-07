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

    public static function capture(): self
    {
        $raw = file_get_contents('php://input') ?: '';
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        $body = $_POST;
        if (str_contains($contentType, 'application/json') && $raw !== '') {
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
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['HTTP_AUTHORIZATION'];
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (strlen($uri) > 2048 || preg_match('/%(?:00|2f|5c)/i', $uri)) {
            throw new AppException('Request path is invalid.', 400, 'invalid_request_path');
        }
        $decodedPath = rawurldecode($uri);
        if (preg_match('/[\x00-\x1F\x7F]/', $decodedPath)) {
            throw new AppException('Request path is invalid.', 400, 'invalid_request_path');
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . ltrim($decodedPath, '/'),
            $_GET,
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
