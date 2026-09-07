<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Deployment\DeploymentFilesystem;
use RuntimeException;
use ZipArchive;

final class InMemoryDeploymentFilesystem implements DeploymentFilesystem
{
    /** @var array<string,array{type:string,size:int,is_symlink:bool,content:string}> */
    private array $entries = ['/'=> ['type' => 'dir', 'size' => 0, 'is_symlink' => false, 'content' => '']];

    /** @var list<array{operation:string,path:string,destination?:string}> */
    public array $calls = [];
    public ?string $throwBeforeMoveSource = null;
    public ?string $throwAfterMoveSource = null;
    public bool $failDelete = false;

    public function info(int $userId, int $accountId, string $path): ?array
    {
        $path = $this->path($path);
        if (!isset($this->entries[$path])) {
            return null;
        }
        $entry = $this->entries[$path];
        return ['path' => $path, 'type' => $entry['type'], 'size' => $entry['size'], 'is_symlink' => $entry['is_symlink']];
    }

    public function ensureDirectory(int $userId, int $accountId, string $path, string $permissions = '0700'): void
    {
        $path = $this->path($path);
        $current = '';
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= '/' . $segment;
            if (isset($this->entries[$current]) && $this->entries[$current]['type'] !== 'dir') {
                throw new RuntimeException('A fake deployment directory component is not a directory.');
            }
            $this->entries[$current] ??= ['type' => 'dir', 'size' => 0, 'is_symlink' => false, 'content' => ''];
        }
        $this->calls[] = ['operation' => 'mkdir', 'path' => $path];
    }

    public function uploadPackage(int $userId, int $accountId, string $directory, string $localPath, string $name): void
    {
        $this->ensureDirectory($userId, $accountId, $directory);
        $content = file_get_contents($localPath);
        if (!is_string($content)) {
            throw new RuntimeException('The fake upload source is unreadable.');
        }
        $path = $this->path($directory . '/' . $name);
        $this->entries[$path] = ['type' => 'file', 'size' => strlen($content), 'is_symlink' => false, 'content' => $content];
        $this->calls[] = ['operation' => 'upload', 'path' => $path];
    }

    public function download(int $userId, int $accountId, string $remotePath, string $localPath, int $maxBytes): array
    {
        $remotePath = $this->path($remotePath);
        $entry = $this->entries[$remotePath] ?? null;
        if ($entry === null || $entry['type'] !== 'file' || $entry['size'] > $maxBytes) {
            throw new RuntimeException('The fake download is unavailable or exceeds its limit.');
        }
        if (file_put_contents($localPath, $entry['content']) !== $entry['size']) {
            throw new RuntimeException('The fake download could not be written.');
        }
        $this->calls[] = ['operation' => 'download', 'path' => $remotePath];
        return ['bytes' => $entry['size'], 'sha256' => hash('sha256', $entry['content']), 'content_type' => 'application/zip'];
    }

    public function compressDirectory(int $userId, int $accountId, string $source, string $destination): void
    {
        $source = $this->path($source);
        if (($this->entries[$source]['type'] ?? null) !== 'dir') {
            throw new RuntimeException('The fake compression source is missing.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'tcm-fake-zip-');
        if ($temporary === false) {
            throw new RuntimeException('A fake ZIP could not be allocated.');
        }
        $zip = new ZipArchive();
        try {
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('A fake ZIP could not be opened.');
            }
            $base = basename($source);
            $zip->addEmptyDir($base);
            foreach ($this->entries as $path => $entry) {
                if (!str_starts_with($path . '/', $source . '/') || $path === $source) {
                    continue;
                }
                $relative = ltrim(substr($path, strlen($source)), '/');
                if ($entry['type'] === 'dir') {
                    $zip->addEmptyDir($base . '/' . $relative);
                } elseif ($entry['type'] === 'file') {
                    $zip->addFromString($base . '/' . $relative, $entry['content']);
                }
            }
            $zip->close();
            $content = file_get_contents($temporary);
            if (!is_string($content) || $content === '') {
                throw new RuntimeException('The fake ZIP is empty.');
            }
            $this->seedFile($destination, $content);
            $this->calls[] = ['operation' => 'compress', 'path' => $source, 'destination' => $this->path($destination)];
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function extract(int $userId, int $accountId, string $archive, string $destination): void
    {
        $archive = $this->path($archive);
        $entry = $this->entries[$archive] ?? null;
        if ($entry === null || $entry['type'] !== 'file') {
            throw new RuntimeException('The fake extraction archive is missing.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'tcm-fake-extract-');
        if ($temporary === false || file_put_contents($temporary, $entry['content']) !== $entry['size']) {
            throw new RuntimeException('The fake extraction archive could not be staged.');
        }
        $zip = new ZipArchive();
        try {
            if ($zip->open($temporary, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('The fake extraction archive is invalid.');
            }
            $this->ensureDirectory($userId, $accountId, $destination);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index, ZipArchive::FL_UNCHANGED);
                $trimmed = rtrim(str_replace('\\', '/', $name), '/');
                if ($trimmed === '' || str_starts_with($trimmed, '/') || str_contains('/' . $trimmed . '/', '/../')) {
                    throw new RuntimeException('The fake extraction encountered an unsafe path.');
                }
                $target = $this->path(rtrim($destination, '/') . '/' . $trimmed);
                if (str_ends_with($name, '/')) {
                    $this->ensureDirectory($userId, $accountId, $target);
                    continue;
                }
                $content = $zip->getFromIndex($index);
                if (!is_string($content)) {
                    throw new RuntimeException('A fake ZIP entry could not be read.');
                }
                $this->seedFile($target, $content);
            }
            $zip->close();
            $this->calls[] = ['operation' => 'extract', 'path' => $archive, 'destination' => $this->path($destination)];
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function moveDirectory(int $userId, int $accountId, string $source, string $destination): void
    {
        $source = $this->path($source);
        $destination = $this->path($destination);
        if ($this->throwBeforeMoveSource === $source) {
            $this->throwBeforeMoveSource = null;
            throw new RuntimeException('Simulated provider failure before rename.');
        }
        if (($this->entries[$source]['type'] ?? null) !== 'dir' || isset($this->entries[$destination])) {
            throw new RuntimeException('The fake atomic rename precondition failed.');
        }
        $moved = [];
        foreach ($this->entries as $path => $entry) {
            if ($path === $source || str_starts_with($path, $source . '/')) {
                $moved[$destination . substr($path, strlen($source))] = $entry;
                unset($this->entries[$path]);
            }
        }
        $this->entries += $moved;
        $this->calls[] = ['operation' => 'move', 'path' => $source, 'destination' => $destination];
        if ($this->throwAfterMoveSource === $source) {
            $this->throwAfterMoveSource = null;
            throw new RuntimeException('Simulated provider timeout after rename.');
        }
    }

    public function deleteTree(int $userId, int $accountId, string $path): void
    {
        $path = $this->path($path);
        if ($this->failDelete) {
            throw new RuntimeException('Simulated cleanup failure.');
        }
        foreach (array_keys($this->entries) as $entry) {
            if ($entry === $path || str_starts_with($entry, $path . '/')) {
                unset($this->entries[$entry]);
            }
        }
        $this->calls[] = ['operation' => 'delete', 'path' => $path];
    }

    public function seedDirectory(string $path): void
    {
        $this->ensureDirectory(0, 0, $path);
    }

    public function seedFile(string $path, string $content): void
    {
        $path = $this->path($path);
        $this->ensureDirectory(0, 0, dirname($path));
        $this->entries[$path] = ['type' => 'file', 'size' => strlen($content), 'is_symlink' => false, 'content' => $content];
    }

    public function content(string $path): ?string
    {
        $entry = $this->entries[$this->path($path)] ?? null;
        return $entry !== null && $entry['type'] === 'file' ? $entry['content'] : null;
    }

    public function exists(string $path): bool
    {
        return isset($this->entries[$this->path($path)]);
    }

    private function path(string $path): string
    {
        $path = preg_replace('#/+#', '/', str_replace('\\', '/', $path)) ?? '';
        if ($path === '' || $path[0] !== '/' || str_contains('/' . $path . '/', '/../') || str_contains($path, "\0")) {
            throw new RuntimeException('The fake filesystem path is unsafe.');
        }
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
