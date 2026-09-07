<?php

declare(strict_types=1);

use App\Core\Container;
use App\Http\ApiKernel;

return static function (ApiKernel $api, Container $container): void {
    foreach (['core', 'files', 'database', 'hosting', 'admin'] as $group) {
        $register = require __DIR__ . '/api/' . $group . '.php';
        $register($api, $container);
    }
};
