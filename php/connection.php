<?php

declare(strict_types=1);

function createDatabaseConnection(): PDO
{
    $configuration = require __DIR__ . '/config/database.php';
    if (! preg_match('/^[a-zA-Z0-9_]+$/', $configuration['database'])) {
        throw new RuntimeException('El nombre de la base de datos contiene caracteres no permitidos.');
    }

    $serverDsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $configuration['host'],
        $configuration['port'],
        $configuration['charset'],
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $serverConnection = new PDO($serverDsn, $configuration['username'], $configuration['password'], $options);
    $serverConnection->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
        $configuration['database'],
        $configuration['charset'],
        $configuration['charset'] . '_general_ci',
    ));

    $databaseDsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $configuration['host'],
        $configuration['port'],
        $configuration['database'],
        $configuration['charset'],
    );

    return new PDO($databaseDsn, $configuration['username'], $configuration['password'], $options);
}
