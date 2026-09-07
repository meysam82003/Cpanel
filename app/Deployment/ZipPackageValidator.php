<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Core\AppException;
use ZipArchive;

final class ZipPackageValidator
{
    /** @return array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} */
    public function validate(string $path, int $maxFiles = 10_000, int $maxUncompressedBytes = 1_073_741_824): array
    {
        if (!is_file($path) || !is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            throw new AppException('Deployment package must be a readable ZIP file.', 415, 'invalid_deployment_package', [], 'deploy.package');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new AppException('Deployment ZIP could not be opened.', 422, 'invalid_deployment_zip', [], 'deploy.package');
        }
        $count = $zip->numFiles;
        if ($count < 1 || $count > $maxFiles) {
            $zip->close();
            throw new AppException('Deployment ZIP file count is outside the safety limit.', 413, 'deployment_file_count_exceeded', ['files' => $count], 'deploy.package');
        }
        $uncompressed = 0;
        $compressed = 0;
        $top = [];
        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('/^[A-Za-z]:\//', $name)) {
                $zip->close();
                throw new AppException('A path traversal entry in the deployment ZIP was blocked.', 403, 'zip_slip_blocked', ['entry' => mb_substr($name, 0, 255)], 'deploy.package');
            }
            if (strlen($name) > 1024 || preg_match('#(^|/)\.(/|$)#', $name)) {
                $zip->close();
                throw new AppException('Deployment ZIP contains an unsafe path.', 422, 'invalid_zip_path', ['entry' => mb_substr($name, 0, 255)], 'deploy.package');
            }
            $external = (int) ($stat['external_attributes'] ?? 0);
            $unixMode = ($external >> 16) & 0xF000;
            if ($unixMode === 0xA000) {
                $zip->close();
                throw new AppException('Symbolic links are not allowed in deployment packages.', 403, 'zip_symlink_blocked', ['entry' => mb_substr($name, 0, 255)], 'deploy.package');
            }
            $uncompressed += (int) ($stat['size'] ?? 0);
            $compressed += (int) ($stat['comp_size'] ?? 0);
            if ($uncompressed > $maxUncompressedBytes || ($compressed > 0 && $uncompressed / $compressed > 200)) {
                $zip->close();
                throw new AppException('Deployment ZIP exceeds extraction size or compression-ratio limits.', 413, 'zip_bomb_blocked', [], 'deploy.package');
            }
            $segment = explode('/', trim($name, '/'))[0] ?? '';
            if ($segment !== '') {
                $top[$segment] = true;
            }
        }
        $zip->close();
        return ['files' => $count, 'compressed_bytes' => (int) filesize($path), 'uncompressed_bytes' => $uncompressed, 'top_level' => array_keys($top), 'sha256' => hash_file('sha256', $path)];
    }
}
