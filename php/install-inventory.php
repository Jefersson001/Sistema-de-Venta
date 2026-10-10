<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/connection.php';

try {
    $db = createDatabaseConnection();
    $migration = file_get_contents(__DIR__ . '/migrations/004_inventory_categories.sql');
    if ($migration === false) {
        throw new RuntimeException('No se pudo leer la migración de Inventario.');
    }
    $db->exec($migration);
    echo "Categorías de Inventario instaladas.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'No se pudo instalar Inventario: ' . $exception->getMessage() . "\n");
    exit(1);
}
