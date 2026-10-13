<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/connection.php';require_once dirname(__DIR__).'/php/payments-repository.php';require_once dirname(__DIR__).'/php/cash-flow-repository.php';require_once dirname(__DIR__).'/php/treasury-schema.php';require_once __DIR__.'/treasury-cleanup.php';
function trCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function trRequest(string $path,string $cookie,?array $post=null):array{
    $c=curl_init('http://localhost/project/modules/contabilidad/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);if($post!==null)curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$redirect=curl_getinfo($c,CURLINFO_REDIRECT_URL);curl_close($c);trCheck(is_string($body)&&!preg_match('/Fatal error|Warning:|Notice:/',$body),'Error PHP en '.$path);return[$status,$body,$redirect];
}
function trToken(string $html):string{trCheck(preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m)===1,'Falta CSRF.');return$m[1];}
function trId(array $r):int{trCheck($r[0]===303&&preg_match('/id=(\d+)/',$r[2],$m)===1,'Falló la operación: '.mb_substr(strip_tags($r[1]),-900));return(int)$m[1];}
function trReject(callable $action,string $text=''):void{try{$action();throw new RuntimeException('Se aceptó una operación inválida.');}catch(InvalidArgumentException $e){if($text!=='')trCheck(str_contains($e->getMessage(),$text),'Rechazo incorrecto: '.$e->getMessage());}}
$db=createDatabaseConnection();$prefix='Test tesoreria '.bin2hex(random_bytes(8));$cookie=tempnam(sys_get_temp_dir(),'treasury-');$accounts=[];$contacts=[];$concepts=[];$documents=[];
try{
    $settings=accountingCurrencies($db);$currency=$settings['primary_code'];$alt=$settings['alternate_code'];$today=commerceToday();$past=(new DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $roles=array_flip($db->query('SELECT id,system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    $db->prepare('INSERT INTO contacts(name,type_id) VALUES (?,?)')->execute([$prefix.' client',$roles['client']]);$contacts[]=(int)$db->lastInsertId();
    foreach(['a'=>'cash','b'=>'bank'] as $key=>$type){$db->prepare('INSERT INTO accounting_payment_accounts(name,account_type) VALUES (?,?)')->execute([$prefix.$key,$type]);$accounts[$key]=(int)$db->lastInsertId();}
    $method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    foreach(['income'=>'inbound','expense'=>'outbound'] as $kind=>$direction){
        [, $html]=trRequest('configuracion.php?section=concepts&new=1',$cookie);$token=trToken($html);
        $response=trRequest('configuracion.php?section=concepts&new=1',$cookie,['csrf'=>$token,'action'=>'save_configuration','name'=>$prefix.$kind,'direction'=>$direction,'active'=>'1']);trCheck($response[0]===303,'No se creó el concepto desde su formulario.');
        $q=$db->prepare('SELECT id FROM accounting_cash_concepts WHERE name=?');$q->execute([$prefix.$kind]);$concepts[$kind]=(int)$q->fetchColumn();
    }
    $create=function(string $kind,string $amount,string $date,?string $code=null,array $extra=[])use($db,$cookie,$accounts,$concepts,$method,$currency,$alt,&$documents):int{
        [, $html]=trRequest('tesoreria.php?new=1&kind='.$kind,$cookie);$token=trToken($html);trCheck(preg_match('/name="creation_key" value="([a-f0-9]+)"/',$html,$m)===1,'Falta la clave de creación.');
        $post=['csrf'=>$token,'creation_key'=>$m[1],'action'=>'save_treasury','kind'=>$kind,'account_id'=>$accounts['a'],'currency_code'=>$code??$currency,'amount'=>$amount,'movement_date'=>$date,'method_id'=>$method,'concept_id'=>$concepts[$kind]??null,'exchange_rate'=>'100'];
        $post=array_replace($post,$extra);$id=trId(trRequest('tesoreria.php?new=1',$cookie,$post));$documents[]=$id;trCheck(trId(trRequest('tesoreria.php?new=1',$cookie,$post))===$id,'Repetir Guardar duplicó el movimiento.');return$id;
    };
    $confirm=function(int $id)use($db,$cookie):void{[, $html]=trRequest('tesoreria.php?id='.$id,$cookie);$d=loadTreasuryDocument($db,$id);trId(trRequest('tesoreria.php?id='.$id,$cookie,['csrf'=>trToken($html),'version'=>$d['version'],'action'=>'confirm_treasury']));};
    $reverse=function(int $id)use($db,$cookie,$today,$prefix,&$documents):int{[, $html]=trRequest('tesoreria.php?id='.$id,$cookie);$d=loadTreasuryDocument($db,$id);$new=trId(trRequest('tesoreria.php?id='.$id,$cookie,['csrf'=>trToken($html),'version'=>$d['version'],'action'=>'reverse_treasury','movement_date'=>$today,'reason'=>$prefix]));$documents[]=$new;return$new;};
    $opening=$create('opening','100.00',$past);$confirm($opening);trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='100.00','Saldo inicial incorrecto.');
    trReject(fn()=>confirmTreasuryDocument($db,$opening,1),'confirmado');
    trReject(fn()=>saveTreasuryDocument($db,[], $opening,2,''),'confirmado');
    $duplicate=$create('opening','10.00',$past);trReject(fn()=>confirmTreasuryDocument($db,$duplicate,1),'saldo inicial');
    $negative=$create('opening','-10.00',$past,$alt);$confirm($negative);
    $payment=saveAccountingPayment($db,['partner_type'=>'customer','direction'=>'inbound','contact_id'=>$contacts[0],'amount'=>'50.00','currency_code'=>$currency,'payment_date'=>$yesterday,'account_id'=>$accounts['a'],'method_id'=>$method],null,null,bin2hex(random_bytes(32)));
    transitionAccountingPayment($db,$payment,1,'post_payment');trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='100.00','Un pago pendiente alteró el saldo.');trReject(fn()=>treasuryPostPayment($db,loadAccountingPayment($db,$payment)),'confirmados');
    transitionAccountingPayment($db,$payment,2,'settle_payment');treasuryPostPayment($db,loadAccountingPayment($db,$payment));
    $q=$db->prepare('SELECT COUNT(*) FROM accounting_treasury_entries WHERE payment_id=?');$q->execute([$payment]);trCheck((int)$q->fetchColumn()===1,'Un pago se contabilizó dos veces.');
    trReject(fn()=>transitionAccountingPayment($db,$payment,3,'cancel_payment'),'Revertir pago');
    $income=$create('income','20.00',$today);$confirm($income);$expense=$create('expense','15.00',$today);$confirm($expense);
    trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='155.00','Ingreso o egreso general incorrecto.');
    $range=cashFlowRange([]);$summary=array_column(loadCashFlowSummary($db,$range,$accounts['a'])['currencies'],null,'code');
    trCheck($summary[$currency]['balance_start']==='150.00'&&$summary[$currency]['income']==='20.00'&&$summary[$currency]['expense']==='15.00'&&$summary[$currency]['balance_end']==='155.00','Se mezclan saldo inicial, ingresos y saldo disponible.');
    $transfer=$create('transfer','30.00',$today,null,['destination_account_id'=>$accounts['b'],'destination_currency_code'=>$currency]);$confirm($transfer);
    trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='125.00'&&treasuryBalance($db,$accounts['b'],$currency,$today)==='30.00','Transferencia no afectó ambas cuentas.');
    $a=array_column(loadCashFlowSummary($db,$range,$accounts['a'])['currencies'],null,'code');$b=array_column(loadCashFlowSummary($db,$range,$accounts['b'])['currencies'],null,'code');
    trCheck($a[$currency]['income']==='20.00'&&$a[$currency]['expense']==='15.00'&&$a[$currency]['transfer_out']==='30.00'&&$b[$currency]['income']==='0.00'&&$b[$currency]['transfer_in']==='30.00','Transferir infla los ingresos o egresos externos.');
    $fx=$create('transfer','10.00',$today,null,['destination_account_id'=>$accounts['b'],'destination_currency_code'=>$alt]);$confirm($fx);
    trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='115.00'&&treasuryBalance($db,$accounts['b'],$alt,$today)==='1000.00','La transferencia entre monedas no conserva la conversión.');
    $insufficient=$create('transfer','1000.00',$today,null,['destination_account_id'=>$accounts['b'],'destination_currency_code'=>$currency]);
    trReject(fn()=>confirmTreasuryDocument($db,$insufficient,1),'no alcanza');trCheck(loadTreasuryDocument($db,$insufficient)['status']==='draft'&&treasuryBalance($db,$accounts['b'],$currency,$today)==='30.00','La transferencia fallida dejó un movimiento parcial.');
    // Competing transfers cannot spend the same available funds twice.
    $racing=[];for($n=0;$n<2;$n++)$racing[]=$create('transfer','90.00',$today,null,['destination_account_id'=>$accounts['b'],'destination_currency_code'=>$currency]);
    $holder=createDatabaseConnection();$holder->beginTransaction();$q=$holder->prepare('SELECT id FROM accounting_payment_accounts WHERE id=? FOR UPDATE');$q->execute([$accounts['a']]);$workers=[];
    try{
        foreach($racing as $op){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/treasury-transfer-worker.php',(string)$op],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);trCheck(is_resource($process),'No se inició el trabajador.');fclose($pipes[0]);trCheck(trim((string)fgets($pipes[1]))==='READY','Trabajador no conectado.');$workers[]=[$process,$pipes];}
        usleep(150000);$holder->commit();$results=[];
        foreach($workers as [$process,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);trCheck(proc_close($process)===0&&$error==='','Falló el trabajador: '.$error);}
        sort($results);trCheck($results===['DONE','INSUFFICIENT'],'Las transferencias simultáneas gastaron dos veces el saldo.');
    }finally{if($holder->inTransaction())$holder->rollBack();}
    foreach($racing as $op)if(loadTreasuryDocument($db,$op)['status']==='done')$reverse($op);
    $reverse($fx);$reverse($transfer);trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='155.00'&&treasuryBalance($db,$accounts['b'],$alt,$today)==='0.00','La reversión de transferencia no restaura las cuentas.');
    trReject(fn()=>reverseTreasuryDocument($db,$transfer,3,['reason'=>$prefix,'movement_date'=>$today]),'revertido');
    $reverse($income);$reverse($expense);trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='150.00','Revertir ingresos y egresos no restaura el saldo.');
    [, $html]=trRequest('pagos.php?type=customer&id='.$payment,$cookie);trCheck(str_contains($html,'Revertir pago')&&!str_contains($html,'value="cancel_payment"'),'El pago confirmado todavía permite cancelarse.');
    $paymentReversal=trId(trRequest('pagos.php?type=customer&id='.$payment,$cookie,['csrf'=>trToken($html),'version'=>'3','action'=>'reverse_payment','reason'=>$prefix,'movement_date'=>$today]));
    trCheck(loadAccountingPayment($db,$payment)['status']==='reversed'&&loadAccountingPayment($db,$payment)['available']==='0.00'&&treasuryBalance($db,$accounts['a'],$currency,$today)==='100.00','La reversión del pago no restaura el saldo o deja un anticipo disponible.');
    trReject(fn()=>reverseAccountingPayment($db,$payment,4,['reason'=>$prefix,'movement_date'=>$today]),'confirmado');
    [, $html]=trRequest('tesoreria.php?id='.$paymentReversal,$cookie);trCheck(str_contains($html,'pagos.php?type=customer&amp;id='.$payment),'La reversión no enlaza al pago original.');
    $reverse($opening);$replacement=$create('opening','200.00',$past);$confirm($replacement);trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='200.00','No se puede corregir el saldo inicial conservando el original.');
    $cancelled=$create('expense','1.00',$today);cancelTreasuryDocument($db,$cancelled,1);trCheck(treasuryBalance($db,$accounts['a'],$currency,$today)==='200.00','Cancelar un borrador alteró el saldo.');
    $base=['kind'=>'income','account_id'=>$accounts['a'],'currency_code'=>$currency,'amount'=>'1.00','movement_date'=>$today,'method_id'=>$method,'concept_id'=>$concepts['income']];
    foreach([['method_id'=>''],['concept_id'=>$concepts['expense']],['movement_date'=>'2026-02-30'],['amount'=>'0.00'],['account_id'=>'0'],['kind'=>'transfer','destination_account_id'=>$accounts['a']]] as $bad)trReject(fn()=>saveTreasuryDocument($db,array_replace($base,$bad),null,null,bin2hex(random_bytes(32))));
    [$status]=trRequest('tesoreria.php?id='.$replacement,$cookie,['action'=>'cancel_treasury','version'=>2]);trCheck($status===403,'Tesorería acepta acciones sin CSRF.');
    $hist=loadCashFlowHistory($db,cashFlowRange(['period'=>'custom','date_from'=>$past,'date_to'=>$today]),1,$accounts['a']);
    trCheck(count($hist['rows'])>8,'El historial no incluye saldos, transferencias y correcciones.');
    foreach(['tesoreria.php','tesoreria.php?kind=transfer','ingresos-egresos.php?account_id='.$accounts['a'],'configuracion.php?section=concepts','configuracion.php?section=accounts&id='.$accounts['a']] as $path){[$status,$html]=trRequest($path,$cookie);trCheck($status===200,'No abre '.$path);$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($html);trCheck((new DOMXPath($dom))->query('//form//form')->length===0,'Hay formularios anidados.');}
    $ledgerBefore=$db->query('SELECT * FROM accounting_treasury_entries ORDER BY id')->fetchAll();installTreasury($db);trCheck($db->query('SELECT * FROM accounting_treasury_entries ORDER BY id')->fetchAll()===$ledgerBefore,'Reinstalar duplica o reinterpreta movimientos.');
    echo "PASS: saldos iniciales, pagos, otros ingresos/egresos, conceptos, transferencias/monedas/simultáneas, insuficiencia, reversión, conservación del original, saldos, UI, CSRF e instalación repetible.\n";
}finally{
    if($db->inTransaction())$db->rollBack();cleanupFixtureTreasury($db,$contacts,$prefix);
    rsort($documents,SORT_NUMERIC);foreach($documents as $id){$db->prepare('DELETE FROM accounting_treasury_entries WHERE document_id=?')->execute([$id]);$db->prepare('DELETE FROM accounting_treasury_documents WHERE id=?')->execute([$id]);}
    foreach($contacts as $id)$db->prepare('DELETE FROM accounting_payments WHERE contact_id=?')->execute([$id]);
    foreach($concepts as $kind=>$id)$db->prepare('DELETE FROM accounting_cash_concepts WHERE id=? AND name=?')->execute([$id,$prefix.$kind]);
    foreach($accounts as $key=>$id)$db->prepare('DELETE FROM accounting_payment_accounts WHERE id=? AND name=?')->execute([$id,$prefix.$key]);
    foreach($contacts as $id)$db->prepare('DELETE FROM contacts WHERE id=? AND name=?')->execute([$id,$prefix.' client']);if(is_file($cookie))unlink($cookie);
}
