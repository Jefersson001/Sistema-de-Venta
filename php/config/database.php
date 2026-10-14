<?php

declare(strict_types=1);

return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => getenv('NEXO_DATABASE_NAME') ?: 'data',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
];
