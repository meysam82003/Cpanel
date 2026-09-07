<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Core\AppException;
use App\FileManager\FileManagerService;

final class DeploymentRollbackExecutor
{
    public function __construct(private readonly FileManagerService $files)
    {
    }

    /** @param array<string,mixed> $deployment
     *  @return array{destination:string,backup_ref:?string,method:string}
     */
    public function restore(int $userId, int $accountId, array $deployment): array
    {
        $destination = (string) ($deployment['destination'] ?? '');
        if ($destination === '' || basename($destination) === '') {
            throw new AppException('Deployment rollback destination is invalid.', 500, 'rollback_destination_invalid');
        }
        try {
            $this->files->info($userId, $accountId, $destination);
            $this->files->delete($userId, $accountId, $destination, true);
        } catch (AppException $exception) {
            if ($exception->safeCode !== 'remote_path_not_found') {
                throw $exception;
            }
        }
        $rollbackPath = is_string($deployment['rollback_path'] ?? null) ? (string) $deployment['rollback_path'] : null;
        if ($rollbackPath !== null && $rollbackPath !== '') {
            try {
                $this->files->info($userId, $accountId, $rollbackPath);
                $this->files->renameOrMove($userId, $accountId, $rollbackPath, $destination);
                return ['destination' => $destination, 'backup_ref' => $rollbackPath, 'method' => 'atomic_directory_restore'];
            } catch (AppException $exception) {
                if ($exception->safeCode !== 'remote_path_not_found') {
                    throw $exception;
                }
            }
        }
        if (!(bool) ($deployment['destination_existed'] ?? true)) {
            return ['destination' => $destination, 'backup_ref' => null, 'method' => 'remove_new_destination'];
        }
        $backup = is_string($deployment['backup_ref'] ?? null) ? (string) $deployment['backup_ref'] : null;
        if ($backup !== null && $backup !== '') {
            $this->files->extract($userId, $accountId, $backup, dirname($destination));
        }
        return ['destination' => $destination, 'backup_ref' => $backup, 'method' => 'archive_restore'];
    }
}
