<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Accounts\AccountRepository;
use App\Core\AppException;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;
use App\FileManager\FileManagerService;
use App\Security\PathGuard;

final class CpanelDeploymentFilesystem implements DeploymentFilesystem
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly FileManagerService $files,
        private readonly PathGuard $paths,
    ) {
    }

    public function info(int $userId, int $accountId, string $path): ?array
    {
        try {
            return $this->files->info($userId, $accountId, $path);
        } catch (AppException $exception) {
            if ($exception->safeCode === 'remote_path_not_found') {
                return null;
            }
            throw $exception;
        }
    }

    public function ensureDirectory(int $userId, int $accountId, string $path, string $permissions = '0700'): void
    {
        if (!preg_match('/^0[0-7]{3}$/', $permissions)) {
            throw new AppException('Deployment directory permissions are invalid.', 500, 'invalid_permissions');
        }
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $path = $this->paths->normalize($path, $root);
        if ($path === $root) {
            return;
        }
        $relative = trim(substr($path, strlen($root)), '/');
        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            $next = $current . '/' . $segment;
            $existing = $this->info($userId, $accountId, $next);
            if ($existing === null) {
                try {
                    $this->files->createFolder($userId, $accountId, $current, $segment, $permissions);
                } catch (CpanelApiException $exception) {
                    $existing = $this->info($userId, $accountId, $next);
                    if ($existing === null) {
                        throw $exception;
                    }
                }
                $existing = $this->info($userId, $accountId, $next);
            }
            if ($existing === null || !in_array(strtolower((string) ($existing['type'] ?? '')), ['dir', 'directory'], true) || (bool) ($existing['is_symlink'] ?? false)) {
                throw new AppException('A deployment working path is not a regular directory.', 409, 'deployment_work_path_invalid', ['path' => $next], 'security.path');
            }
            $current = $next;
        }
    }

    public function uploadPackage(int $userId, int $accountId, string $directory, string $localPath, string $name): void
    {
        $this->files->upload($userId, $accountId, $directory, [['path' => $localPath, 'name' => $name]], 'reject', ['source' => 'deployment_package']);
    }

    public function download(int $userId, int $accountId, string $remotePath, string $localPath, int $maxBytes): array
    {
        return $this->cpanel->downloadTo($this->accounts->connection($userId, $accountId), $remotePath, $localPath, $maxBytes);
    }

    public function compressDirectory(int $userId, int $accountId, string $source, string $destination): void
    {
        $this->files->compress($userId, $accountId, [$source], $destination, 'zip');
    }

    public function extract(int $userId, int $accountId, string $archive, string $destination): void
    {
        $this->files->extract($userId, $accountId, $archive, $destination);
    }

    public function moveDirectory(int $userId, int $accountId, string $source, string $destination): void
    {
        $this->files->renameDirectoryAtomically($userId, $accountId, $source, $destination);
    }

    public function deleteTree(int $userId, int $accountId, string $path): void
    {
        $this->files->delete($userId, $accountId, $path, true);
    }
}
