<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
        private readonly ?string $streamFile = null,
        private readonly bool $deleteAfterStream = false,
    ) {
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function download(string $path, string $filename, string $contentType, bool $deleteAfter = false): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The download source must be a readable regular file.');
        }
        $size = filesize($path);
        if ($size === false) {
            throw new InvalidArgumentException('The download source size cannot be determined.');
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)) ?: 'download.bin';
        $contentType = strtolower(trim(preg_replace('/[\r\n]/', '', $contentType) ?? ''));
        if (!preg_match('#^[a-z0-9!#$&^_.+\-]+/[a-z0-9!#$&^_.+\-]+(?:\s*;\s*charset=[a-z0-9._\-]+)?$#i', $contentType)) {
            $contentType = 'application/octet-stream';
        }
        return new self('', 200, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode(basename($filename)),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], $path, $deleteAfter);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if ($this->streamFile !== null) {
            $handle = fopen($this->streamFile, 'rb');
            if ($handle !== false) {
                while (!feof($handle)) {
                    $chunk = fread($handle, 1_048_576);
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
                fclose($handle);
            }
            if ($this->deleteAfterStream) {
                @unlink($this->streamFile);
            }
        } else {
            echo $this->body;
        }
        exit;
    }
}
