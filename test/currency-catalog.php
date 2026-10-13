<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/currency-schema.php';
require_once dirname(__DIR__).'/php/currency-catalog-repository.php';
function currencyCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function currencyRejected(callable $action): void { try { $action(); } catch (InvalidArgumentException|PDOException $e) { return; } throw new RuntimeException('Se aceptó una modificación inválida.'); }
$db=createDatabaseConnection(); $testDatabase='nexo_currency_test_'.bin2hex(random_bytes(8));
currencyCheck(preg_match('/^nexo_currency_test_[a-f0-9]{16}$/',$testDatabase)===1,'Nombre de pruebas incorrecto.');
$db->exec('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4');
try {
    $db->exec('USE `'.$testDatabase.'`');
    $db->exec('CREATE TABLE accounting_currency_settings(id TINYINT PRIMARY KEY,primary_code CHAR(3),alternate_code CHAR(3),exchange_rate DECIMAL(20,8),version INT)');
    $db->exec("INSERT INTO accounting_currency_settings VALUES(1,'USD','VES',150,1)");
    foreach (['inventory_products','sales_quotations','purchase_orders','accounting_invoices','accounting_payments','accounting_payment_accounts'] as $table) $db->exec('CREATE TABLE '.$table.'(currency_code CHAR(3),currency_primary_code CHAR(3),currency_alternate_code CHAR(3),primary_code CHAR(3),alternate_code CHAR(3))');
    installCurrencyCatalog($db); installCurrencyCatalog($db);
    currencyCheck((int)$db->query('SELECT COUNT(*) FROM accounting_currencies')->fetchColumn()===2 && (int)$db->query('SELECT COUNT(*) FROM accounting_currency_rates')->fetchColumn()===1,'La migración no es repetible.');
    $ids=$db->query('SELECT code,id FROM accounting_currencies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $data=['code'=>'EUR','name'=>'Euro','symbol'=>'€','active'=>'1','unit_label'=>'Euro','subunit_label'=>'Céntimo','symbol_position'=>'after'];
    $id=saveCurrencyRecord($db,$data,null,null);
    $yesterday=(new DateTimeImmutable(commerceToday()))->modify('-1 day')->format('Y-m-d');
    $tomorrow=(new DateTimeImmutable(commerceToday()))->modify('+1 day')->format('Y-m-d');
    $rates=[['rate_date'=>$yesterday,'base_currency_id'=>(string)$ids['USD'],'rate'=>'0.80'],['rate_date'=>$tomorrow,'base_currency_id'=>(string)$ids['USD'],'rate'=>'0.90']];
    saveCurrencyRecord($db,$data+['rates'=>$rates],$id,1);
    currencyCheck(currencyRateForPair($db,'USD','EUR',commerceToday())==='0.80000000' && currencyRateForPair($db,'USD','EUR',$tomorrow)==='0.90000000','Se usa una tasa futura antes de su fecha.');
    currencyCheck(currencyRateForPair($db,'EUR','USD',commerceToday())==='1.25000000','La tasa inversa es incorrecta.');
    currencyRejected(fn()=>saveCurrencyRecord($db,$data,$id,1));
    currencyRejected(fn()=>saveCurrencyRecord($db,array_replace($data,['code'=>'GBP']),$id,2));
    currencyRejected(fn()=>saveCurrencyRecord($db,$data+['rates'=>[['rate_date'=>'2026-02-31','base_currency_id'=>$ids['USD'],'rate'=>'1']]],$id,2));
    currencyRejected(fn()=>saveCurrencyRecord($db,$data+['rates'=>[['rate_date'=>commerceToday(),'base_currency_id'=>$id,'rate'=>'1']]],$id,2));
    currencyRejected(fn()=>saveCurrencyRecord($db,$data+['rates'=>[['rate_date'=>commerceToday(),'base_currency_id'=>$ids['USD'],'rate'=>'0']]],$id,2));
    currencyRejected(fn()=>saveCurrencyRecord($db,$data+['rates'=>[$rates[0],$rates[0]]],$id,2));
    currencyRejected(fn()=>saveCurrencyRecord($db,$data,null,null));
    $other=saveCurrencyRecord($db,array_replace($data,['code'=>'GBP','name'=>'Libra']),null,null);
    $ownRate=loadCurrencyRecord($db,$id)['rates'][0];
    currencyRejected(fn()=>saveCurrencyRecord($db,array_replace($data,['code'=>'GBP','name'=>'Libra','rates'=>[$ownRate]]),$other,1));
    saveCurrencyRecord($db,array_replace($data,['symbol'=>'EUR']),$id,2);
    currencyCheck(count(loadCurrencyRecord($db,$id)['rates'])===2,'Editar los datos eliminó el historial.');
    $usd=loadCurrencyRecord($db,(int)$ids['USD']);
    currencyRejected(fn()=>saveCurrencyRecord($db,array_replace($usd,['active'=>'0']),$ids['USD'],1));
    currencyRejected(fn()=>saveBusinessCurrencies($db,['primary_id'=>$ids['USD'],'alternate_id'=>$ids['USD'],'version'=>'1']));
    saveBusinessCurrencies($db,['primary_id'=>$ids['USD'],'alternate_id'=>$id,'version'=>'1']);
    $settings=accountingCurrencies($db);
    currencyCheck($settings['primary_code']==='USD' && $settings['alternate_code']==='EUR' && $settings['exchange_rate']==='0.80000000','La selección principal/alterna no usa la tasa vigente.');
    currencyCheck(accountingCurrencies($db,$tomorrow)['exchange_rate']==='0.90000000','La tasa no cambia al llegar su fecha.');
    currencyRejected(fn()=>saveBusinessCurrencies($db,['primary_id'=>$id,'alternate_id'=>$ids['USD'],'version'=>'1']));
    saveBusinessCurrencies($db,['primary_id'=>$id,'alternate_id'=>$ids['USD'],'version'=>'2']);
    currencyCheck(accountingCurrencies($db)['exchange_rate']==='1.25000000','Cambiar la principal no conserva el sentido de conversión.');
    saveCurrencyRecord($db,array_replace($data,['code'=>'GBP','name'=>'Libra','active'=>'0']),$other,1);
    currencyRejected(fn()=>saveBusinessCurrencies($db,['primary_id'=>$id,'alternate_id'=>$other,'version'=>'3']));
    // Saving only the displayed rates preserves the rows on other notebook pages.
    $many=[];for($i=1;$i<=30;$i++)$many[]=['rate_date'=>(new DateTimeImmutable($yesterday))->modify('-'.$i.' days')->format('Y-m-d'),'base_currency_id'=>$ids['USD'],'rate'=>'0.70'];
    saveCurrencyRecord($db,$data+['rates'=>$many],$id,3);
    $one=loadCurrencyRecord($db,$id)['rates'][0];saveCurrencyRecord($db,$data+['rates'=>[$one]],$id,4);
    currencyCheck(count(loadCurrencyRecord($db,$id)['rates'])===32,'Una página del notebook reemplaza todo el historial.');
    $snapshot=['primary_code'=>'USD','alternate_code'=>'VES','exchange_rate'=>'150.00000000'];
    currencyCheck(accountingConvert('10.00','USD','VES',$snapshot)==='1500.00','El catálogo nuevo reinterpreta la tasa guardada de un documento.');
    echo "PASS: catálogo de monedas, migración repetible, tasas fechadas/futuras/inversas, versiones, validaciones, principal/alterna, archivo, historial paginado y snapshots.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('DROP DATABASE `'.$testDatabase.'`');
}

// HTTP forms use their normal routes; only a disposable, unselected currency is created.
$live=createDatabaseConnection(); $fixture=null; $cookie=tempnam(sys_get_temp_dir(),'currency-http-');
$prefix='Test moneda '.bin2hex(random_bytes(8));
function currencyHttp(string $path,string $cookie,?array $post=null): array
{
    $c=curl_init('http://localhost/project/modules/contabilidad/'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>10]);
    if ($post!==null) curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));
    $html=curl_exec($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); $redirect=curl_getinfo($c,CURLINFO_REDIRECT_URL);curl_close($c);
    currencyCheck($html!==false&&!preg_match('/Fatal error|Warning:|Notice:/',$html),'Error PHP en formulario de monedas.');
    return [$status,$html,$redirect];
}
try {
    do { $code='Z'.chr(random_int(65,90)).chr(random_int(65,90));$q=$live->prepare('SELECT COUNT(*) FROM accounting_currencies WHERE code=?');$q->execute([$code]); } while ($q->fetchColumn());
    [$status,$html]=currencyHttp('configuracion.php?section=currencies&new=1',$cookie);
    currencyCheck($status===200&&preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m)===1,'Falta formulario/CSRF.');$token=$m[1];
    $base=(int)$live->query('SELECT c.id FROM accounting_currencies c JOIN accounting_currency_settings s ON s.primary_code=c.code WHERE s.id=1')->fetchColumn();
    $post=['csrf'=>$token,'action'=>'save_currency','code'=>$code,'name'=>$prefix,'active'=>'1','symbol'=>'T$','unit_label'=>'Unidad','subunit_label'=>'Centavo','symbol_position'=>'before','rates'=>[['base_currency_id'=>$base,'rate_date'=>commerceToday(),'rate'=>'2.5']]];
    [$status,$html,$redirect]=currencyHttp('configuracion.php?section=currencies&new=1',$cookie,$post);
    currencyCheck($status===303&&preg_match('/id=(\d+)/',$redirect,$m)===1,'No se guardó la ficha de moneda.');$fixture=(int)$m[1];
    [$status,$html]=currencyHttp('configuracion.php?section=currencies&id='.$fixture,$cookie);
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
    currencyCheck($xpath->query('//form//form')->length===0&&$xpath->query('//*[@id="currency-rates"]//input[@data-rate-inverse and @value="0.40000000"]')->length===1,'El notebook de tasas no conserva la relación o la inversa.');
    foreach(['code','name','active','symbol','unit_label','subunit_label','symbol_position'] as $field)currencyCheck($xpath->query('//form//*[@name="'.$field.'"]')->length===1,'Falta un campo de la moneda.');
    $post['version']='1';unset($post['rates']);$post['symbol']='TT';
    currencyCheck(currencyHttp('configuracion.php?section=currencies&id='.$fixture,$cookie,$post)[0]===303,'No se actualizó la ficha.');
    currencyCheck(loadCurrencyRecord($live,$fixture)['symbol']==='TT'&&count(loadCurrencyRecord($live,$fixture)['rates'])===1,'Editar los campos perdió la tasa.');
    currencyCheck(currencyHttp('configuracion.php?section=currencies&id='.$fixture,$cookie,$post)[0]===200,'Se aceptó una versión anterior.');
    $post['version']='2';$post['rates']=[['base_currency_id'=>$base,'rate_date'=>commerceToday(),'rate'=>'3']];
    currencyCheck(currencyHttp('configuracion.php?section=currencies&id='.$fixture,$cookie,$post)[0]===200&&loadCurrencyRecord($live,$fixture)['version']===2,'Una tasa duplicada alteró la ficha.');
    unset($post['csrf']);currencyCheck(currencyHttp('configuracion.php?section=currencies&id='.$fixture,$cookie,$post)[0]===403,'Se guardó sin CSRF.');
    echo "PASS: tree/form por HTTP, campos de moneda, notebook Tasas, guardado, conservación del historial, versiones, duplicados y CSRF.\n";
} finally {
    if ($fixture) {
        $q=$live->prepare('SELECT id FROM accounting_currencies WHERE id=? AND name=? AND code=?');$q->execute([$fixture,$prefix,$code]);
        if ($q->fetchColumn()) { $live->prepare('DELETE FROM accounting_currency_rates WHERE currency_id=?')->execute([$fixture]);$live->prepare('DELETE FROM accounting_currencies WHERE id=?')->execute([$fixture]); }
    }
    if (is_file($cookie)) unlink($cookie);
}
