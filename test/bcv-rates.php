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
    // Do not depend on whatever real rates happen to exist in the source installation.
    $db->exec('SET @nexo_company_id=1');$db->exec('DELETE FROM accounting_currency_rates WHERE company_id=1');
    $db->exec('UPDATE accounting_currency_settings SET exchange_rate=NULL WHERE company_id=1');
    $ids=$db->query("SELECT code,id FROM accounting_currencies WHERE company_id=1 AND code IN('USD','VES')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $db->prepare("INSERT INTO accounting_currency_rates(company_id,currency_id,base_currency_id,rate_date,rate,source) VALUES(1,?,?,?,'77.00','manual')")->execute([$ids['VES'],$ids['USD'],commerceToday()]);
    $second=createBusinessCompany($db,['name'=>'BCV segunda empresa'],$actor);
    bcvCheck(currencyRateSnapshot($db,'USD','VES',commerceToday(),$second)===null,'Se copió una tasa manual de otra empresa sin publicaciones oficiales.');
    $today=commerceToday();$tomorrow=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    bcvApplyPublication($db,['rate'=>'60.00','effective_date'=>$tomorrow,'source'=>'bcv']);
    $futureOnly=createBusinessCompany($db,['name'=>'BCV empresa con solo publicación futura'],$actor);
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,$futureOnly)===null&&currencyRateSnapshot($db,'USD','VES',$tomorrow,$futureOnly)['rate']==='60.00','Se utiliza una publicación futura como si ya estuviera vigente.');
    foreach([$today=>'50.00',$tomorrow=>'60.00'] as $date=>$rate)bcvApplyPublication($db,['rate'=>$rate,'effective_date'=>$date,'source'=>'bcv']);
    foreach([1,$second] as $company){
        bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,$company)['rate']==='50.00','Una tasa futura se aplicó antes de Fecha Valor.');
        bcvCheck(currencyRateSnapshot($db,'VES','USD',$tomorrow,$company)['rate']==='0.02','La conversión inversa es incorrecta.');
        $q=$db->prepare("SELECT exchange_rate FROM accounting_currency_settings WHERE company_id=? AND id=1");$q->execute([$company]);bcvCheck($q->fetchColumn()==='50.00','La configuración no usa la tasa actual de su empresa.');
    }
    $third=createBusinessCompany($db,['name'=>'BCV empresa con tasa inmediata'],$actor);
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,$third)['rate']==='50.00','La empresa nueva no recibió la tasa vigente al crearla.');
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$tomorrow,$third)['rate']==='60.00','La empresa nueva perdió la publicación futura.');
    $q=$db->prepare('SELECT exchange_rate FROM accounting_currency_settings WHERE company_id=?');$q->execute([$third]);bcvCheck($q->fetchColumn()==='50.00','La configuración de la nueva empresa usa la tasa futura o queda vacía.');
    // Reproduce a company created by the previous version that has only tomorrow's quote.
    $db->exec('SET @nexo_company_id='.$third);$db->prepare('DELETE FROM accounting_currency_rates WHERE company_id=? AND rate_date<=?')->execute([$third,$today]);
    $db->prepare('UPDATE accounting_currency_settings SET exchange_rate=NULL WHERE company_id=?')->execute([$third]);
    bcvApplyPublication($db,['rate'=>'61.00','effective_date'=>$tomorrow,'source'=>'bcv']);
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,$third)['rate']==='50.00','Sincronizar una publicación futura no recupera la tasa vigente de una empresa vacía.');
    $q=$db->prepare("SELECT COUNT(*) FROM accounting_currency_rates WHERE company_id=? AND rate_date=? AND source='bcv'");$q->execute([$second,$tomorrow]);bcvCheck((int)$q->fetchColumn()===1,'Repetir la publicación duplicó la tasa.');
    $db->exec('SET @nexo_company_id='.$second.', @nexo_actor_id=NULL');$db->prepare("UPDATE accounting_currency_settings SET primary_code='VES',alternate_code='USD' WHERE company_id=?")->execute([$second]);
    bcvApplyPublication($db,['rate'=>'50.00','effective_date'=>$today,'source'=>'bcv']);
    $q=$db->prepare('SELECT exchange_rate FROM accounting_currency_settings WHERE company_id=?');$q->execute([$second]);bcvCheck($q->fetchColumn()==='0.02','Se supuso que todas las empresas tienen USD como moneda principal.');
    putenv('NEXO_DATABASE_NAME='.$server['database']);$stateFiles=[bcvStatePath(),bcvStatePath().'.lock'];
    $state=bcvSync(true,static fn()=>['rate'=>'50.00','effective_date'=>$today,'source'=>'bcv','url'=>BCV_SOURCE_URL]);$lastSuccess=$state['success_at'];
    try{bcvSync(true,static fn()=>throw new RuntimeException('Sin conexión de prueba'));throw new LogicException('Se ocultó un error de consulta.');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
    $failed=bcvReadState();bcvCheck($failed['success_at']===$lastSuccess&&$failed['error']==='Sin conexión de prueba'&&$failed['publication']['rate']==='50.00','Un fallo eliminó la última publicación válida.');
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,1)['rate']==='50.00','Un fallo cambió las tasas guardadas.');
    $offline=createBusinessCompany($db,['name'=>'BCV empresa sin conexión externa'],$actor);
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,$offline)['rate']==='50.00','Crear una empresa depende de que el BCV responda en ese momento.');
    $missing=createBusinessCompany($db,['name'=>'BCV empresa anterior por reparar'],$actor);
    $db->exec('SET @nexo_company_id='.$missing);$db->prepare('DELETE FROM accounting_currency_rates WHERE company_id=? AND rate_date<=?')->execute([$missing,$today]);
    $db->prepare('UPDATE accounting_currency_settings SET exchange_rate=NULL WHERE company_id=?')->execute([$missing]);
    $manual=createBusinessCompany($db,['name'=>'BCV empresa con tasa manual'],$actor);
    $db->exec('SET @nexo_company_id='.$manual.', @nexo_actor_id='.$actor);
    $db->prepare("UPDATE accounting_currency_rates SET rate=75,source='manual' WHERE company_id=? AND rate_date=?")->execute([$manual,$today]);
    $db->prepare('UPDATE accounting_currency_settings SET exchange_rate=75 WHERE company_id=?')->execute([$manual]);
    $invoices=$db->query('SELECT id,total_amount,exchange_rate,exchange_rate_date,exchange_rate_source FROM accounting_invoices ORDER BY id')->fetchAll();
    $repair=require dirname(__DIR__).'/php/migrations/versioned/20261113123604_inicializar_tasa_bcv_empresas.php';$repair($db);
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,$missing)['rate']==='50.00','La migración no repara una empresa que ya quedó sin tasa.');
    bcvCheck(currencyRateSnapshot($db,'USD','VES',$today,$manual)['rate']==='75.00','La migración sustituyó una tasa manual existente.');
    $snapshot=$db->query('SELECT * FROM accounting_currency_rates ORDER BY id')->fetchAll();$settings=$db->query('SELECT * FROM accounting_currency_settings ORDER BY company_id')->fetchAll();$repair($db);
    bcvCheck($snapshot===$db->query('SELECT * FROM accounting_currency_rates ORDER BY id')->fetchAll()&&$settings===$db->query('SELECT * FROM accounting_currency_settings ORDER BY company_id')->fetchAll(),'Repetir la migración modifica o duplica tasas.');
    bcvCheck($invoices===$db->query('SELECT id,total_amount,exchange_rate,exchange_rate_date,exchange_rate_source FROM accounting_invoices ORDER BY id')->fetchAll(),'La inicialización recalculó documentos históricos.');
    $context=$db->query('SELECT @nexo_company_id AS company_id,@nexo_actor_id AS actor_id')->fetch();bcvCheck((int)$context['company_id']===$manual&&(int)$context['actor_id']===$actor,'La migración no restaura el contexto de empresa y autoría.');
    echo "PASS: parser, Fecha Valor, futuras/inversas, tasa inmediata al crear empresas, sin copiar manuales ni depender de red, recuperación al sincronizar, migración repetible, contexto e historial conservados.\n";
}finally{
    if($oldDatabase===false)putenv('NEXO_DATABASE_NAME');else putenv('NEXO_DATABASE_NAME='.$oldDatabase);
    foreach($stateFiles as $file)if(is_file($file))unlink($file);stopAuthTestServer($server);
}
