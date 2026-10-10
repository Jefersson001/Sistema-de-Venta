<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';

/**
 * @return array{connected: bool, database: string, error: string|null}
 */
function getDatabaseStatus(): array
{
    $configuration = require __DIR__ . '/config/database.php';

    try {
        $connection = createDatabaseConnection();
        $connection->query('SELECT 1')->fetchColumn();

        return ['connected' => true, 'database' => $configuration['database'], 'error' => null];
    } catch (PDOException $exception) {
        return ['connected' => false, 'database' => $configuration['database'], 'error' => $exception->getMessage()];
    }
}
