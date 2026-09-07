<?php

declare(strict_types=1);

namespace App\Deployment;

use App\Accounts\AccountRepository;
use App\Core\AppException;
use App\Core\Database;

final class DeploymentPackageService
{
    public function __construct(private readonly Database $database, private readonly AccountRepository $accounts, private readonly ZipPackageValidator $validator)
    {
    }

    /** @return array{id:int,name:string,metadata:array<string,mixed>,preview:array<string,mixed>,expires_at:string} */
    public function register(int $userId, int $accountId, string $path, string $originalName): array
    {
        $this->accounts->getOwned($userId, $accountId);
        if (is_link($path)) {
            throw new AppException('Deployment package storage must not be a symbolic link.', 422, 'invalid_deployment_upload', [], 'deploy.package');
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new AppException('Deployment package is not available in secure upload storage.', 422, 'invalid_deployment_upload', [], 'deploy.package');
        }
        $metadata = $this->validator->validate($real);
        $name = mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 255);
        if (!preg_match('/^[\pL\pN._ -]{1,200}\.zip$/ui', $name)) {
            $name = 'release.zip';
        }
        $expires = gmdate('Y-m-d H:i:s', time() + 1800);
        $this->database->execute('INSERT INTO deployment_packages (user_id, account_id, original_name, local_path, checksum_sha256, metadata_json, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$userId, $accountId, $name, $real, $metadata['sha256'], json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $expires]);
        return ['id' => $this->database->lastInsertId(), 'name' => $name, 'metadata' => $metadata, 'preview' => $metadata, 'expires_at' => $expires];
    }

    /** @return array{id:int,path:string,name:string,metadata:array<string,mixed>} */
    public function consume(int $userId, int $accountId, int $packageId): array
    {
        return $this->database->transaction(function (Database $db) use ($userId, $accountId, $packageId): array {
            $row = $db->one('SELECT * FROM deployment_packages WHERE id = ? FOR UPDATE', [$packageId]);
            if ($row === null || (int) $row['user_id'] !== $userId || (int) $row['account_id'] !== $accountId || $row['consumed_at'] !== null || strtotime((string) $row['expires_at']) < time() || !is_file((string) $row['local_path'])) {
                throw new AppException('Deployment package expired, was used, or does not belong to you.', 404, 'deployment_package_unavailable', [], 'security.idor');
            }
            if (!hash_equals((string) $row['checksum_sha256'], hash_file('sha256', (string) $row['local_path']))) {
                throw new AppException('Deployment package changed after validation.', 409, 'deployment_package_integrity_failed', [], 'deploy.package');
            }
            $db->execute('UPDATE deployment_packages SET consumed_at = CURRENT_TIMESTAMP WHERE id = ?', [$packageId]);
            $metadata = json_decode((string) $row['metadata_json'], true);
            return ['id' => $packageId, 'path' => (string) $row['local_path'], 'name' => (string) $row['original_name'], 'metadata' => is_array($metadata) ? $metadata : []];
        });
    }
}
