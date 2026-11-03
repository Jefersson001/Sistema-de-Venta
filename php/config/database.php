<?php

declare(strict_types=1);
require_once __DIR__.'/../database-url.php';

$configuration = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => getenv('NEXO_DATABASE_NAME') ?: 'data',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'create_database' => true,
];

$databaseUrl = getenv('DATABASE_URL');
if ($databaseUrl !== false && trim($databaseUrl) !== '') {
    $configuration = databaseUrlConfiguration($databaseUrl);
    // Preserve the isolated test database override even when DATABASE_URL is set.
    if ($testDatabase = getenv('NEXO_DATABASE_NAME')) {
        $configuration['database'] = $testDatabase;
    }
}

return $configuration;
