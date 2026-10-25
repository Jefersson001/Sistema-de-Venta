<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auth-server.php';require_once dirname(__DIR__).'/php/bcv-sync.php';require_once dirname(__DIR__).'/php/company-repository.php';require_once dirname(__DIR__).'/php/exchange-rate-schema.php';
function bcvCheck(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
$html='<div id="dolar"><strong>1.234,56789000</strong></div><span class="date-display-single" content="2026-10-26T00:00:00-04:00">Lunes</span>';
$publication=bcvParsePublication($html);bcvCheck($publication['rate']==='1234.57'&&$publication['effective_date']==='2026-10-26','No se respeta Fecha Valor o la notación decimal oficial.');
foreach(['<html>Error del servidor</html>',str_replace('1.234,56789000','0,00000000',$html),str_replace('2026-10-26','2026-02-30',$html)] as $bad){try{bcvParsePublication($bad);throw new LogicException('Publicación inválida aceptada.');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}}
$server=startAuthTestServer();$oldDatabase=getenv('NEXO_DATABASE_NAME');$stateFiles=[];
try{
    $db=$server['db'];installExchangeRateMetadata($db);installExchangeRateMetadata($db);
    $setup=prepareFirstAdministrator($db);$password=bin2hex(random_bytes(12));$actor=createFirstAdministrator($db,['name'=>'BCV Test','login'=>'bcv.'.bin2hex(random_bytes(4)),'password'=>$password,'password_confirmation'=>$password],hash('sha256',$setup));
    $second=createBusinessCompany($db,['name'=>'BCV segunda empresa'],$actor);
    $today=commerceToday();$tomorrow=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    foreach([$today=>'50.00',$tomorrow=>'60.00'] as $date=>$rate)bcvApplyPublication($db,['rate'=>$rate,'effective_date'=>$date,'source'=>'bcv']);
    foreach([1,$second] as $company){
        bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,$company)['rate']==='50.00','Una tasa futura se aplicó antes de Fecha Valor.');
        bcvCheck(currencyRateSnapshot($db,'VES','USD',$tomorrow,$company)['rate']==='0.02','La conversión inversa es incorrecta.');
        $q=$db->prepare("SELECT exchange_rate FROM accounting_currency_settings WHERE company_id=? AND id=1");$q->execute([$company]);bcvCheck($q->fetchColumn()==='50.00','La configuración no usa la tasa actual de su empresa.');
    }
    bcvApplyPublication($db,['rate'=>'61.00','effective_date'=>$tomorrow,'source'=>'bcv']);
    $q=$db->prepare("SELECT COUNT(*) FROM accounting_currency_rates WHERE company_id=? AND rate_date=? AND source='bcv'");$q->execute([$second,$tomorrow]);bcvCheck((int)$q->fetchColumn()===1,'Repetir la publicación duplicó la tasa.');
    $db->exec('SET @nexo_company_id='.$second.', @nexo_actor_id=NULL');$db->prepare("UPDATE accounting_currency_settings SET primary_code='VES',alternate_code='USD' WHERE company_id=?")->execute([$second]);
    bcvApplyPublication($db,['rate'=>'50.00','effective_date'=>$today,'source'=>'bcv']);
    $q=$db->prepare('SELECT exchange_rate FROM accounting_currency_settings WHERE company_id=?');$q->execute([$second]);bcvCheck($q->fetchColumn()==='0.02','Se supuso que todas las empresas tienen USD como moneda principal.');
    putenv('NEXO_DATABASE_NAME='.$server['database']);$stateFiles=[bcvStatePath(),bcvStatePath().'.lock'];
    $state=bcvSync(true,static fn()=>['rate'=>'50.00','effective_date'=>$today,'source'=>'bcv','url'=>BCV_SOURCE_URL]);$lastSuccess=$state['success_at'];
    try{bcvSync(true,static fn()=>throw new RuntimeException('Sin conexión de prueba'));throw new LogicException('Se ocultó un error de consulta.');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
    $failed=bcvReadState();bcvCheck($failed['success_at']===$lastSuccess&&$failed['error']==='Sin conexión de prueba'&&$failed['publication']['rate']==='50.00','Un fallo eliminó la última publicación válida.');
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,1)['rate']==='50.00','Un fallo cambió las tasas guardadas.');
    echo "PASS: parser oficial, Fecha Valor, tasas futuras e inversas, empresas, moneda principal invertida, repetición, historial y conservación ante fallos.\n";
}finally{
    if($oldDatabase===false)putenv('NEXO_DATABASE_NAME');else putenv('NEXO_DATABASE_NAME='.$oldDatabase);
    foreach($stateFiles as $file)if(is_file($file))unlink($file);stopAuthTestServer($server);
}
