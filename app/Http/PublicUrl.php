<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Env;

/**
 * Builds externally reachable URLs (Telegram webhook, Mini App buttons,
 * download links) outside of a request, from the address that was detected
 * and verified during installation or the last Setup repair.
 */
final class PublicUrl
{
    public readonly string $baseUrl;
    public readonly string $mode;

    public function __construct(string $baseUrl, string $mode = UrlContext::MODE_PRETTY)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->mode = in_array($mode, UrlContext::MODES, true) ? $mode : UrlContext::MODE_PRETTY;
    }

    public static function fromEnv(): self
    {
        return new self((string) Env::get('APP_URL', ''), (string) Env::get('APP_URL_MODE', UrlContext::MODE_PRETTY));
    }

    /** @param array<string,scalar|null> $query */
    public function to(string $route, array $query = []): string
    {
        return UrlContext::compose($this->baseUrl, $route, $query, $this->mode);
    }

    /** @param array<string,scalar|null> $query */
    public function miniApp(array $query = []): string
    {
        return $this->to('/miniapp/', $query);
    }

    public function webhook(string $secret): string
    {
        return $this->to('/webhook/' . $secret);
    }

    public function download(string $token): string
    {
        return $this->to('/download/' . rawurlencode($token));
    }

    public function isHttps(): bool
    {
        return str_starts_with($this->baseUrl, 'https://');
    }
}
