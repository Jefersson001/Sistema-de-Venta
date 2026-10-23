<?php
declare(strict_types=1);
require_once __DIR__.'/http-session.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/cash-flow-repository.php';
require_once dirname(__DIR__).'/php/payments-repository.php';require_once __DIR__.'/treasury-cleanup.php';

function flowCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function flowRequest(string $path, ?array $post = null): array
{
    $curl=testHttpCurl('http://localhost/project/modules/'.$path, $cookie ?? $cookieJar ?? $cookieFile ?? null);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    if ($post !== null) curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));
    $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    flowCheck(is_string($body) && !preg_match('/Fatal error|Warning:|Notice:/',$body),'Error HTTP en '.$path);
    return [$status,$body];
}
function flowAmounts(array $summary): array { return array_column($summary['currencies'],null,'code'); }

$db=createDatabaseConnection(); $prefix='Test flujo '.bin2hex(random_bytes(8)); $contacts=[]; $payments=[]; $fixtures=[]; $accounts=[]; $methods=[];
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
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES (?,'cash',NULL)")->execute([$prefix.'base']);
    $account=$accounts['base']=(int)$db->lastInsertId();
    $method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    foreach(['transfer','unused'] as $key){$db->prepare('INSERT INTO accounting_payment_methods(name) VALUES(?)')->execute([$prefix.$key]);$methods[$key]=(int)$db->lastInsertId();}
    foreach (['bank'=>['bank',null],'cash'=>['cash',$alternate],'empty'=>['bank',$primary]] as $key=>[$type,$currency]) {
        $db->prepare('INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES (?,?,?)')->execute([$prefix.$key,$type,$currency]); $accounts[$key]=(int)$db->lastInsertId();
    }
    $today=commerceToday(); $date=new DateTimeImmutable($today); $oldCode='XHF';
    $ranges=[cashFlowRange([]),cashFlowRange(['period'=>'last7']),cashFlowRange(['period'=>'month']),cashFlowRange(['period'=>'custom','date_from'=>$date->modify('-7 days')->format('Y-m-d'),'date_to'=>$date->modify('+1 day')->format('Y-m-d')])];
    $baseline=[]; foreach ($ranges as $range) $baseline[$range['period']]=flowAmounts(loadCashFlowSummary($db,$range));
    $add=function(string $partner,string $direction,string $amount,string $currency,string $paymentDate,string $state='settled',?int $accountId=null,?int $methodId=null) use ($db,$prefix,$contacts,$account,$method,$settings,&$payments,&$fixtures):int {
        $db->prepare('INSERT INTO accounting_payments(name,partner_type,direction,contact_id,amount,currency_code,primary_code,alternate_code,exchange_rate,payment_date,reference,account_id,method_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$prefix.count($payments),$partner,$direction,$contacts[$partner==='customer'?'client':'supplier'],$amount,$currency,$settings['primary_code'],$settings['alternate_code'],$settings['exchange_rate'],$paymentDate,$prefix,$accountId??$account,$methodId??$method,$state]);
        $id=(int)$db->lastInsertId(); $payments[]=$id; $fixtures[]=['id'=>$id,'direction'=>$direction,'amount'=>$amount,'currency'=>$currency,'date'=>$paymentDate,'status'=>$state,'account_id'=>$accountId??$account];
        if($state==='settled')treasuryPostPayment($db,loadAccountingPayment($db,$id));return $id;
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
    for ($n=0;$n<22;$n++) $add('customer','inbound','0.01',$primary,$today,'settled',$accounts['bank']);
    $add('customer','inbound','25.12',$primary,$today,'settled',$accounts['bank'],$methods['transfer']);
    $add('supplier','outbound','2.05',$primary,$today,'settled',$accounts['bank']);
    $add('supplier','outbound','15.00',$alternate,$today,'settled',$accounts['cash']);
    $add('customer','inbound','123.00',$primary,$today,'pending',$accounts['bank']);
    $add('customer','inbound','456.00',$primary,$date->modify('-20 days')->format('Y-m-d'),'settled',$accounts['bank']);

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
    $accountCards=array_column(loadCashFlowAccounts($db,$ranges[0]),null,'id');
    $bankAmounts=flowAmounts(['currencies'=>$accountCards[$accounts['bank']]['currencies']]);
    $bankMethods=array_column($bankAmounts[$primary]['methods'],null,'method_id');
    flowCheck(count($bankMethods)===2&&$bankMethods[$methods['transfer']]['income']==='25.12'&&$bankMethods[$methods['transfer']]['expense']==='0.00'&&$bankMethods[$method]['income']==='0.22'&&$bankMethods[$method]['expense']==='2.05','El desglose mezcla métodos, cuentas, fechas o pagos sin confirmar.');
    flowCheck(!isset($bankMethods[$methods['unused']])&&$accountCards[$accounts['empty']]['currencies'][0]['methods']===[],'Se muestran medios de pago sin uso.');
    foreach($accountCards as $card)foreach($card['currencies'] as $currency){
        foreach(['income','expense'] as $key){$sum='0.00';foreach($currency['methods'] as $detail)$sum=bcadd($sum,$detail[$key],2);flowCheck($sum===$currency[$key],'El desglose por medio de pago no coincide con el total de la cuenta.');}
    }
    [, $snapshotBody]=flowRequest('dashboard/resumen.php?'.cashFlowQuery($ranges[0]));$snapshot=json_decode($snapshotBody,true);
    $snapshotAccounts=array_column($snapshot['accounts'],null,'id');flowCheck($snapshotAccounts[$accounts['bank']]['currencies']===$accountCards[$accounts['bank']]['currencies'],'El dashboard no actualiza el desglose por métodos.');
    [, $dashboardBody]=flowRequest('dashboard/index.php?'.cashFlowQuery($ranges[0]));
    flowCheck(str_contains($dashboardBody,'data-cash-flow-account="'.$accounts['bank'].'"')&&str_contains($dashboardBody,'data-cash-flow-method="'.$methods['transfer'].'"')&&!str_contains($dashboardBody,'data-cash-flow-method="'.$methods['unused'].'"'),'El dashboard no presenta los métodos usados.');
    flowCheck($bankAmounts[$primary]['income']==='25.34' && $bankAmounts[$primary]['expense']==='2.05' && $bankAmounts[$primary]['net']==='23.29' && $accountCards[$accounts['bank']]['count']===24,'El resumen del banco incluye pagos de otra cuenta, fecha o estado.');
    $cashAmounts=flowAmounts(['currencies'=>$accountCards[$accounts['cash']]['currencies']]);
    flowCheck(count($cashAmounts)===1 && $cashAmounts[$alternate]['expense']==='15.00' && $cashAmounts[$alternate]['net']==='-15.00','La caja mezcla monedas o no calcula egresos.');
    flowCheck($accountCards[$accounts['empty']]['count']===0 && $accountCards[$accounts['empty']]['currencies'][0]['income']==='0.00','No se muestran bancos sin pagos.');
    $accountTotals=flowAmounts(loadCashFlowSummary($db,$ranges[0],$accounts['bank'])); flowCheck($accountTotals===$bankAmounts,'La tarjeta y el detalle del banco difieren.');
    $accountHistory=loadCashFlowHistory($db,$ranges[0],1,$accounts['bank']);flowCheck($accountHistory['total']===24 && $accountHistory['pages']===2,'Paginación del banco incorrecta.');
    foreach($accountHistory['rows'] as $row)flowCheck((int)$row['account_id']===$accounts['bank'],'Se filtraron pagos de otra cuenta en el historial del banco.');
    $custom=$ranges[3];
    [, $body]=flowRequest('contabilidad/ingresos-egresos.php?'.cashFlowQuery($custom));
    $dom=new DOMDocument();$dom->loadHTML('<?xml encoding="UTF-8">'.$body);$xpath=new DOMXPath($dom);
    flowCheck($xpath->query('//*[@id="cash-flow-history-title"]')->length===0 && $xpath->query('//*[@data-cash-flow-account]')->length===count($accountCards),'La vista general no muestra tarjetas en lugar del listado.');
    $bankLink=$xpath->query('//*[@data-cash-flow-account="'.$accounts['bank'].'"]')->item(0);
    flowCheck($xpath->query('.//*[@data-cash-flow-method="'.$methods['transfer'].'"]',$bankLink)->length===1&&$xpath->query('//*[@data-cash-flow-method="'.$methods['unused'].'"]')->length===0,'El informe no muestra los medios de pago utilizados por cuenta.');
    parse_str(parse_url($bankLink->getAttribute('href'),PHP_URL_QUERY),$linkQuery);
    flowCheck((int)$linkQuery['account_id']===$accounts['bank'] && $linkQuery['date_from']===$custom['date_from'] && $linkQuery['date_to']===$custom['date_to'],'La tarjeta perdió la cuenta o las fechas.');
    [, $body]=flowRequest('contabilidad/ingresos-egresos.php?'.cashFlowQuery($ranges[0],$accounts['bank']));
    $dom->loadHTML('<?xml encoding="UTF-8">'.$body);$xpath=new DOMXPath($dom);
    flowCheck($xpath->query('//*[@data-cash-flow-method="'.$methods['transfer'].'"]')->length===1,'La ficha de la cuenta no muestra el desglose por método.');
    flowCheck($xpath->query('//input[@name="account_id" and @value="'.$accounts['bank'].'"]')->length===1 && $xpath->query('//*[@id="cash-flow-history-title"]')->length===1,'El detalle no conserva la cuenta al cambiar el período.');
    $actualIds=[];foreach($xpath->query('//table//a') as $link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);$actualIds[]=(int)$query['id'];}
    flowCheck($actualIds===array_map('intval',array_column($accountHistory['rows'],'payment_id')),'La vista del banco muestra pagos de otra cuenta.');
    $pageLink=$xpath->query('//nav[@aria-label="Páginas del historial"]//a')->item(0);parse_str(parse_url($pageLink->getAttribute('href'),PHP_URL_QUERY),$pageQuery);
    flowCheck((int)$pageQuery['account_id']===$accounts['bank'] && $pageQuery['period']==='today' && $pageQuery['page']==='2','La paginación perdió el banco o período.');
    [, $body]=flowRequest('contabilidad/ingresos-egresos.php?'.cashFlowQuery($ranges[0],$accounts['empty']));flowCheck(str_contains($body,'No hay movimientos confirmados en este período.'),'Un banco vacío no muestra su historial vacío.');
    foreach(['0','bad','999999999','%5B%5D=1'] as $bad){
        $query=$bad==='%5B%5D=1'?'account_id%5B%5D=1':'account_id='.$bad;
        [$status,$body]=flowRequest('contabilidad/ingresos-egresos.php?'.$query);flowCheck($status===404 && !str_contains($body,'data-cash-flow-account='),'Una cuenta inválida muestra silenciosamente todas las cuentas.');
    }
    [, $body]=flowRequest('contabilidad/ingresos-egresos.php?account_id='.$accounts['bank'].'&period=custom&date_from=bad&date_to='.$today);
    flowCheck(str_contains($body,'name="account_id" value="'.$accounts['bank'].'"'),'Un error de fecha descarta la cuenta seleccionada.');
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
    treasuryPostPayment($db,loadAccountingPayment($db,$pending));
    [, $body]=flowRequest('dashboard/ingresos-egresos.php'); $after=flowAmounts(json_decode($body,true));
    flowCheck(bcsub($after[$primary]['income'],$before[$primary]['income'],2)==='999.00','El resumen no refleja un pago recién confirmado.');
    reverseAccountingPayment($db,$pending,1,['reason'=>$prefix,'movement_date'=>$today]);
    [, $body]=flowRequest('dashboard/ingresos-egresos.php');$reversed=flowAmounts(json_decode($body,true));
    flowCheck(bcsub($reversed[$primary]['expense'],$before[$primary]['expense'],2)==='999.00'&&$reversed[$primary]['balance_end']===$before[$primary]['balance_end'],'La reversión no conserva el original y restaura el saldo.');
    echo "PASS: períodos, monedas, precisión, estados, tarjetas por caja/banco, aislamiento de cuentas, historial, paginación, fechas y actualización de ingresos y egresos.\n";
} finally {
    cleanupFixtureTreasury($db,array_values($contacts),$prefix);
    foreach ($payments as $id) $db->prepare('DELETE FROM accounting_payments WHERE id=? AND reference=?')->execute([$id,$prefix]);
    foreach ($contacts as $role=>$id) $db->prepare('DELETE FROM contacts WHERE id=? AND name=?')->execute([$id,$prefix.$role]);
    foreach ($accounts as $key=>$id) $db->prepare('DELETE FROM accounting_payment_accounts WHERE id=? AND name=?')->execute([$id,$prefix.$key]);
    foreach ($methods as $key=>$id) $db->prepare('DELETE FROM accounting_payment_methods WHERE id=? AND name=?')->execute([$id,$prefix.$key]);
}
