<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AppException;

final class UploadReceiver
{
    public function __construct(private readonly string $tempRoot)
    {
    }

    /** @param array<string,mixed> $file
     *  @return array{path:string,name:string,size:int,mime:string}
     */
    public function receive(array $file, int $maxBytes, array $extensions): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new AppException('The upload did not complete.', 422, 'upload_failed', ['upload_error' => $error], 'files.upload');
        }
        $source = (string) ($file['tmp_name'] ?? '');
        $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'upload.bin')));
        $size = (int) ($file['size'] ?? -1);
        if ($size < 0 || $size > $maxBytes || !is_uploaded_file($source)) {
            throw new AppException('Uploaded file is invalid or exceeds the allowed size.', 413, 'upload_size_exceeded', ['limit' => $maxBytes], 'files.upload');
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extensions !== [] && !in_array($extension, $extensions, true)) {
            throw new AppException('Uploaded file type is not allowed for this operation.', 415, 'upload_type_not_allowed', ['extension' => $extension], 'files.upload');
        }
        if (!is_dir($this->tempRoot) && !mkdir($this->tempRoot, 0700, true) && !is_dir($this->tempRoot)) {
            throw new AppException('Secure upload storage is unavailable.', 500, 'upload_storage_unavailable');
        }
        $suffix = str_ends_with(strtolower($name), '.sql.gz') ? '.sql.gz' : ($extension !== '' ? '.' . $extension : '');
        $target = rtrim($this->tempRoot, '/') . '/upload-' . bin2hex(random_bytes(16)) . $suffix;
        if (!move_uploaded_file($source, $target)) {
            throw new AppException('Uploaded file could not be moved to secure storage.', 500, 'upload_storage_unavailable');
        }
        @chmod($target, 0600);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($target) ?: 'application/octet-stream';
        return ['path' => $target, 'name' => $name, 'size' => (int) filesize($target), 'mime' => $mime];
    }

    /** @param array<string,mixed> $entry
     *  @return list<array<string,mixed>>
     */
    public function normalizeMultiple(array $entry, int $maxCount = 20): array
    {
        if (!is_array($entry['name'] ?? null)) {
            return [$entry];
        }
        $result = [];
        $count = count($entry['name']);
        if ($count < 1 || $count > $maxCount) {
            throw new AppException('Upload file count is outside the allowed range.', 422, 'invalid_upload_count', [], 'files.upload');
        }
        for ($index = 0; $index < $count; $index++) {
            $result[] = [
                'name' => $entry['name'][$index] ?? '',
                'type' => $entry['type'][$index] ?? '',
                'tmp_name' => $entry['tmp_name'][$index] ?? '',
                'error' => $entry['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $entry['size'][$index] ?? -1,
            ];
        }
        return $result;
    }
}
