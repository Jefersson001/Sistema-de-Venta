<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
(static function()use($db,$post,$server,$cookies,$seller,$customer,$product,$creditId,$creditInvoice,$credit,$salePost,$path,$requestPath,$locations,$account,$method,$request,$prefix){
    $config='/modules/venta_directa/configuracion.php';$returns='/modules/venta_directa/devoluciones.php';$closing='/modules/venta_directa/cierre.php';
    dsExpect($post('admin',$config,['action'=>'save_credit','contact_id'=>(string)$customer,'credit_limit'=>'1.00','policy'=>'warn','version'=>'0'])[0]===303,'No se configura el crédito.');
    dsExpect(authTestRequest($server,$config,$cookies['seller'])[0]===403,'Un vendedor configura su propio límite.');
    [$code,$body]=authTestRequest($server,$path.'?credit_customer_id='.$customer,$cookies['seller']);$exposure=json_decode($body,true,8,JSON_THROW_ON_ERROR);dsExpect($code===200&&$exposure['limit']==='1.00'&&$exposure['policy']==='warn','No se consulta la advertencia de crédito.');
    $testSale=$credit;$testSale['request_key']=bin2hex(random_bytes(32));unset($testSale['payment_mode']);$warningSale=dsId($post('seller',$path,$testSale));
    $q=$db->prepare('SELECT payment_mode,credit_warning_json FROM direct_sale_checkouts WHERE id=?');$q->execute([$warningSale]);$warning=$q->fetch();dsExpect($warning['payment_mode']==='credit'&&count(json_decode($warning['credit_warning_json'],true))>0,'La venta advertida no se permite o no conserva su advertencia.');
    dsExpect($post('admin',$config,['action'=>'save_credit','contact_id'=>(string)$customer,'credit_limit'=>'1.00','policy'=>'block','version'=>'1'])[0]===303,'No se cambia la política de prueba.');
    $testSale['request_key']=bin2hex(random_bytes(32));dsExpect($post('seller',$path,$testSale)[0]===422,'No se bloquea cuando la política explícita lo requiere.');
    dsExpect($post('admin',$config,['action'=>'save_credit','contact_id'=>(string)$customer,'credit_limit'=>'','policy'=>'warn','version'=>'2'])[0]===303,'No se restablece Advertir y permitir.');
    $line=(int)$db->query('SELECT id FROM accounting_invoice_lines WHERE invoice_id='.(int)$creditInvoice['id'].' LIMIT 1')->fetchColumn();
    $returnInput=['action'=>'direct_return','sale_id'=>(string)$creditId,'request_key'=>bin2hex(random_bytes(32)),'reason'=>'Devolución parcial del cliente','quantities'=>[$line=>'0.50']];
    $returnId=dsId($post('seller',$returns.'?sale_id='.$creditId,$returnInput));dsExpect(dsId($post('seller',$returns.'?sale_id='.$creditId,$returnInput))===$returnId,'Una devolución se duplica al reintentar.');
    dsExpect($post('seller',$returns.'?id='.$returnId,['action'=>'direct_return_approve'])[0]===403,'El vendedor confirma su propia devolución.');
    $nativeBefore=(int)$db->query('SELECT COUNT(*) FROM inventory_operations')->fetchColumn();dsId($post('manager',$returns.'?id='.$returnId,['action'=>'direct_return_approve']));
    $q=$db->prepare('SELECT * FROM direct_sale_actions WHERE id=?');$q->execute([$returnId]);$returned=$q->fetch();dsExpect($returned['status']==='done'&&$returned['operation_id']&&$returned['credit_id'],'La devolución no genera ambos documentos.');
    $note=$db->query('SELECT * FROM accounting_invoices WHERE id='.(int)$returned['credit_id'])->fetch();dsExpect($note['status']==='posted'&&$note['total_amount']==='3.85','La nota parcial pierde impuestos o cantidades.');
    dsId($post('manager',$returns.'?id='.$returnId,['action'=>'direct_return_approve']));dsExpect((int)$db->query('SELECT COUNT(*) FROM inventory_operations')->fetchColumn()===$nativeBefore+1,'Reintentar la devolución duplica inventario.');
    $returnInput['request_key']=bin2hex(random_bytes(32));$returnInput['quantities']=[$line=>'0.75'];dsExpect($post('seller',$returns.'?sale_id='.$creditId,$returnInput)[0]===422,'Se devuelve más de lo pendiente.');
    $version=(string)$db->query('SELECT version FROM accounting_invoices WHERE id='.(int)$creditInvoice['id'])->fetchColumn();
    $pay=['action'=>'direct_payment','version'=>$version,'request_key'=>bin2hex(random_bytes(32)),'amount'=>'1.00','payment_date'=>commerceToday(),'reference'=>'Abono directo','currency_code'=>'USD','account_id'=>(string)$account,'method_id'=>(string)$method];
    $before=(int)$db->query('SELECT COUNT(*) FROM accounting_payments')->fetchColumn();dsId($post('seller',$path.'?id='.$creditId,$pay));dsId($post('seller',$path.'?id='.$creditId,$pay));dsExpect((int)$db->query('SELECT COUNT(*) FROM accounting_payments')->fetchColumn()===$before+1,'El abono directo se duplica.');
    $q=$db->prepare('SELECT payment_id FROM direct_sale_actions WHERE request_key=?');$q->execute([$pay['request_key']]);$payment=(int)$q->fetchColumn();dsExpect($db->query('SELECT status FROM accounting_payments WHERE id='.$payment)->fetchColumn()==='pending','El cobro se confirma automáticamente.');
    $bad=$pay;$bad['amount']='2.00';dsExpect($post('seller',$path.'?id='.$creditId,$bad)[0]===409,'Se reutiliza una clave para otro importe.');
    $bad=$pay;$bad['request_key']=bin2hex(random_bytes(32));$bad['amount']='100';$bad['version']=(string)$db->query('SELECT version FROM accounting_invoices WHERE id='.(int)$creditInvoice['id'])->fetchColumn();dsExpect($post('seller',$path.'?id='.$creditId,$bad)[0]===422,'Un cobro excede el saldo.');
    // Return of surplus requires approval and physical reception before stock moves.
    $surplus=$request;$surplus['request_key']=bin2hex(random_bytes(32));$surplus['request_kind']='return';$surplus['lines_json']=json_encode([['product_id'=>$product,'quantity'=>'1.00']]);$surplusId=dsId($post('seller',$requestPath,$surplus));
    $requestLine=(int)$db->query('SELECT id FROM direct_stock_request_lines WHERE request_id='.$surplusId)->fetchColumn();
    dsId($post('manager',$requestPath.'?id='.$surplusId,['action'=>'direct_approve','version'=>'1','source_location_id'=>(string)$locations['central'],'approved'=>[$requestLine=>'1.00']]));
    dsId($post('manager',$requestPath.'?id='.$surplusId,['action'=>'direct_dispatch','version'=>'2']));$op=(int)$db->query('SELECT operation_id FROM direct_stock_requests WHERE id='.$surplusId)->fetchColumn();
    $movement=$db->query('SELECT * FROM inventory_operations WHERE id='.$op)->fetch();dsExpect((int)$movement['source_location_id']===$locations['seller']&&(int)$movement['destination_location_id']===$locations['central']&&$movement['status']==='done','El retorno de sobrantes no invierte correctamente el movimiento.');
    [$code,$body]=authTestRequest($server,$closing,$cookies['seller']);dsExpect($code===200&&str_contains($body,'data-day-summary'),'No abre el resumen de jornada.');
    $close=['action'=>'direct_close','seller_id'=>(string)$seller,'closing_date'=>commerceToday(),'declared'=>['USD'=>'16.40'],'notes'=>'Revisión de la jornada'];$closeId=dsId($post('seller',$closing,$close));dsExpect(dsId($post('seller',$closing,$close))===$closeId,'Se duplica un cierre de la misma jornada.');
    $q=$db->prepare('SELECT * FROM direct_day_closings WHERE id=?');$q->execute([$closeId]);$closed=$q->fetch();$snapshot=json_decode($closed['snapshot_json'],true,32,JSON_THROW_ON_ERROR);dsExpect($snapshot['money']['USD']['cash']==='16.40'&&$snapshot['money']['USD']['settled']==='0.00','El cierre confunde cobros pendientes, caja o confirmados.');
    dsExpect($post('seller',$closing.'?id='.$closeId,['action'=>'direct_close_validate'])[0]===403,'El vendedor valida su cierre.');
    dsId($post('manager',$closing.'?id='.$closeId,['action'=>'direct_close_validate']));dsExpect($db->query('SELECT status FROM direct_day_closings WHERE id='.$closeId)->fetchColumn()==='validated','El supervisor no valida el cierre.');
    dsExpect($db->query('SELECT status FROM accounting_payments WHERE id='.$payment)->fetchColumn()==='pending','Validar el cierre confirma dinero sin autorización.');
    $late=$pay;$late['request_key']=bin2hex(random_bytes(32));$late['amount']='0.50';$late['version']=(string)$db->query('SELECT version FROM accounting_invoices WHERE id='.(int)$creditInvoice['id'])->fetchColumn();dsId($post('seller',$path.'?id='.$creditId,$late));
    $q->execute([$closeId]);dsExpect($q->fetch()['snapshot_json']===$closed['snapshot_json'],'Un cobro posterior cambia el cierre histórico.');
    $proofFile=tempnam($server['sessions'],'direct-proof-');file_put_contents($proofFile,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN2kAAAAASUVORK5CYII='));
    try{
        [, $form]=authTestRequest($server,$path.'?id='.$creditId,$cookies['seller']);$upload=$late;$upload['request_key']=bin2hex(random_bytes(32));$upload['version']=(string)$db->query('SELECT version FROM accounting_invoices WHERE id='.(int)$creditInvoice['id'])->fetchColumn();$upload['amount']='0.10';$upload['csrf']=authTestToken($form);$upload['company_scope']=authTestToken($form,'company_scope');$upload['proof_files[0]']=new CURLFile($proofFile,'image/png','comprobante.png');
        $curl=curl_init($server['url'].$path.'?id='.$creditId);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookies['seller'],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$upload]);$body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);dsExpect($code===303,'No se registra el cobro con comprobante: '.mb_substr(strip_tags((string)$body),-1200));
        $proof=$db->prepare('SELECT p.storage_key,p.id FROM payment_proofs p JOIN direct_sale_actions a ON a.payment_id=p.payment_id WHERE a.request_key=?');$proof->execute([$upload['request_key']]);$stored=$proof->fetch();dsExpect($stored&&is_file($server['sessions'].'/payment-proofs/'.$stored['storage_key'].'.bin'),'El comprobante no queda asociado al cobro privado.');
    }finally{unlink($proofFile);}
    echo "PASS: plazo único, crédito configurable, advertencia permitida, abonos idempotentes pendientes, devolución parcial con nota e inventario, retorno de sobrantes y cierre revisado sin confirmar dinero.\n";
})();
