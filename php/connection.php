<?php

declare(strict_types=1);

function createDatabaseConnection(bool $unscoped = false): PDO
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

    if (!$unscoped && isset($GLOBALS['company_context'])) {
        require_once __DIR__.'/company-connection.php';
        $context=$GLOBALS['company_context'];
        return new CompanyConnection($databaseDsn,$configuration['username'],$configuration['password'],$options,
            $context['read_selected']??$context['selected'],$context['active'],$context['actor'],$context['tables']);
    }
    $connection=new PDO($databaseDsn, $configuration['username'], $configuration['password'], $options);
    $connection->exec("SET time_zone='-04:00'");
    return $connection;
}
