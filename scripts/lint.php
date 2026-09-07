<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['app', 'bootstrap', 'cli', 'public', 'resources', 'routes', 'scripts', 'tests'];
$files = [];
foreach ($directories as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files, SORT_STRING);
$failures = [];
foreach ($files as $file) {
    $output = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
    if ($exit !== 0) {
        $failures[] = str_replace($root . '/', '', $file) . ': ' . implode(' ', $output);
    }
}
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, sprintf("PHP lint passed: %d files%s", count($files), PHP_EOL));
