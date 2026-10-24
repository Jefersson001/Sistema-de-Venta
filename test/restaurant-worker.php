<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/restaurant-repository.php';
require_once dirname(__DIR__).'/php/company-connection.php';
$c=require dirname(__DIR__).'/php/config/database.php';
if(!preg_match('/^nexo_auth_test_[a-f0-9]{16}$/D',$c['database']))throw new RuntimeException('Se requiere una base temporal.');
$raw=createDatabaseConnection(true);$tables=$raw->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);
$db=new CompanyConnection('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false],[1],1,(int)$argv[1],$tables);
echo "READY\n";flush();fgets(STDIN);
try {
    $id=restaurantOpenAccount($db,['table_id'=>$argv[2],'table_version'=>$argv[3],'waiter_id'=>$argv[1],'guests'=>1,'notes'=>'','request_key'=>$argv[4]]);
    echo 'OPENED '.$id.PHP_EOL;
}catch(InvalidArgumentException $e){echo 'REJECTED '.$e->getCode().PHP_EOL;}
