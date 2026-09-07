<?php

declare(strict_types=1);

use App\Core\AppException;
use App\Core\Container;
use App\FileManager\ArchiveService;
use App\FileManager\DownloadService;
use App\FileManager\FileManagerService;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\UploadReceiver;
use App\Plans\PlanGuard;
use App\Security\ConfirmationService;

return static function (ApiKernel $api, Container $container): void {
    $files = $container->get(FileManagerService::class);
    $archives = $container->get(ArchiveService::class);
    $downloads = $container->get(DownloadService::class);
    $uploads = $container->get(UploadReceiver::class);
    $plans = $container->get(PlanGuard::class);
    $confirmations = $container->get(ConfirmationService::class);

    $api->route('GET', '/api/v1/hosts/{account}/files', static function (Request $request, array $params, array $session) use ($files): array {
        return $files->browse((int) $session['user_id'], (int) $params['account'], (string) $request->input('path', '.'), (int) $request->input('page', 1), (int) $request->input('per_page', 50), (string) $request->input('sort', 'name'), (string) $request->input('direction', 'asc'), filter_var($request->input('hidden', false), FILTER_VALIDATE_BOOL));
    }, 180);
    $api->route('GET', '/api/v1/hosts/{account}/files/info', static fn (Request $request, array $params, array $session): array => $files->info((int) $session['user_id'], (int) $params['account'], (string) $request->input('path', '')), 180);
    $api->route('GET', '/api/v1/hosts/{account}/files/content', static fn (Request $request, array $params, array $session): array => $files->readText((int) $session['user_id'], (int) $params['account'], (string) $request->input('path', ''), (int) $request->input('offset', 0), (int) $request->input('length', 131072)), 120);

    $api->route('PUT', '/api/v1/hosts/{account}/files/content', static function (Request $request, array $params, array $session) use ($files, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $path = (string) $request->input('path', '');
        if (in_array(strtolower(basename($path)), ['.htaccess', 'index.php'], true)) {
            $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'file.replace_sensitive', $path);
        }
        return $files->saveText($userId, $accountId, $path, (string) $request->input('content', ''), filter_var($request->input('backup', true), FILTER_VALIDATE_BOOL));
    }, 40);
    $api->route('POST', '/api/v1/hosts/{account}/files/new', static fn (Request $request, array $params, array $session): array => $files->createFile((int) $session['user_id'], (int) $params['account'], (string) $request->input('directory', '.'), (string) $request->input('name', '')), 40);
    $api->route('POST', '/api/v1/hosts/{account}/folders', static fn (Request $request, array $params, array $session): array => $files->createFolder((int) $session['user_id'], (int) $params['account'], (string) $request->input('directory', '.'), (string) $request->input('name', ''), (string) $request->input('permissions', '0755')), 40);

    $api->route('POST', '/api/v1/hosts/{account}/files/upload', static function (Request $request, array $params, array $session) use ($files, $uploads, $plans): array {
        $userId = (int) $session['user_id'];
        $plan = $plans->plan($userId);
        $entry = $request->files['files'] ?? $request->files['file'] ?? null;
        if (!is_array($entry)) {
            throw new AppException('Select at least one file to upload.', 422, 'upload_missing', [], 'files.upload');
        }
        $received = [];
        try {
            foreach ($uploads->normalizeMultiple($entry) as $upload) {
                $item = $uploads->receive($upload, (int) $plan['max_upload_bytes'], []);
                $plans->upload($userId, $item['size']);
                $received[] = $item;
            }
            return $files->upload($userId, (int) $params['account'], (string) $request->input('directory', '.'), array_map(static fn (array $item): array => ['path' => $item['path'], 'name' => $item['name'], 'mime' => $item['mime']], $received), (string) $request->input('collision', 'reject'));
        } finally {
            foreach ($received as $item) {
                @unlink($item['path']);
            }
        }
    }, 20);

    $api->route('POST', '/api/v1/hosts/{account}/files/move', static fn (Request $request, array $params, array $session): array => $files->renameOrMove((int) $session['user_id'], (int) $params['account'], (string) $request->input('source', ''), (string) $request->input('destination', '')), 40);
    $api->route('POST', '/api/v1/hosts/{account}/files/copy', static fn (Request $request, array $params, array $session): array => $files->copy((int) $session['user_id'], (int) $params['account'], (string) $request->input('source', ''), (string) $request->input('destination', '')), 40);
    $api->route('DELETE', '/api/v1/hosts/{account}/files', static function (Request $request, array $params, array $session) use ($files, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $path = (string) $request->input('path', '');
        $permanent = filter_var($request->input('permanent', false), FILTER_VALIDATE_BOOL);
        $info = $files->info($userId, $accountId, $path);
        $isDirectory = in_array(strtolower((string) ($info['type'] ?? '')), ['dir', 'directory'], true);
        if ($permanent || $isDirectory) {
            $action = $permanent ? 'file.delete_permanent' : 'file.delete_recursive';
            $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, $action, (string) $info['path']);
        }
        return $files->delete($userId, $accountId, (string) $info['path'], $permanent);
    }, 30);

    $api->route('POST', '/api/v1/hosts/{account}/trash/restore', static fn (Request $request, array $params, array $session): array => $files->restoreTrash((int) $session['user_id'], (int) $params['account'], (string) $request->input('path', '')), 30);
    $api->route('DELETE', '/api/v1/hosts/{account}/trash', static function (Request $request, array $params, array $session) use ($files, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'trash.empty', 'trash');
        return $files->emptyTrash($userId, $accountId);
    }, 10);
    $api->route('POST', '/api/v1/hosts/{account}/archives', static function (Request $request, array $params, array $session) use ($archives): array {
        $sources = $request->input('sources', []);
        if (!is_array($sources)) {
            throw new AppException('Archive sources must be a list.', 422, 'invalid_archive_request', [], 'files.zip');
        }
        return $archives->enqueueCreate((int) $session['user_id'], (int) $params['account'], array_values($sources), (string) $request->input('destination', ''), (string) $request->input('format', 'zip'));
    }, 15);
    $api->route('POST', '/api/v1/hosts/{account}/archives/extract', static function (Request $request, array $params, array $session) use ($archives, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $archive = (string) $request->input('archive', '');
        $destination = (string) $request->input('destination', '');
        $collision = (string) $request->input('collision', 'reject');
        if ($collision === 'overwrite') {
            $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'archive.extract_overwrite', $archive . '|' . $destination);
        }
        return $archives->enqueueExtract($userId, $accountId, $archive, $destination, $collision);
    }, 15);
    $api->route('GET', '/api/v1/hosts/{account}/files/search', static fn (Request $request, array $params, array $session): array => ['items' => $files->search((int) $session['user_id'], (int) $params['account'], (string) $request->input('directory', '.'), (string) $request->input('q', ''), (string) $request->input('type', 'all'))], 60);

    $api->route('GET', '/api/v1/hosts/{account}/files/versions', static fn (Request $request, array $params, array $session): array => ['versions' => $files->versionHistory((int) $session['user_id'], (int) $params['account'], (string) $request->input('path', ''))]);
    $api->route('POST', '/api/v1/hosts/{account}/files/versions/{version}/restore', static function (Request $request, array $params, array $session) use ($files, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $path = (string) $request->input('path', '');
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'file.restore_version', $path . ':' . $params['version']);
        $files->restoreVersion($userId, $accountId, $path, (int) $params['version']);
        return ['restored' => true];
    }, 20);
    $api->route('POST', '/api/v1/hosts/{account}/download-links', static fn (Request $request, array $params, array $session): array => $downloads->issue((int) $session['user_id'], (int) $params['account'], (string) $request->input('path', '')), 20);
};
