<?php

declare(strict_types=1);

require_once __DIR__.'/php/auth.php';
require_once __DIR__.'/php/permissions.php';
$GLOBALS['permission_runtime']=true;
$currentUser=authRequireLogin();
require_once __DIR__ . '/php/database-status.php';

$modules = require __DIR__ . '/php/config/modules.php';
foreach($modules as $key=>&$module){$url=permissionModuleUrl($key);if(!$url){unset($modules[$key]);continue;}$module['url']=$url;}unset($module);
$databaseStatus = getDatabaseStatus();

require __DIR__ . '/html/home.php';
