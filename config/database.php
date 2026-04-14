<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => \App\Utils\Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) \App\Utils\Env::get('DB_PORT', 3306),
    'database' => \App\Utils\Env::get('DB_DATABASE', 'sistem_pay'),
    'username' => \App\Utils\Env::get('DB_USERNAME', 'root'),
    'password' => \App\Utils\Env::get('DB_PASSWORD', ''),
    'charset' => \App\Utils\Env::get('DB_CHARSET', 'utf8mb4'),
    'collation' => \App\Utils\Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];
