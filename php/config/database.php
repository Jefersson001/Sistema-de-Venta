<?php

declare(strict_types=1);

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
    // Never include the URL in errors: it contains the database password.
    $parts = parse_url(trim($databaseUrl));
    if ($parts === false
        || !in_array(strtolower($parts['scheme'] ?? ''), ['mysql', 'mariadb'], true)
        || empty($parts['host']) || !isset($parts['user'])
        || !preg_match('/^[a-zA-Z0-9_.:\[\]-]+$/D', $parts['host'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('DATABASE_URL debe ser una URL mysql://usuario:clave@host:puerto/base sin parámetros adicionales.');
    }
    $database = rawurldecode(ltrim($parts['path'] ?? '', '/'));
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $database)) {
        throw new RuntimeException('DATABASE_URL debe indicar un nombre de base de datos válido.');
    }
    $configuration = [
        'host' => $parts['host'],
        'port' => $parts['port'] ?? 3306,
        'database' => $database,
        'username' => rawurldecode($parts['user']),
        'password' => rawurldecode($parts['pass'] ?? ''),
        'charset' => 'utf8mb4',
        // Dokploy provisions the database separately. Runtime needs no CREATE privilege.
        'create_database' => false,
    ];
    // Preserve the isolated test database override even when DATABASE_URL is set.
    if ($testDatabase = getenv('NEXO_DATABASE_NAME')) {
        $configuration['database'] = $testDatabase;
    }
}

return $configuration;
