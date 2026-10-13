<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/cash-flow-repository.php';

function flowCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function flowRequest(string $path, ?array $post = null): array
{
    $curl=curl_init('http://localhost/project/modules/'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    if ($post !== null) curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));
    $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    flowCheck(is_string($body) && !preg_match('/Fatal error|Warning:|Notice:/',$body),'Error HTTP en '.$path);
    return [$status,$body];
}
function flowAmounts(array $summary): array { return array_column($summary['currencies'],null,'code'); }

$db=createDatabaseConnection(); $prefix='Test flujo '.bin2hex(random_bytes(8)); $contacts=[]; $payments=[]; $fixtures=[];
try {
    $leap=cashFlowRange(['period'=>'last7'],'2024-03-01');
    flowCheck($leap['date_from']==='2024-02-24' && $leap['date_to']==='2024-03-01','Últimos 7 días no incluye hoy o falla en año bisiesto.');
    flowCheck(cashFlowRange(['period'=>'last7'],'2026-01-01')['date_from']==='2025-12-26','Falla el cambio de año.');
    flowCheck(cashFlowRange(['period'=>'month'],'2024-03-15')['date_from']==='2024-03-01','Este mes no comienza el día 1.');
    foreach ([['period'=>[]],['period'=>'wrong'],['period'=>'custom'],['period'=>'custom','date_from'=>'2026-02-30','date_to'=>'2026-03-01'],['period'=>'custom','date_from'=>[],'date_to'=>'2026-03-01'],['period'=>'custom','date_from'=>'2026-03-02','date_to'=>'2026-03-01']] as $input) {
        try { cashFlowRange($input); throw new RuntimeException('Se aceptó un rango inválido.'); } catch (InvalidArgumentException) {}
    }
    $settings=accountingCurrencies($db); $primary=$settings['primary_code']; $alternate=$settings['alternate_code'];
    $roles=array_flip($db->query('SELECT id,system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach (['client','supplier'] as $role) {
        $db->prepare('INSERT INTO contacts(name,type_id) VALUES (?,?)')->execute([$prefix.$role,$roles[$role]]); $contacts[$role]=(int)$db->lastInsertId();
    }
    $account=(int)$db->query("SELECT id FROM accounting_payment_accounts WHERE system_key='default_cash'")->fetchColumn();
    $method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $today=commerceToday(); $date=new DateTimeImmutable($today); $oldCode='XHF';
    $ranges=[cashFlowRange([]),cashFlowRange(['period'=>'last7']),cashFlowRange(['period'=>'month']),cashFlowRange(['period'=>'custom','date_from'=>$date->modify('-7 days')->format('Y-m-d'),'date_to'=>$date->modify('+1 day')->format('Y-m-d')])];
    $baseline=[]; foreach ($ranges as $range) $baseline[$range['period']]=flowAmounts(loadCashFlowSummary($db,$range));
    $add=function(string $partner,string $direction,string $amount,string $currency,string $paymentDate,string $state='settled') use ($db,$prefix,$contacts,$account,$method,$settings,&$payments,&$fixtures):int {
        $db->prepare('INSERT INTO accounting_payments(name,partner_type,direction,contact_id,amount,currency_code,primary_code,alternate_code,exchange_rate,payment_date,reference,account_id,method_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$prefix.count($payments),$partner,$direction,$contacts[$partner==='customer'?'client':'supplier'],$amount,$currency,$settings['primary_code'],$settings['alternate_code'],$settings['exchange_rate'],$paymentDate,$prefix,$account,$method,$state]);
        $id=(int)$db->lastInsertId(); $payments[]=$id; $fixtures[]=['id'=>$id,'direction'=>$direction,'amount'=>$amount,'currency'=>$currency,'date'=>$paymentDate,'status'=>$state]; return $id;
    };
    $add('customer','inbound','100.00',$primary,$today);
    $add('supplier','outbound','40.00',$primary,$today);
    $add('supplier','inbound','7.00',$primary,$today); // Supplier refund is an income.
    $add('customer','outbound','12.00',$primary,$today); // Customer refund is an expense.
    $add('customer','inbound','50.00',$alternate,$today);
    $add('supplier','outbound','25.00',$alternate,$today);
    $pending=$add('customer','inbound','999.00',$primary,$today,'pending');
    $add('supplier','outbound','888.00',$primary,$today,'draft');
    $add('customer','inbound','777.00',$primary,$today,'cancel');
    $add('customer','inbound','30.00',$primary,$date->modify('-6 days')->format('Y-m-d'));
    $add('supplier','outbound','9.00',$primary,$date->modify('-7 days')->format('Y-m-d'));
    $add('customer','inbound','10.00',$primary,$date->format('Y-m-01'));
    $add('customer','inbound','555.00',$primary,$date->modify('+1 day')->format('Y-m-d'));
    $add('customer','inbound','90071992547409.93',$oldCode,$today);
    $add('customer','inbound','0.07',$oldCode,$today);
    for ($n=0;$n<22;$n++) $add('customer','inbound','0.01',$primary,$today);

    foreach ($ranges as $range) {
        $expected=$baseline[$range['period']];
        foreach ($fixtures as $fixture) {
            if ($fixture['status']!=='settled' || $fixture['date']<$range['date_from'] || $fixture['date']>$range['date_to']) continue;
            $code=$fixture['currency']; $key=$fixture['direction']==='inbound'?'income':'expense';
            $expected[$code]??=['income'=>'0.00','expense'=>'0.00','income_count'=>0,'expense_count'=>0];
            $expected[$code][$key]=bcadd($expected[$code][$key],$fixture['amount'],2); $expected[$code][$key.'_count']++;
        }
        $actual=flowAmounts(loadCashFlowSummary($db,$range));
        foreach ($expected as $code=>$row) {
            flowCheck($actual[$code]['income']===$row['income'] && $actual[$code]['expense']===$row['expense'],'Importes incorrectos en '.$range['period'].' '.$code);
            flowCheck($actual[$code]['net']===bcsub($row['income'],$row['expense'],2),'Diferencia incorrecta.');
            flowCheck($actual[$code]['income_count']===$row['income_count'] && $actual[$code]['expense_count']===$row['expense_count'],'Conteo incorrecto.');
        }
        [$status,$body]=flowRequest('dashboard/ingresos-egresos.php?'.cashFlowQuery($range));
        flowCheck($status===200 && flowAmounts(json_decode($body,true,512,JSON_THROW_ON_ERROR))===$actual,'El endpoint no coincide con el resumen.');
        foreach (['dashboard/index.php','contabilidad/ingresos-egresos.php'] as $path) {
            [$status,$body]=flowRequest($path.'?'.cashFlowQuery($range)); flowCheck($status===200,'No abre '.$path);
            $dom=new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">'.$body); $xpath=new DOMXPath($dom);
            flowCheck($xpath->query('//select[@name="period"]/option[@selected and @value="'.$range['period'].'"]')->length===1,'La vista perdió el período elegido.');
            foreach (['income','expense','net'] as $key) {
                $text=$xpath->query('//*[@data-cash-flow-amount="'.$key.'"]')->item(0)?->textContent ?? '';
                foreach ($actual as $code=>$row) flowCheck(str_contains($text,$row[$key]) && str_contains($text,$code),'La tarjeta mezcla monedas o muestra otro importe.');
            }
        }
    }
    $history=loadCashFlowHistory($db,$ranges[0],1); flowCheck(count($history['rows'])===20 && $history['pages']>=2,'El historial no pagina.');
    foreach ($history['rows'] as $row) flowCheck($row['status']==='settled' && $row['payment_date']===$today,'El historial incluye un pago fuera del período o sin confirmar.');
    $second=loadCashFlowHistory($db,$ranges[0],2);
    flowCheck(!array_intersect(array_column($history['rows'],'id'),array_column($second['rows'],'id')),'La paginación repite pagos.');
    flowCheck(loadCashFlowHistory($db,$ranges[0],999999)['currentPage']===$history['pages'],'La página supera el total.');
    flowCheck(loadCashFlowHistory($db,$ranges[0],[])['currentPage']===1,'Página malformada no controlada.');
    $empty=cashFlowRange(['period'=>'custom','date_from'=>'1000-01-01','date_to'=>'1000-01-01']);
    foreach (loadCashFlowSummary($db,$empty)['currencies'] as $row) flowCheck($row['income']==='0.00' && $row['expense']==='0.00','El período vacío no muestra ceros.');
    foreach (['period=custom&date_from=2026-10-14&date_to=2026-10-13','period=custom&date_from=bad&date_to=2026-10-13','period%5B%5D=today'] as $query) {
        [$status,$body]=flowRequest('dashboard/ingresos-egresos.php?'.$query); flowCheck($status===422 && isset(json_decode($body,true)['error']),'El endpoint aceptó fechas inválidas.');
        [$status,$body]=flowRequest('contabilidad/ingresos-egresos.php?'.$query); flowCheck($status===200 && !str_contains($body,'<strong>0.00</strong>'),'El error muestra ceros engañosos.');
    }
    [$status]=flowRequest('dashboard/ingresos-egresos.php',[]); flowCheck($status===405,'El resumen acepta escrituras.');
    // A newly confirmed payment is reflected on the next live read, including cancellations.
    [, $body]=flowRequest('dashboard/ingresos-egresos.php'); $before=flowAmounts(json_decode($body,true));
    $db->prepare("UPDATE accounting_payments SET status='settled' WHERE id=? AND reference=?")->execute([$pending,$prefix]);
    [, $body]=flowRequest('dashboard/ingresos-egresos.php'); $after=flowAmounts(json_decode($body,true));
    flowCheck(bcsub($after[$primary]['income'],$before[$primary]['income'],2)==='999.00','El resumen no refleja un pago recién confirmado.');
    $db->prepare("UPDATE accounting_payments SET status='cancel' WHERE id=? AND reference=?")->execute([$pending,$prefix]);
    [, $body]=flowRequest('dashboard/ingresos-egresos.php'); flowCheck(flowAmounts(json_decode($body,true))===$before,'El resumen conserva un pago cancelado.');
    echo "PASS: períodos, límites de fechas, monedas, precisión decimal, anticipos, reembolsos, estados, paginación, vistas y actualización de ingresos y egresos.\n";
} finally {
    foreach ($payments as $id) $db->prepare('DELETE FROM accounting_payments WHERE id=? AND reference=?')->execute([$id,$prefix]);
    foreach ($contacts as $role=>$id) $db->prepare('DELETE FROM contacts WHERE id=? AND name=?')->execute([$id,$prefix.$role]);
}
