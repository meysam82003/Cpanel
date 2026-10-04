<?php

declare(strict_types=1);

// Direct installer entry: works even when URL rewriting is unavailable,
// .htaccess was not extracted, or a parent folder's rules intercept requests.
$_GET['r'] = '/install';
require __DIR__ . '/index.php';
