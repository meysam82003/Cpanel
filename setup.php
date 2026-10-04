<?php

declare(strict_types=1);

// Direct Setup & diagnostics entry: works without URL rewriting.
$_GET['r'] = '/setup';
require __DIR__ . '/index.php';
