<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!preg_match('/^nexo_auth_test_[a-f0-9]{16}$/D',getenv('NEXO_DATABASE_NAME')?:'')){http_response_code(404);exit(1);}
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/operation-status-repository.php';
try{transitionInventoryOperation(createDatabaseConnection(),(int)($argv[1]??0),2,'check_ready');echo "reserved\n";}
catch(InvalidArgumentException){echo "rejected\n";exit(2);}
