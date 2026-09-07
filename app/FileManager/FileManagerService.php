<?php

declare(strict_types=1);

namespace App\FileManager;

use App\Accounts\AccountRepository;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Cpanel\CpanelApiException;
use App\Cpanel\UapiClient;
use App\Security\PathGuard;

final class FileManagerService
{
    private const TEXT_EXTENSIONS = ['php', 'html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'env', 'htaccess', 'conf', 'ini', 'md', 'log', 'sql'];

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly UapiClient $cpanel,
        private readonly PathGuard $paths,
        private readonly FileVersionService $versions,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array{path:string,parent:?string,items:list<array<string,mixed>>,pagination:array<string,int|bool>} */
    public function browse(int $userId, int $accountId, string $path, int $page = 1, int $perPage = 50, string $sort = 'name', string $direction = 'asc', bool $hidden = false): array
    {
        [$account, $connection, $root, $safePath] = $this->context($userId, $accountId, $path);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $sort = in_array($sort, ['name', 'size', 'mtime'], true) ? $sort : 'name';
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $sortField = ['name' => 'file', 'size' => 'size', 'mtime' => 'mtime'][$sort];
        $result = $this->cpanel->call($connection, 'Fileman', 'list_files', [
            'dir' => $safePath,
            'show_hidden' => $hidden ? 1 : 0,
            'include_mime' => 1,
            'include_permissions' => 1,
            'api.paginate.enable' => 1,
            'api.paginate.start' => ($page - 1) * $perPage + 1,
            'api.paginate.size' => $perPage + 1,
            'api.sort.enable' => 1,
            'api.sort.a.field' => $sortField,
            'api.sort.a.method' => $sort === 'name' ? 'lexicographic' : 'numeric',
            'api.sort.a.reverse' => $direction === 'desc' ? 1 : 0,
        ]);
        $items = $this->asList($result['data']);
        $hasMore = count($items) > $perPage;
        $items = array_slice($items, 0, $perPage);
        foreach ($items as &$item) {
            $fullPath = (string) ($item['fullpath'] ?? rtrim($safePath, '/') . '/' . ($item['file'] ?? $item['name'] ?? ''));
            $this->paths->assertWithinRoot($fullPath, $root);
            unset($item['uid'], $item['gid']);
            $item['path'] = $fullPath;
            $item['is_symlink'] = in_array(strtolower((string) ($item['type'] ?? '')), ['link', 'symlink'], true);
        }
        unset($item);
        $this->audit->record($userId, $accountId, 'file.browse', 'success', 'directory', $safePath, ['page' => $page, 'items' => count($items)]);
        return [
            'path' => $safePath,
            'parent' => $safePath === $root ? null : dirname($safePath),
            'items' => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $hasMore],
        ];
    }

    /** @return array<string,mixed> */
    public function info(int $userId, int $accountId, string $path): array
    {
        [, $connection, $root, $safePath] = $this->context($userId, $accountId, $path);
        $result = $this->cpanel->call($connection, 'Fileman', 'get_file_information', ['path' => $safePath, 'include_mime' => 1, 'include_permissions' => 1, 'check_for_leaf_directories' => 1]);
        $info = $this->first($result['data']);
        if ((int) ($info['exists'] ?? 0) !== 1) {
            throw new AppException('The file or directory does not exist.', 404, 'remote_path_not_found', [], 'errors.not-found');
        }
        $canonical = (string) ($info['fullpath'] ?? $safePath);
        $this->paths->assertWithinRoot($canonical, $root);
        unset($info['uid'], $info['gid']);
        $info['path'] = $canonical;
        return $info;
    }

    /** @return array{path:string,content:string,offset:int,length:int,total_bytes:int,has_more:bool,language:string} */
    public function readText(int $userId, int $accountId, string $path, int $offset = 0, int $length = 131072): array
    {
        [, $connection, , $safePath] = $this->context($userId, $accountId, $path);
        $this->assertTextFile($safePath);
        $metadata = $this->info($userId, $accountId, $safePath);
        $size = (int) ($metadata['size'] ?? 0);
        if ($size > 4_194_304) {
            throw new AppException('This file is too large for the text editor. Use secure download or the log tail view.', 413, 'text_file_too_large', ['size' => $size], 'files.edit');
        }
        $result = $this->cpanel->call($connection, 'Fileman', 'get_file_content', ['dir' => dirname($safePath), 'file' => basename($safePath), 'from_charset' => '_DETECT_', 'to_charset' => 'UTF-8']);
        $data = $this->first($result['data']);
        $content = (string) ($data['content'] ?? '');
        $offset = max(0, min(strlen($content), $offset));
        $length = max(1024, min(262144, $length));
        $chunk = substr($content, $offset, $length);
        return ['path' => $safePath, 'content' => $chunk, 'offset' => $offset, 'length' => strlen($chunk), 'total_bytes' => strlen($content), 'has_more' => $offset + strlen($chunk) < strlen($content), 'language' => $this->languageFor($safePath)];
    }

    /** @return array<string,mixed> */
    public function saveText(int $userId, int $accountId, string $path, string $content, bool $backup = true): array
    {
        [, $connection, $root, $safePath] = $this->context($userId, $accountId, $path);
        $this->assertTextFile($safePath);
        if (strlen($content) > 4_194_304 || !mb_check_encoding($content, 'UTF-8')) {
            throw new AppException('Editor content must be valid UTF-8 and no larger than 4 MiB.', 422, 'invalid_editor_content', [], 'files.edit');
        }
        $version = null;
        if ($backup || $this->isSensitivePath($safePath)) {
            $version = $this->versions->backupBeforeWrite($userId, $accountId, $connection, $safePath, $root, 'editor_save');
        }
        $result = $this->cpanel->call($connection, 'Fileman', 'save_file_content', ['dir' => dirname($safePath), 'file' => basename($safePath), 'content' => $content, 'from_charset' => 'UTF-8', 'to_charset' => 'UTF-8'], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'file.save', 'success', 'file', $safePath, ['bytes' => strlen($content), 'version' => $version['version'] ?? null]);
        return ['path' => $safePath, 'bytes' => strlen($content), 'backup' => $version, 'cpanel' => $result['data']];
    }

    public function createFile(int $userId, int $accountId, string $directory, string $filename): array
    {
        [, , , $safeDirectory] = $this->context($userId, $accountId, $directory);
        $filename = $this->paths->sanitizeFilename($filename);
        return $this->saveText($userId, $accountId, $safeDirectory . '/' . $filename, '', false);
    }

    /** @return array<string,mixed> */
    public function createFolder(int $userId, int $accountId, string $directory, string $name, string $permissions = '0755'): array
    {
        [, $connection, , $safeDirectory] = $this->context($userId, $accountId, $directory);
        $name = $this->paths->sanitizeFilename($name);
        if (!preg_match('/^0[0-7]{3}$/', $permissions)) {
            throw new AppException('Directory permissions must be a four-digit octal value.', 422, 'invalid_permissions');
        }
        $result = $this->cpanel->callLegacyApi2($connection, 'Fileman', 'mkdir', ['path' => $safeDirectory, 'name' => $name, 'permissions' => $permissions]);
        $path = $safeDirectory . '/' . $name;
        $this->audit->record($userId, $accountId, 'directory.create', 'success', 'directory', $path, ['permissions' => $permissions, 'api' => 'api2_no_uapi_equivalent']);
        return ['path' => $path, 'cpanel' => $result['data']];
    }

    /** @param list<array{path:string,mime?:string,name?:string}> $uploads
     *  @return array<string,mixed>
     */
    public function upload(int $userId, int $accountId, string $directory, array $uploads, string $collisionPolicy = 'reject', array $auditMetadata = []): array
    {
        [, $connection, , $safeDirectory] = $this->context($userId, $accountId, $directory);
        if ($uploads === [] || count($uploads) > 20) {
            throw new AppException('Select between one and twenty files per upload.', 422, 'invalid_upload_count', [], 'files.upload');
        }
        $files = [];
        foreach ($uploads as $index => $upload) {
            $name = $this->paths->sanitizeFilename((string) ($upload['name'] ?? basename($upload['path'])));
            if (!is_file($upload['path']) || !is_readable($upload['path'])) {
                throw new AppException('An uploaded temporary file is missing.', 422, 'upload_temp_missing');
            }
            $name = $this->resolveUploadName($userId, $accountId, $safeDirectory, $name, $collisionPolicy);
            $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload['path']) ?: 'application/octet-stream';
            $files['file-' . ($index + 1)] = ['path' => $upload['path'], 'mime' => $actualMime, 'name' => $name];
        }
        $result = $this->cpanel->call($connection, 'Fileman', 'upload_files', ['dir' => $safeDirectory], 'POST', $files, false);
        $metadata = ['count' => count($files), 'filenames' => array_column($files, 'name')] + $auditMetadata;
        $this->audit->record($userId, $accountId, 'file.upload', 'success', 'directory', $safeDirectory, $metadata);
        return ['directory' => $safeDirectory, 'files' => array_column($files, 'name'), 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function renameOrMove(int $userId, int $accountId, string $source, string $destination): array
    {
        [, $connection, , $safeSource] = $this->context($userId, $accountId, $source);
        [, , , $safeDestination] = $this->context($userId, $accountId, $destination);
        try {
            $result = $this->cpanel->call($connection, 'Fileman', 'move_file', ['source' => $safeSource, 'destination' => $safeDestination], 'GET', [], false);
            $providerApi = 'uapi';
        } catch (CpanelApiException $exception) {
            if (!$this->cpanel->isOperationUnavailable($exception)) {
                throw $exception;
            }
            $result = $this->cpanel->callLegacyApi2($connection, 'Fileman', 'fileop', ['op' => 'move', 'sourcefiles' => ltrim($safeSource, '/'), 'destfiles' => ltrim($safeDestination, '/'), 'doubledecode' => 0]);
            $providerApi = 'api2_compatibility';
        }
        $this->audit->record($userId, $accountId, 'file.move', 'success', 'path', $safeSource, ['destination' => $safeDestination, 'provider_api' => $providerApi]);
        return ['source' => $safeSource, 'destination' => $safeDestination, 'provider_api' => $providerApi, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function copy(int $userId, int $accountId, string $source, string $destination): array
    {
        [, $connection, , $safeSource] = $this->context($userId, $accountId, $source);
        [, , , $safeDestination] = $this->context($userId, $accountId, $destination);
        try {
            $result = $this->cpanel->call($connection, 'Fileman', 'copy_file', ['source' => $safeSource, 'destination' => $safeDestination], 'GET', [], false);
            $providerApi = 'uapi';
        } catch (CpanelApiException $exception) {
            if (!$this->cpanel->isOperationUnavailable($exception)) {
                throw $exception;
            }
            $result = $this->cpanel->callLegacyApi2($connection, 'Fileman', 'fileop', ['op' => 'copy', 'sourcefiles' => ltrim($safeSource, '/'), 'destfiles' => ltrim($safeDestination, '/'), 'doubledecode' => 0]);
            $providerApi = 'api2_compatibility';
        }
        $this->audit->record($userId, $accountId, 'file.copy', 'success', 'path', $safeSource, ['destination' => $safeDestination, 'provider_api' => $providerApi]);
        return ['source' => $safeSource, 'destination' => $safeDestination, 'provider_api' => $providerApi, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function delete(int $userId, int $accountId, string $path, bool $permanent = false): array
    {
        [, $connection, , $safePath] = $this->context($userId, $accountId, $path);
        $info = $this->info($userId, $accountId, $safePath);
        $isDirectory = in_array(strtolower((string) ($info['type'] ?? 'file')), ['dir', 'directory'], true);
        try {
            $result = $this->cpanel->call($connection, 'Fileman', $permanent ? 'delete_file' : 'trash_file', ['path' => $safePath], 'GET', [], false);
            $providerApi = 'uapi';
        } catch (CpanelApiException $exception) {
            if (!$this->cpanel->isOperationUnavailable($exception)) {
                throw $exception;
            }
            $result = $this->cpanel->callLegacyApi2($connection, 'Fileman', 'fileop', ['op' => $permanent ? 'unlink' : 'trash', 'sourcefiles' => ltrim($safePath, '/'), 'doubledecode' => 0]);
            $providerApi = 'api2_compatibility';
        }
        $this->audit->record($userId, $accountId, $permanent ? 'file.delete_permanent' : 'file.trash', 'success', $isDirectory ? 'directory' : 'file', $safePath, ['provider_api' => $providerApi]);
        return ['path' => $safePath, 'permanent' => $permanent, 'provider_api' => $providerApi, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function restoreTrash(int $userId, int $accountId, string $path): array
    {
        [, $connection, , $safePath] = $this->context($userId, $accountId, $path);
        try {
            $result = $this->cpanel->call($connection, 'Fileman', 'restore_from_trash', ['path' => $safePath], 'GET', [], false);
            $providerApi = 'uapi';
        } catch (CpanelApiException $exception) {
            if (!$this->cpanel->isOperationUnavailable($exception)) {
                throw $exception;
            }
            $result = $this->cpanel->callLegacyApi2($connection, 'Fileman', 'fileop', ['op' => 'restorefile', 'sourcefiles' => ltrim($safePath, '/'), 'doubledecode' => 0]);
            $providerApi = 'api2_compatibility';
        }
        $this->audit->record($userId, $accountId, 'file.restore_trash', 'success', 'path', $safePath, ['provider_api' => $providerApi]);
        return ['path' => $safePath, 'provider_api' => $providerApi, 'cpanel' => $result['data']];
    }

    public function emptyTrash(int $userId, int $accountId): array
    {
        [, $connection] = $this->context($userId, $accountId, '.');
        $result = $this->cpanel->call($connection, 'Fileman', 'empty_trash', [], 'POST', [], false);
        $this->audit->record($userId, $accountId, 'trash.empty', 'success', 'trash', '.trash');
        return ['cpanel' => $result['data']];
    }

    /** @param list<string> $sources
     *  @return array<string,mixed>
     */
    public function compress(int $userId, int $accountId, array $sources, string $destination, string $format = 'zip'): array
    {
        if ($sources === [] || count($sources) > 100 || !in_array($format, ['zip', 'tar.gz', 'tar.bz2', 'tar', 'gz', 'bz2'], true)) {
            throw new AppException('Archive selection or format is invalid.', 422, 'invalid_archive_request', [], 'files.zip');
        }
        [, $connection] = $this->context($userId, $accountId, '.');
        $safeSources = array_map(fn (string $path): string => ltrim($this->context($userId, $accountId, $path)[3], '/'), $sources);
        $safeDestination = ltrim($this->context($userId, $accountId, $destination)[3], '/');
        $result = $this->cpanel->callLegacyApi2($connection, 'Fileman', 'fileop', ['op' => 'compress', 'sourcefiles' => implode(',', $safeSources), 'destfiles' => $safeDestination, 'metadata' => $format, 'doubledecode' => 0]);
        $this->audit->record($userId, $accountId, 'file.compress', 'success', 'archive', '/' . $safeDestination, ['count' => count($safeSources), 'format' => $format]);
        return ['destination' => '/' . $safeDestination, 'cpanel' => $result['data']];
    }

    /** @return array<string,mixed> */
    public function extract(int $userId, int $accountId, string $archive, string $destination): array
    {
        [, $connection, , $safeArchive] = $this->context($userId, $accountId, $archive);
        [, , , $safeDestination] = $this->context($userId, $accountId, $destination);
        if (!preg_match('/\.(?:zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|gz|bz2)$/i', $safeArchive)) {
            throw new AppException('The selected file is not a supported archive.', 422, 'unsupported_archive', [], 'files.zip');
        }
        $result = $this->cpanel->callLegacyApi2($connection, 'Fileman', 'fileop', ['op' => 'extract', 'sourcefiles' => ltrim($safeArchive, '/'), 'destfiles' => ltrim($safeDestination, '/'), 'doubledecode' => 0]);
        $this->audit->record($userId, $accountId, 'file.extract', 'success', 'archive', $safeArchive, ['destination' => $safeDestination]);
        return ['archive' => $safeArchive, 'destination' => $safeDestination, 'cpanel' => $result['data']];
    }

    /** @return list<array<string,mixed>> */
    public function search(int $userId, int $accountId, string $directory, string $query, string $type = 'all'): array
    {
        [, $connection, , $safeDirectory] = $this->context($userId, $accountId, $directory);
        $query = trim(mb_substr($query, 0, 100));
        if ($query === '' || !in_array($type, ['all', 'file', 'dir'], true)) {
            throw new AppException('Enter a valid file search query.', 422, 'invalid_file_search');
        }
        $result = $this->cpanel->callLegacyApi2($connection, 'Fileman', 'search', ['dir' => $safeDirectory, 'query' => $query, 'types' => $type === 'all' ? 'file|dir' : $type]);
        return array_slice($this->asList($result['data']), 0, 200);
    }

    /** @return list<array<string,mixed>> */
    public function versionHistory(int $userId, int $accountId, string $path): array
    {
        [, , , $safePath] = $this->context($userId, $accountId, $path);
        return $this->versions->history($userId, $accountId, $safePath);
    }

    public function restoreVersion(int $userId, int $accountId, string $path, int $versionId): void
    {
        [, $connection, $root, $safePath] = $this->context($userId, $accountId, $path);
        $this->versions->backupBeforeWrite($userId, $accountId, $connection, $safePath, $root, 'before_version_restore');
        $this->versions->restore($userId, $accountId, $connection, $versionId, $safePath);
        $this->audit->record($userId, $accountId, 'file.restore_version', 'success', 'file', $safePath, ['version_id' => $versionId]);
    }

    /** @return array{0:array<string,mixed>,1:array{base_url:string,username:string,token:string},2:string,3:string} */
    private function context(int $userId, int $accountId, string $path): array
    {
        $account = $this->accounts->getOwned($userId, $accountId);
        $root = rtrim((string) ($account['root_path'] ?: '/home/' . $account['cpanel_username']), '/');
        $safePath = $this->paths->normalize($path, $root);
        return [$account, $this->accounts->connection($userId, $accountId), $root, $safePath];
    }

    private function assertTextFile(string $path): void
    {
        $name = strtolower(basename($path));
        $extension = ltrim((string) pathinfo($name, PATHINFO_EXTENSION), '.');
        if ($name === '.env' || $name === '.htaccess') {
            return;
        }
        if (!in_array($extension, self::TEXT_EXTENSIONS, true)) {
            throw new AppException('This file type is not enabled in the text editor.', 415, 'unsupported_text_file', [], 'files.edit');
        }
    }

    private function isSensitivePath(string $path): bool
    {
        $name = strtolower(basename($path));
        return in_array($name, ['.env', '.htaccess', 'index.php', 'wp-config.php', 'configuration.php'], true);
    }

    private function languageFor(string $path): string
    {
        $name = strtolower(basename($path));
        if ($name === '.env') {
            return 'env';
        }
        if ($name === '.htaccess') {
            return 'apache';
        }
        return strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) ?: 'text';
    }

    public function resolveUploadName(int $userId, int $accountId, string $directory, string $name, string $policy): string
    {
        if (!in_array($policy, ['reject', 'overwrite', 'rename'], true)) {
            throw new AppException('Upload collision policy is invalid.', 422, 'invalid_collision_policy');
        }
        $candidate = $name;
        for ($index = 0; $index < 1000; $index++) {
            try {
                $info = $this->info($userId, $accountId, $directory . '/' . $candidate);
                $exists = (int) ($info['exists'] ?? 1) === 1;
            } catch (AppException $exception) {
                if ($exception->safeCode !== 'remote_path_not_found') {
                    throw $exception;
                }
                $exists = false;
            }
            if (!$exists || $policy === 'overwrite') {
                return $candidate;
            }
            if ($policy === 'reject') {
                throw new AppException('A file with this name already exists. Choose overwrite, automatic rename, or cancel.', 409, 'upload_collision', ['filename' => $name], 'files.upload');
            }
            $base = pathinfo($name, PATHINFO_FILENAME);
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $candidate = $base . '-' . ($index + 1) . ($extension !== '' ? '.' . $extension : '');
        }
        throw new AppException('A unique upload filename could not be generated.', 409, 'upload_collision_exhausted');
    }

    /** @return list<array<string,mixed>> */
    private function asList(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        foreach (['files', 'items', 'results'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        return [$data];
    }

    /** @return array<string,mixed> */
    private function first(mixed $data): array
    {
        $list = $this->asList($data);
        return $list[0] ?? [];
    }
}
