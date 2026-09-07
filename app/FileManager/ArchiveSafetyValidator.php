<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Core\AppException;
use ZipArchive;

final class ArchiveSafetyValidator
{
    /** @return array{format:string,files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} */
    public function validate(string $path, ?string $originalName = null, int $maxFiles = 10_000, int $maxUncompressedBytes = 1_073_741_824, int $maxRatio = 200): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new AppException('Archive is not readable.', 422, 'invalid_archive', [], 'files.zip');
        }
        $maxFiles = max(1, min(100_000, $maxFiles));
        $maxUncompressedBytes = max(1_048_576, min(10_737_418_240, $maxUncompressedBytes));
        $maxRatio = max(2, min(1_000, $maxRatio));
        $format = $this->format($originalName ?? basename($path));
        $summary = match ($format) {
            'zip' => $this->zip($path, $maxFiles, $maxUncompressedBytes, $maxRatio),
            'tar', 'tar.gz', 'tar.bz2' => $this->tar($path, $format, $maxFiles, $maxUncompressedBytes, $maxRatio),
            'gz' => $this->singleCompressed($path, $originalName ?? basename($path), 'gz', $maxUncompressedBytes, $maxRatio),
            'bz2' => $this->singleCompressed($path, $originalName ?? basename($path), 'bz2', $maxUncompressedBytes, $maxRatio),
            default => throw new AppException('Archive format is not supported.', 415, 'unsupported_archive', [], 'files.zip'),
        };

        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new AppException('Archive checksum could not be calculated.', 500, 'archive_integrity_failed', [], 'files.zip');
        }
        return ['format' => $format, 'sha256' => $checksum] + $summary;
    }

    /** @return array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>} */
    private function zip(string $path, int $maxFiles, int $maxBytes, int $maxRatio): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new AppException('ZIP archive could not be opened.', 422, 'invalid_archive', [], 'files.zip');
        }
        try {
            $count = $zip->numFiles;
            if ($count < 1 || $count > $maxFiles) {
                throw new AppException('ZIP entry count exceeds the safety policy.', 413, 'archive_file_count_exceeded', ['files' => $count], 'files.zip');
            }
            $uncompressed = 0;
            $compressedPayload = 0;
            $top = [];
            $seen = [];
            for ($index = 0; $index < $count; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) {
                    throw new AppException('ZIP directory contains an unreadable entry.', 422, 'invalid_archive', [], 'files.zip');
                }
                $entry = $this->safeEntry((string) ($stat['name'] ?? ''));
                $identity = rtrim($entry, '/');
                if (isset($seen[$identity])) {
                    throw new AppException('ZIP contains duplicate output paths.', 422, 'archive_duplicate_path', ['entry' => mb_substr($entry, 0, 255)], 'files.zip');
                }
                $seen[$identity] = true;

                $operatingSystem = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                    $type = ($attributes >> 16) & 0xF000;
                    if (in_array($type, [0xA000, 0x2000, 0x6000, 0x1000, 0xC000], true)) {
                        throw new AppException('Links and special device entries are blocked in ZIP archives.', 403, 'archive_special_entry_blocked', ['entry' => mb_substr($entry, 0, 255)], 'files.zip');
                    }
                }
                if ((int) ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new AppException('Encrypted ZIP entries cannot be safely inspected.', 422, 'archive_encrypted_entry', ['entry' => mb_substr($entry, 0, 255)], 'files.zip');
                }
                $size = max(0, (int) ($stat['size'] ?? 0));
                $compressed = max(0, (int) ($stat['comp_size'] ?? 0));
                $uncompressed = $this->boundedAdd($uncompressed, $size, $maxBytes);
                if ($compressedPayload > PHP_INT_MAX - $compressed) {
                    throw new AppException('ZIP compressed-size metadata is outside the supported range.', 413, 'archive_size_exceeded', [], 'files.zip');
                }
                $compressedPayload += $compressed;
                if ($size > 0 && ($compressed === 0 || $size / $compressed > $maxRatio)) {
                    throw new AppException('ZIP entry exceeds the compression-ratio safety limit.', 413, 'zip_bomb_blocked', ['entry' => mb_substr($entry, 0, 255)], 'files.zip');
                }
                $this->topLevel($top, $entry);
            }
            if ($uncompressed > max(1, $compressedPayload) * $maxRatio) {
                throw new AppException('ZIP exceeds the total compression-ratio safety limit.', 413, 'zip_bomb_blocked', [], 'files.zip');
            }
            return ['files' => $count, 'compressed_bytes' => (int) filesize($path), 'uncompressed_bytes' => $uncompressed, 'top_level' => array_keys($top)];
        } finally {
            $zip->close();
        }
    }

    /** @return array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>} */
    private function tar(string $path, string $format, int $maxFiles, int $maxBytes, int $maxRatio): array
    {
        if ($format === 'tar.bz2' && !extension_loaded('bz2')) {
            throw new AppException('Bzip2 support is unavailable on this host.', 501, 'archive_bzip2_unavailable', [], 'files.zip');
        }
        $uri = match ($format) {
            'tar.gz' => 'compress.zlib://' . $path,
            'tar.bz2' => 'compress.bzip2://' . $path,
            default => $path,
        };
        $stream = @fopen($uri, 'rb');
        if ($stream === false) {
            throw new AppException('TAR archive could not be opened by this PHP installation.', 422, 'invalid_archive', [], 'files.zip');
        }
        $files = 0;
        $uncompressed = 0;
        $top = [];
        $seen = [];
        $nextLongName = null;
        $nextPax = [];
        $globalPax = [];
        try {
            while (true) {
                $header = $this->readExact($stream, 512, true);
                if ($header === null) {
                    break;
                }
                if (trim($header, "\0") === '') {
                    $end = $this->readExact($stream, 512, true);
                    if ($end !== null && trim($end, "\0") !== '') {
                        throw new AppException('TAR end marker is malformed.', 422, 'invalid_archive', [], 'files.zip');
                    }
                    $this->assertOnlyZeroPadding($stream);
                    break;
                }
                $this->verifyTarHeader($header);
                $headerSize = $this->tarNumber(substr($header, 124, 12));
                if ($headerSize > $maxBytes) {
                    throw new AppException('TAR entry exceeds the extraction size limit.', 413, 'archive_size_exceeded', [], 'files.zip');
                }
                $type = $header[156] === "\0" ? '0' : $header[156];
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $shortName = rtrim(substr($header, 0, 100), "\0");
                $headerName = $prefix !== '' ? $prefix . '/' . $shortName : $shortName;
                if (in_array($type, ['x', 'g', 'L'], true)) {
                    if ($headerSize > 1_048_576) {
                        throw new AppException('TAR metadata entry is too large.', 413, 'invalid_archive', [], 'files.zip');
                    }
                    $metadata = $this->readExact($stream, $headerSize, false) ?? '';
                    $this->readPadding($stream, $headerSize);
                    if ($type === 'L') {
                        $nextLongName = rtrim($metadata, "\0\r\n");
                        $this->safeEntry($nextLongName);
                    } else {
                        $parsed = $this->pax($metadata);
                        if (isset($parsed['path'])) {
                            $this->safeEntry($parsed['path']);
                        }
                        if ($this->containsUnsafePaxMetadata($parsed)) {
                            throw new AppException('PAX links and sparse files are blocked.', 403, 'archive_special_entry_blocked', [], 'files.zip');
                        }
                        if ($type === 'g') {
                            $globalPax = $parsed + $globalPax;
                        } else {
                            $nextPax = $parsed;
                        }
                    }
                    continue;
                }
                if ($type === 'K' || in_array($type, ['1', '2', '3', '4', '6', 'S'], true)) {
                    throw new AppException('TAR links, sparse files, and device entries are blocked.', 403, 'archive_special_entry_blocked', [], 'files.zip');
                }
                if (!in_array($type, ['0', '5', '7'], true)) {
                    throw new AppException('TAR contains an unsupported entry type.', 422, 'archive_special_entry_blocked', ['type' => ord($type)], 'files.zip');
                }
                $effectivePax = $nextPax + $globalPax;
                $entry = $this->safeEntry((string) ($effectivePax['path'] ?? $nextLongName ?? $headerName));
                $identity = rtrim($entry, '/');
                if (isset($seen[$identity])) {
                    throw new AppException('TAR contains duplicate output paths.', 422, 'archive_duplicate_path', ['entry' => mb_substr($entry, 0, 255)], 'files.zip');
                }
                $seen[$identity] = true;
                $effectiveSize = $headerSize;
                if (isset($effectivePax['size'])) {
                    $effectiveSize = $this->decimalSize($effectivePax['size']);
                }
                if ($effectiveSize > $maxBytes) {
                    throw new AppException('TAR entry exceeds the extraction size limit.', 413, 'archive_size_exceeded', [], 'files.zip');
                }
                $files++;
                if ($files > $maxFiles) {
                    throw new AppException('TAR entry count exceeds the safety policy.', 413, 'archive_file_count_exceeded', ['files' => $files], 'files.zip');
                }
                if ($type !== '5') {
                    $uncompressed = $this->boundedAdd($uncompressed, $effectiveSize, $maxBytes);
                }
                $this->topLevel($top, $entry);
                $this->discard($stream, $effectiveSize);
                $this->readPadding($stream, $effectiveSize);
                $nextLongName = null;
                $nextPax = [];
            }
        } finally {
            fclose($stream);
        }
        if ($files < 1) {
            throw new AppException('TAR archive contains no extractable entries.', 422, 'invalid_archive', [], 'files.zip');
        }
        $compressedBytes = (int) filesize($path);
        if ($uncompressed > max(1, $compressedBytes) * $maxRatio) {
            throw new AppException('TAR exceeds the compression-ratio safety limit.', 413, 'zip_bomb_blocked', [], 'files.zip');
        }
        return ['files' => $files, 'compressed_bytes' => $compressedBytes, 'uncompressed_bytes' => $uncompressed, 'top_level' => array_keys($top)];
    }

    /** @return array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>} */
    private function singleCompressed(string $path, string $originalName, string $format, int $maxBytes, int $maxRatio): array
    {
        if ($format === 'bz2' && !extension_loaded('bz2')) {
            throw new AppException('Bzip2 support is unavailable on this host.', 501, 'archive_bzip2_unavailable', [], 'files.zip');
        }
        if ($format === 'gz') {
            $this->validateGzipHeader($path);
        }
        $uri = ($format === 'gz' ? 'compress.zlib://' : 'compress.bzip2://') . $path;
        $stream = @fopen($uri, 'rb');
        if ($stream === false) {
            throw new AppException('Compressed archive could not be opened.', 422, 'invalid_archive', [], 'files.zip');
        }
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    throw new AppException('Compressed archive could not be read.', 422, 'invalid_archive', [], 'files.zip');
                }
                $bytes = $this->boundedAdd($bytes, strlen($chunk), $maxBytes);
            }
        } finally {
            fclose($stream);
        }
        $compressed = (int) filesize($path);
        if ($bytes > max(1, $compressed) * $maxRatio) {
            throw new AppException('Compressed file exceeds the expansion-ratio safety limit.', 413, 'zip_bomb_blocked', [], 'files.zip');
        }
        $name = preg_replace('/\.(?:gz|bz2)$/i', '', basename($originalName)) ?: 'extracted-file';
        return ['files' => 1, 'compressed_bytes' => $compressed, 'uncompressed_bytes' => $bytes, 'top_level' => [$this->safeEntry($name)]];
    }

    /** @param array<string,string> $metadata */
    private function containsUnsafePaxMetadata(array $metadata): bool
    {
        foreach (array_keys($metadata) as $key) {
            $lower = strtolower($key);
            if (str_ends_with($lower, 'linkpath') || str_contains($lower, 'sparse') || str_starts_with($lower, 'schily.dev') || $lower === 'schily.filetype') {
                return true;
            }
        }
        return false;
    }

    private function decimalSize(string $size): int
    {
        if (preg_match('/^(?:0|[1-9]\d*)$/D', $size) !== 1 || strlen($size) > strlen((string) PHP_INT_MAX)) {
            throw new AppException('PAX size metadata is invalid.', 422, 'invalid_archive', [], 'files.zip');
        }
        $value = (int) $size;
        if ((string) $value !== $size) {
            throw new AppException('PAX size is outside the supported integer range.', 413, 'archive_size_exceeded', [], 'files.zip');
        }
        return $value;
    }

    private function validateGzipHeader(string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new AppException('Gzip header cannot be read.', 422, 'invalid_archive', [], 'files.zip');
        }
        try {
            $header = $this->readExact($stream, 10, false);
            if ($header === null || ord($header[0]) !== 0x1f || ord($header[1]) !== 0x8b || ord($header[2]) !== 8 || (ord($header[3]) & 0xE0) !== 0) {
                throw new AppException('Gzip header is invalid.', 422, 'invalid_archive', [], 'files.zip');
            }
            $flags = ord($header[3]);
            if (($flags & 0x04) !== 0) {
                $lengthBytes = $this->readExact($stream, 2, false);
                $length = unpack('vlength', $lengthBytes ?? '')['length'] ?? -1;
                if ($length < 0 || $length > 65_535) {
                    throw new AppException('Gzip extra header is invalid.', 422, 'invalid_archive', [], 'files.zip');
                }
                $this->discard($stream, $length);
            }
            if (($flags & 0x08) !== 0) {
                $name = $this->readCString($stream, 1024);
                $this->safeEntry($name);
            }
            if (($flags & 0x10) !== 0) {
                $this->readCString($stream, 4096);
            }
            if (($flags & 0x02) !== 0) {
                $this->readExact($stream, 2, false);
            }
        } finally {
            fclose($stream);
        }
    }

    private function safeEntry(string $name): string
    {
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $name)) {
            throw new AppException('Archive contains an absolute or invalid output path.', 403, 'zip_slip_blocked', ['entry' => mb_substr($name, 0, 255)], 'files.zip');
        }
        $normalized = str_replace('\\', '/', $name);
        if (strlen($normalized) > 1024 || str_contains($normalized, '//') || preg_match('#(^|/)(?:\.|\.\.)(?:/|$)#', $normalized) || preg_match('/[\x00-\x1F\x7F]/u', $normalized)) {
            throw new AppException('Archive path traversal or ambiguous path was blocked.', 403, 'zip_slip_blocked', ['entry' => mb_substr($normalized, 0, 255)], 'files.zip');
        }
        foreach (explode('/', rtrim($normalized, '/')) as $segment) {
            if ($segment === '' || strlen($segment) > 255) {
                throw new AppException('Archive path segment is invalid.', 422, 'invalid_archive_path', ['entry' => mb_substr($normalized, 0, 255)], 'files.zip');
            }
        }
        return $normalized;
    }

    /** @param array<string,bool> $top */
    private function topLevel(array &$top, string $entry): void
    {
        $segment = explode('/', rtrim($entry, '/'))[0] ?? '';
        if ($segment !== '') {
            $top[$segment] = true;
        }
    }

    private function boundedAdd(int $total, int $size, int $maximum): int
    {
        if ($size < 0 || $size > $maximum || $total > $maximum - $size) {
            throw new AppException('Archive expansion exceeds the configured safety limit.', 413, 'archive_size_exceeded', ['limit' => $maximum], 'files.zip');
        }
        return $total + $size;
    }

    /** @param resource $stream */
    private function readExact($stream, int $bytes, bool $allowCleanEof): ?string
    {
        if ($bytes === 0) {
            return '';
        }
        $data = '';
        while (strlen($data) < $bytes && !feof($stream)) {
            $chunk = fread($stream, $bytes - strlen($data));
            if ($chunk === false) {
                throw new AppException('Archive stream could not be read.', 422, 'invalid_archive', [], 'files.zip');
            }
            $data .= $chunk;
        }
        if ($data === '' && $allowCleanEof) {
            return null;
        }
        if (strlen($data) !== $bytes) {
            throw new AppException('Archive ended before a complete entry was read.', 422, 'invalid_archive', [], 'files.zip');
        }
        return $data;
    }

    /** @param resource $stream */
    private function discard($stream, int $bytes): void
    {
        $remaining = $bytes;
        while ($remaining > 0) {
            $chunk = fread($stream, min(1_048_576, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new AppException('Archive entry data is truncated.', 422, 'invalid_archive', [], 'files.zip');
            }
            $remaining -= strlen($chunk);
        }
    }

    /** @param resource $stream */
    private function readPadding($stream, int $size): void
    {
        $padding = (512 - ($size % 512)) % 512;
        if ($padding > 0) {
            $this->readExact($stream, $padding, false);
        }
    }

    /** @param resource $stream */
    private function assertOnlyZeroPadding($stream): void
    {
        while (!feof($stream)) {
            $chunk = fread($stream, 1_048_576);
            if ($chunk === false) {
                throw new AppException('Archive trailer could not be read.', 422, 'invalid_archive', [], 'files.zip');
            }
            if ($chunk !== '' && trim($chunk, "\0") !== '') {
                throw new AppException('Data after the TAR end marker is blocked.', 422, 'invalid_archive', [], 'files.zip');
            }
        }
    }

    private function verifyTarHeader(string $header): void
    {
        $stored = trim(str_replace("\0", ' ', substr($header, 148, 8)));
        if ($stored === '' || preg_match('/^[0-7]+$/D', $stored) !== 1) {
            throw new AppException('TAR header checksum is invalid.', 422, 'invalid_archive', [], 'files.zip');
        }
        $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $calculated = array_sum(unpack('C*', $checksumHeader));
        if (octdec($stored) !== $calculated) {
            throw new AppException('TAR header checksum does not match its contents.', 422, 'archive_integrity_failed', [], 'files.zip');
        }
    }

    private function tarNumber(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
            $bytes = array_values(unpack('C*', $field));
            if (($bytes[0] & 0x40) !== 0) {
                throw new AppException('Negative TAR sizes are invalid.', 422, 'invalid_archive', [], 'files.zip');
            }
            $value = $bytes[0] & 0x3F;
            foreach (array_slice($bytes, 1) as $byte) {
                if ($value > intdiv(PHP_INT_MAX - $byte, 256)) {
                    throw new AppException('TAR size is outside the supported integer range.', 413, 'archive_size_exceeded', [], 'files.zip');
                }
                $value = ($value * 256) + $byte;
            }
            return $value;
        }
        $octal = trim($field, " \0");
        if ($octal === '') {
            return 0;
        }
        if (preg_match('/^[0-7]+$/D', $octal) !== 1 || strlen($octal) > 21) {
            throw new AppException('TAR entry size is invalid.', 422, 'invalid_archive', [], 'files.zip');
        }
        $value = octdec($octal);
        if (!is_int($value)) {
            throw new AppException('TAR entry size is outside the supported integer range.', 413, 'archive_size_exceeded', [], 'files.zip');
        }
        return $value;
    }

    /** @return array<string,string> */
    private function pax(string $data): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $space = strpos($data, ' ', $offset);
            if ($space === false || preg_match('/^\d+$/D', substr($data, $offset, $space - $offset)) !== 1) {
                throw new AppException('PAX metadata record is invalid.', 422, 'invalid_archive', [], 'files.zip');
            }
            $recordLength = (int) substr($data, $offset, $space - $offset);
            if ($recordLength < 4 || $offset + $recordLength > $length) {
                throw new AppException('PAX metadata length is invalid.', 422, 'invalid_archive', [], 'files.zip');
            }
            if ($data[$offset + $recordLength - 1] !== "\n") {
                throw new AppException('PAX metadata record terminator is invalid.', 422, 'invalid_archive', [], 'files.zip');
            }
            $record = substr($data, $space + 1, $recordLength - ($space - $offset) - 2);
            $separator = strpos($record, '=');
            if ($separator === false) {
                throw new AppException('PAX metadata field is invalid.', 422, 'invalid_archive', [], 'files.zip');
            }
            $key = substr($record, 0, $separator);
            if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $key) !== 1) {
                throw new AppException('PAX metadata key is invalid.', 422, 'invalid_archive', [], 'files.zip');
            }
            $result[$key] = substr($record, $separator + 1);
            $offset += $recordLength;
        }
        return $result;
    }

    /** @param resource $stream */
    private function readCString($stream, int $maximum): string
    {
        $value = '';
        while (strlen($value) <= $maximum) {
            $character = fread($stream, 1);
            if ($character === false || $character === '') {
                throw new AppException('Compressed header is truncated.', 422, 'invalid_archive', [], 'files.zip');
            }
            if ($character === "\0") {
                return $value;
            }
            $value .= $character;
        }
        throw new AppException('Compressed header field is too long.', 422, 'invalid_archive', [], 'files.zip');
    }

    private function format(string $name): string
    {
        $lower = strtolower($name);
        return match (true) {
            str_ends_with($lower, '.tar.gz'), str_ends_with($lower, '.tgz') => 'tar.gz',
            str_ends_with($lower, '.tar.bz2'), str_ends_with($lower, '.tbz2') => 'tar.bz2',
            str_ends_with($lower, '.zip') => 'zip',
            str_ends_with($lower, '.tar') => 'tar',
            str_ends_with($lower, '.gz') => 'gz',
            str_ends_with($lower, '.bz2') => 'bz2',
            default => 'unsupported',
        };
    }
}
