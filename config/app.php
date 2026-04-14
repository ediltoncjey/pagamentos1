<?php

declare(strict_types=1);

return [
    'name' => \App\Utils\Env::get('APP_NAME', 'SISTEM_PAY'),
    'env' => \App\Utils\Env::get('APP_ENV', 'production'),
    'debug' => filter_var(\App\Utils\Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => \App\Utils\Env::get('APP_URL', 'http://localhost/sistem_pay'),
    'timezone' => \App\Utils\Env::get('APP_TIMEZONE', 'Africa/Maputo'),
    'storage_path' => dirname(__DIR__) . '/storage',
    'log_path' => dirname(__DIR__) . '/storage/logs/app.log',
];
