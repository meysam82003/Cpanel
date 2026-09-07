<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Core\AppException;

final class DeploymentBackupVerifier
{
    public function __construct(
        private readonly DeploymentFilesystem $filesystem,
        private readonly ZipPackageValidator $archives,
        private readonly string $tempRoot,
        private readonly int $maxBytes = 1_073_741_824,
    ) {
    }

    /** @return array<string,mixed> */
    public function verify(int $userId, int $accountId, string $backup, string $destination, ?string $expectedChecksum = null): array
    {
        $info = $this->filesystem->info($userId, $accountId, $backup);
        $type = strtolower((string) ($info['type'] ?? 'file'));
        $size = (int) ($info['size'] ?? 0);
        $limit = max(1_048_576, min(1_073_741_824, $this->maxBytes));
        if ($info === null || in_array($type, ['dir', 'directory', 'link', 'symlink'], true) || (bool) ($info['is_symlink'] ?? false) || $size < 1 || $size >= $limit) {
            throw new AppException('Deployment backup is missing, unsafe, empty, or exceeds the verification limit.', 502, 'deployment_backup_invalid', ['size' => $size, 'limit' => $limit], 'deployment.rollback');
        }
        if ($expectedChecksum !== null && !preg_match('/^[a-f0-9]{64}$/', $expectedChecksum)) {
            throw new AppException('Persisted deployment backup checksum is invalid.', 409, 'deployment_state_tampered', [], 'security.path');
        }

        $temporary = $this->temporaryPath();
        try {
            $download = $this->filesystem->download($userId, $accountId, $backup, $temporary, $size + 1);
            if ((int) ($download['bytes'] ?? -1) !== $size || !preg_match('/^[a-f0-9]{64}$/', (string) ($download['sha256'] ?? ''))) {
                throw new AppException('Deployment backup download did not match cPanel file metadata.', 502, 'deployment_backup_integrity_failed', [], 'deployment.rollback');
            }
            $checksum = (string) $download['sha256'];
            if ($expectedChecksum !== null && !hash_equals($expectedChecksum, $checksum)) {
                throw new AppException('Deployment backup checksum changed after creation.', 409, 'deployment_backup_integrity_failed', [], 'deployment.rollback');
            }
            $metadata = $this->archives->validate($temporary, 100_000, 10_737_418_240);
            if ((int) $metadata['compressed_bytes'] !== $size || $metadata['top_level'] !== [basename($destination)] || !hash_equals($checksum, (string) $metadata['sha256'])) {
                throw new AppException('Deployment backup archive layout or checksum is invalid.', 409, 'deployment_backup_layout_invalid', ['top_level' => $metadata['top_level']], 'deployment.rollback');
            }
            return $info + ['size' => $size, 'sha256' => $checksum, 'archive_metadata' => $metadata];
        } finally {
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function temporaryPath(): string
    {
        $directory = rtrim($this->tempRoot, '/');
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new AppException('Secure backup verification storage is unavailable.', 500, 'deployment_storage_unavailable');
        }
        @chmod($directory, 0700);
        $real = realpath($directory);
        if ($real === false || !is_writable($real)) {
            throw new AppException('Secure backup verification storage is unavailable.', 500, 'deployment_storage_unavailable');
        }
        return $real . '/deployment-backup-verify-' . bin2hex(random_bytes(16)) . '.zip';
    }
}
