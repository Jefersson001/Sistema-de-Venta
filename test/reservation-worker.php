<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/operation-status-repository.php';
$db=createDatabaseConnection();
echo "READY\n";flush();
try{transitionInventoryOperation($db,(int)($argv[1]??0),(int)($argv[2]??0),'check_ready');echo "RESERVED\n";}
catch(InvalidArgumentException $e){echo "WAITING\n";}
