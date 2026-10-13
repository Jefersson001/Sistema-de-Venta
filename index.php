<?php

declare(strict_types=1);

require_once __DIR__ . '/php/database-status.php';

$modules = require __DIR__ . '/php/config/modules.php';
$databaseStatus = getDatabaseStatus();

require __DIR__ . '/html/home.php';
