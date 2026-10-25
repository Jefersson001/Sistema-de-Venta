<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/php/sales-repository.php';
require_once dirname(__DIR__).'/php/inventory-stock-repository.php';
require_once dirname(__DIR__).'/php/tax-repository.php';
require_once dirname(__DIR__).'/php/bcv-provider.php';
require_once dirname(__DIR__).'/php/two-decimals-schema.php';
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/transfer-fields.php';
function decimalExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['1.004'=>'1.00','1.005'=>'1.01','-1.005'=>'-1.01','-0.004'=>'0.00','9999999999999999.99'=>'9999999999999999.99'] as $input=>$expected) {
    decimalExpect(decimalRound($input)===$expected,'Redondeo incorrecto: '.$input);
}
decimalExpect(decimalDisplay('9999999999999999.99')==='9.999.999.999.999.999,99','La impresión perdió precisión.');
decimalExpect(decimalInput('1.000')==='1.00' && decimalInput('1,5')==='1.50' && decimalInput('1.125')==='1.125','El campo cantidad no conserva un formato válido de dos decimales.');
$page=['catalogs'=>['products'=>[],'taxes'=>[]]]; $line=['quantity'=>'1.000']; $lineKey=0; $locked=true;
foreach (['sales','purchases'] as $view) {
    ob_start(); require dirname(__DIR__).'/html/'.$view.'/line.php'; $markup=ob_get_clean();
    $dom=new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($markup); $xpath=new DOMXPath($dom);
    decimalExpect($xpath->query('//input[contains(@name,"[quantity]") and @value="1.00"]')->length===1,'La línea '.$view.' todavía muestra 1.000.');
}
decimalExpect(inventoryQuantity('1,25')==='1.25' && inventoryQuantity('1.255')===null,'La cantidad no respeta dos decimales.');
decimalExpect(salesDecimal('999999999.99',9,2)==='999999999.99' && salesDecimal('1.001',9,2)===null,'Validación decimal incorrecta.');
decimalExpect(validateBusinessTax(['name'=>'IVA','rate'=>'16,25'])[0]['rate']==='16.25','El impuesto no admite dos decimales.');
decimalExpect((bool)validateBusinessTax(['name'=>'IVA','rate'=>'16.255'])[1],'Se admitió un tercer decimal en impuestos.');
try { accountingExchangeRate('40.123'); throw new LogicException('Se admitió un tercer decimal en tasas.'); } catch (InvalidArgumentException) {}
decimalExpect(accountingExchangeRate('40,12')==='40.12','Validación de tasa incorrecta.');
decimalExpect(commerceAmounts('3.12','19.99','16.25')===['base_amount'=>'62.37','tax_amount'=>'10.14','total_amount'=>'72.51'],'Redondeo comercial incorrecto.');
$pair=['primary_code'=>'USD','alternate_code'=>'VES','exchange_rate'=>'40.12'];
decimalExpect(accountingConvert('1.25','USD','VES',$pair)==='50.15' && accountingConvert('50.15','VES','USD',$pair)==='1.25','Conversión incorrecta.');
$publication=bcvParsePublication('<div id="dolar"><strong>1.234,56789000</strong></div><span class="date-display-single" content="2026-10-26T00:00:00-04:00"></span>');
decimalExpect($publication['rate']==='1234.57','La tasa BCV no se redondeó a dos decimales.');

$db=createDatabaseConnection(true); $name='nexo_decimal_test_'.bin2hex(random_bytes(8));
$field=['type'=>'decimal','label'=>'Cantidad'];
decimalExpect(transferNormalize($db,'quantity','1,25',$field)==='1.25','La importación no respeta dos decimales.');
try { transferNormalize($db,'quantity','1.255',$field); throw new LogicException('La importación aceptó un tercer decimal.'); } catch (InvalidArgumentException) {}
try {
    $db->exec('CREATE DATABASE `'.$name.'`'); $db->exec('USE `'.$name.'`');
    $db->exec('CREATE TABLE fixture(id INT PRIMARY KEY, quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000, rate DECIMAL(20,8) NULL, amount DECIMAL(18,2) NOT NULL)');
    $db->exec('INSERT INTO fixture VALUES(1,1.125,40.12678900,123.45),(2,-1.125,NULL,0)');
    $changes=installTwoDecimals($db);
    decimalExpect(count($changes)===2 && installTwoDecimals($db)===[],'La migración no es repetible.');
    $row=$db->query('SELECT * FROM fixture WHERE id=1')->fetch();
    decimalExpect($row['quantity']==='1.13' && $row['rate']==='40.13' && $row['amount']==='123.45','La migración alteró importes o redondeó incorrectamente.');
    decimalExpect($db->query('SELECT quantity FROM fixture WHERE id=2')->fetchColumn()==='-1.13','Redondeo negativo incorrecto en MariaDB.');
    $db->exec('INSERT INTO fixture(id,amount) VALUES(3,0)');
    $row=$db->query('SELECT * FROM fixture WHERE id=3')->fetch();
    decimalExpect($row['quantity']==='0.00' && $row['rate']===null,'Se perdieron los valores predeterminados o NULL.');
    decimalExpect($db->query("SHOW COLUMNS FROM fixture WHERE Field='quantity'")->fetch()['Type']==='decimal(11,2)','Se modificó la capacidad entera.');
    $db->exec('CREATE TABLE unsafe(rate DECIMAL(20,8) NOT NULL)'); $db->exec('INSERT INTO unsafe VALUES(0.001)');
    try { installTwoDecimals($db); throw new LogicException('Se permitió convertir una tasa positiva a cero.'); } catch (RuntimeException $error) { if ($error instanceof LogicException) throw $error; }
    echo "PASS: cantidades, impuestos, tasas, BCV, conversiones, redondeo exacto, impresión y migración repetible.\n";
} finally { $db->exec('USE information_schema'); $db->exec('DROP DATABASE `'.$name.'`'); }
