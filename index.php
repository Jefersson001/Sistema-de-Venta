<?php

declare(strict_types=1);

require_once __DIR__ . '/php/database-status.php';

$modules = require __DIR__ . '/php/config/modules.php';
$databaseStatus = getDatabaseStatus();

require_once __DIR__.'/php/dashboard-repository.php';
require_once __DIR__.'/php/operation-status-repository.php';
$dashboard=['available'=>false];
if($databaseStatus['connected'])try{$dashboard=loadDashboard(createDatabaseConnection());}catch(PDOException $e){error_log('Inicio: '.$e->getMessage());}
require __DIR__ . '/html/dashboard.php';
