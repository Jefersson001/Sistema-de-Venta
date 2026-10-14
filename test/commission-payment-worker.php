<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/commission-repository.php';
echo "READY\n";flush();
try {
    $id=paySalesCommission(createDatabaseConnection(),(int)$argv[1],['amount'=>$argv[2],'account_id'=>$argv[3],
        'method_id'=>$argv[4],'currency_code'=>$argv[5],'movement_date'=>$argv[6]],bin2hex(random_bytes(32)));
    echo 'PAID '.$id."\n";
} catch(InvalidArgumentException $exception) { echo "REJECTED\n"; }
