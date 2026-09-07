<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Core\AppException;
use App\FileManager\ArchiveSafetyValidator;

final class ZipPackageValidator
{
    private readonly ArchiveSafetyValidator $archives;

    public function __construct(?ArchiveSafetyValidator $archives = null)
    {
        $this->archives = $archives ?? new ArchiveSafetyValidator();
    }

    /** @return array{files:int,compressed_bytes:int,uncompressed_bytes:int,top_level:list<string>,sha256:string} */
    public function validate(string $path, int $maxFiles = 10_000, int $maxUncompressedBytes = 1_073_741_824): array
    {
        if (!is_file($path) || !is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            throw new AppException('Deployment package must be a readable ZIP file.', 415, 'invalid_deployment_package', [], 'deploy.package');
        }
        $result = $this->archives->validate($path, basename($path), $maxFiles, $maxUncompressedBytes);
        return [
            'files' => $result['files'],
            'compressed_bytes' => $result['compressed_bytes'],
            'uncompressed_bytes' => $result['uncompressed_bytes'],
            'top_level' => $result['top_level'],
            'sha256' => $result['sha256'],
        ];
    }
}
