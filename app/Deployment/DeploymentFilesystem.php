<?php

declare(strict_types=1);

namespace App\Deployment;

interface DeploymentFilesystem
{
    /** @return array<string,mixed>|null */
    public function info(int $userId, int $accountId, string $path): ?array;

    public function ensureDirectory(int $userId, int $accountId, string $path, string $permissions = '0700'): void;

    public function uploadPackage(int $userId, int $accountId, string $directory, string $localPath, string $name): void;

    /** @return array{bytes:int,sha256:string,content_type:?string} */
    public function download(int $userId, int $accountId, string $remotePath, string $localPath, int $maxBytes): array;

    public function compressDirectory(int $userId, int $accountId, string $source, string $destination): void;

    public function extract(int $userId, int $accountId, string $archive, string $destination): void;

    public function moveDirectory(int $userId, int $accountId, string $source, string $destination): void;

    public function deleteTree(int $userId, int $accountId, string $path): void;
}
