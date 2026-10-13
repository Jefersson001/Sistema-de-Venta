<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/connection.php';require_once dirname(__DIR__).'/php/treasury-repository.php';
$db=createDatabaseConnection();echo "READY\n";flush();
try{confirmTreasuryDocument($db,(int)$argv[1],1);echo "DONE\n";}
catch(InvalidArgumentException $e){if(!str_contains($e->getMessage(),'no alcanza'))throw $e;echo "INSUFFICIENT\n";}
