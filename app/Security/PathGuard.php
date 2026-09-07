<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\AppException;

final class PathGuard
{
    public function normalize(string $path, string $root): string
    {
        $this->rejectAmbiguousEncoding($path);
        $root = $this->normalizeAbsolute($root);
        $candidate = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        $candidate = $this->normalizeAbsolute($candidate);
        $this->assertWithinRoot($candidate, $root);
        return $candidate;
    }

    public function assertWithinRoot(string $canonicalPath, string $root): void
    {
        $canonicalPath = rtrim($this->normalizeAbsolute($canonicalPath), '/') ?: '/';
        $root = rtrim($this->normalizeAbsolute($root), '/') ?: '/';
        if ($canonicalPath !== $root && !str_starts_with($canonicalPath . '/', $root . '/')) {
            throw new AppException('The requested path is outside the allowed account root.', 403, 'path_outside_root', [], 'security.path');
        }
    }

    public function sanitizeFilename(string $filename): string
    {
        $this->rejectAmbiguousEncoding($filename);
        $filename = trim(str_replace(['\\', '/'], '', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..' || strlen($filename) > 255) {
            throw new AppException('The file name is invalid.', 422, 'invalid_filename', [], 'files.upload');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $filename)) {
            throw new AppException('The file name contains unsafe characters.', 422, 'invalid_filename');
        }
        return $filename;
    }

    private function normalizeAbsolute(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new AppException('Path traversal was blocked.', 403, 'path_traversal_blocked', [], 'security.path');
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return '/' . implode('/', $parts);
    }

    private function rejectAmbiguousEncoding(string $value): void
    {
        if (str_contains($value, "\0") || preg_match('/%(?:00|2e|2f|5c)/i', $value)) {
            throw new AppException('Encoded or null-byte path traversal was blocked.', 403, 'path_traversal_blocked', [], 'security.path');
        }
        $decoded = rawurldecode($value);
        if ($decoded !== $value && rawurldecode($decoded) !== $decoded) {
            throw new AppException('Double-encoded paths are not accepted.', 403, 'path_traversal_blocked');
        }
    }
}

