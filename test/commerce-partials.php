<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/commerce-repository.php';
require_once dirname(__DIR__).'/php/invoice-status-repository.php';
require_once dirname(__DIR__).'/php/inventory-stock-repository.php';
require_once dirname(__DIR__).'/php/operation-status-repository.php';
$db=createDatabaseConnection();$prefix='Test parciales '.bin2hex(random_bytes(8));$cookie=tempnam(sys_get_temp_dir(),'partial-');$f=[];$orders=[];$manual=[];
function checkPartial(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function partialRequest(string $path,string $cookie,?array $post=null):array{
    $curl=curl_init('http://localhost/project/modules/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);
    if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$redirect=curl_getinfo($curl,CURLINFO_REDIRECT_URL);if($body===false)throw new RuntimeException(curl_error($curl));curl_close($curl);
    checkPartial(!preg_match('/Fatal error|Warning:|Notice:/',$body),'Error PHP en '.$path.': '.strip_tags($body));return [$code,$body,$redirect];
}
function partialToken(string $body):string{checkPartial(preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$m)===1,'Falta CSRF.');return $m[1];}
function partialId(array $r):int{checkPartial($r[0]===303&&preg_match('/id=(\d+)/',$r[2],$m)===1,'Falló una acción: '.strip_tags($r[1]));return(int)$m[1];}
function partialRecord(PDO $db,string $table,int $id):array{$q=$db->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return $q->fetch();}
try{
    $roles=array_flip($db->query('SELECT id,system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type) VALUES (?,'cash')")->execute([$prefix]);$f['account']=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_warehouses(name) VALUES (?)')->execute([$prefix]);$f['warehouse']=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_locations(name,warehouse_id) VALUES (?,?)')->execute([$prefix,$f['warehouse']]);$f['location']=(int)$db->lastInsertId();
    foreach(['supplier','salesperson','client'] as $role){$db->prepare('INSERT INTO contacts(name,type_id,warehouse_id,location_id) VALUES (?,?,?,?)')->execute([$prefix.$role,$roles[$role],$role==='salesperson'?$f['warehouse']:null,$role==='salesperson'?$f['location']:null]);$f[$role]=(int)$db->lastInsertId();}
    $db->prepare('UPDATE contacts SET salesperson_id=? WHERE id=?')->execute([$f['salesperson'],$f['client']]);
    $db->prepare('INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,sale_price) VALUES (?,1,1,3.00)')->execute([$prefix]);$f['product']=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO inventory_products(name) VALUES (?)')->execute([$prefix.' sin stock']);$f['other_product']=(int)$db->lastInsertId();
    foreach(['compras','ventas'] as $module){$db->prepare('INSERT INTO business_taxes(name,module_key,rate) VALUES (?,?,16)')->execute([$prefix,$module]);$f[$module.'_tax']=(int)$db->lastInsertId();}
    $db->prepare('INSERT INTO accounting_payment_terms(name,days) VALUES (?,30)')->execute([$prefix]);$f['term']=(int)$db->lastInsertId();
    // Configure supplier prices through the product form.
    [, $productForm]=partialRequest('inventario/index.php?id='.$f['product'],$cookie);
    $r=partialRequest('inventario/index.php?id='.$f['product'],$cookie,['csrf'=>partialToken($productForm),'action'=>'save_product','name'=>$prefix,'sale_enabled'=>'1','purchase_enabled'=>'1','sale_price'=>'3.00','cost_price'=>'0','product_type'=>'goods','invoice_policy'=>'delivered','supplier_prices'=>[['supplier_id'=>$f['supplier'],'price'=>'2.00']]]);
    checkPartial($r[0]===303,'No se guardó el precio de proveedor.');
    $prices=loadPurchaseCatalogs($db,null);checkPartial(count(array_filter($prices['supplierPrices'],fn($p)=>(int)$p['product_id']===$f['product']&&$p['price']==='2.00'))===1,'No se relacionó el precio del proveedor.');
    $createOrder=function(string $kind,string $quantity='10.000',bool $confirm=true)use($db,$cookie,$f,&$orders):int{
        $module=$kind==='sale'?'ventas':'compras';[, $body]=partialRequest($module.'/index.php?new=1',$cookie);$token=partialToken($body);
        if($kind==='purchase')checkPartial(str_contains($body,'>Orden de compra</option>')&&!str_contains($body,'>Orden de venta</option>'),'Compra muestra un tipo de venta.');
        $post=['csrf'=>$token,'action'=>$kind==='sale'?'save_quotation':'save_purchase','order_type'=>$kind==='sale'?'delivery':'receipt','order_date'=>commerceToday(),'payment_term_id'=>$f['term'],'destination_location_id'=>$f['location'],$kind==='sale'?'customer_id':'supplier_id'=>$f[$kind==='sale'?'client':'supplier'],'lines'=>[['product_id'=>$f['product'],'quantity'=>$quantity,'unit_price'=>$kind==='sale'?'3.00':'2.00','tax_id'=>$f[$module.'_tax']]]];
        $id=partialId(partialRequest($module.'/index.php?new=1',$cookie,$post));$orders[]=[$kind,$id];
        if($confirm)partialId(partialRequest($module.'/index.php?id='.$id,$cookie,['csrf'=>$token,'version'=>'1','action'=>$kind==='sale'?'confirm_sale':'confirm_purchase']));return $id;
    };
    $move=function(int $id,string $qty,bool $validate=true)use($db,$cookie):void{
        $o=partialRecord($db,'inventory_operations',$id);$path='inventario/operaciones.php?type='.$o['operation_type'].'&id='.$id;[, $body]=partialRequest($path,$cookie);$token=partialToken($body);$lines=loadInventoryOperationLines($db,$id);
        foreach($lines as &$l)$l['quantity']=$qty;unset($l);
        $post=['csrf'=>$token,'version'=>$o['version'],'action'=>'save_operation','contact_id'=>$o['contact_id'],'source_location_id'=>$o['source_location_id'],'destination_location_id'=>$o['destination_location_id'],'scheduled_date'=>$o['scheduled_date'],'notes'=>$o['notes'],'lines'=>$lines];
        partialId(partialRequest($path,$cookie,$post));$o=partialRecord($db,'inventory_operations',$id);
        if($o['status']==='draft'){partialId(partialRequest($path,$cookie,['csrf'=>$token,'version'=>$o['version'],'action'=>'confirm_operation']));$o=partialRecord($db,'inventory_operations',$id);}
        if($validate){partialId(partialRequest($path,$cookie,['csrf'=>$token,'version'=>$o['version'],'action'=>'check_ready']));$o=partialRecord($db,'inventory_operations',$id);partialId(partialRequest($path,$cookie,['csrf'=>$token,'version'=>$o['version'],'action'=>'validate_operation']));}
    };
    $register=function(int $id)use($db,$cookie):void{
        $i=partialRecord($db,'accounting_invoices',$id);$path='contabilidad/index.php?type='.$i['invoice_type'].'&id='.$id;[, $body]=partialRequest($path,$cookie);
        partialId(partialRequest($path,$cookie,['csrf'=>partialToken($body),'action'=>'confirm_invoice','version'=>$i['version'],'invoice_date'=>$i['invoice_date'],'due_date'=>$i['due_date']??'']));
    };
    $purchase=$createOrder('purchase');$receipt=createPurchaseReceipt($db,$purchase);$move($receipt,'6.000');
    $progress=commerceLineProgress($db,'purchase',$purchase)[0];checkPartial($progress['moved']==='6.000'&&$progress['pending']==='4.000','Recepción parcial perdió su pendiente.');
    $links=commerceLinks($db,'purchase',$purchase);checkPartial(count($links['operations'])===2&&createPurchaseReceipt($db,$purchase)===(int)$links['operations'][1]['id'],'No se reutiliza el pendiente creado automáticamente.');
    $bill=createCommerceInvoice($db,'purchase',$purchase);$i=partialRecord($db,'accounting_invoices',$bill);checkPartial($i['base_amount']==='12.00'&&$i['total_amount']==='13.92'&&$i['due_date']===(new DateTimeImmutable($i['invoice_date']))->modify('+30 days')->format('Y-m-d'),'La factura no respeta recepción, impuesto o vencimiento.');$register($bill);
    $move((int)$links['operations'][1]['id'],'4.000');$bill2=createCommerceInvoice($db,'purchase',$purchase);checkPartial($bill2!==$bill&&partialRecord($db,'accounting_invoices',$bill2)['total_amount']==='9.28','No se facturó solo la segunda recepción.');$register($bill2);
    checkPartial(partialRecord($db,'inventory_products',$f['product'])['cost_price']==='2.00','La recepción no actualizó el costo de referencia.');
    $sale=$createOrder('sale');$delivery=createSalesDelivery($db,$sale);$move($delivery,'6.000');$invoice=createCommerceInvoice($db,'sale',$sale);$register($invoice);
    checkPartial(partialRecord($db,'accounting_invoices',$invoice)['total_amount']==='20.88','La factura de venta no respeta las seis unidades entregadas.');
    $links=commerceLinks($db,'sale',$sale);$backorder=(int)$links['operations'][1]['id'];$move($backorder,'4.000',false);
    // A separate operation reserves the four remaining units.
    $storage=loadStorageRelations($db);$contacts=$db->query("SELECT c.id FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE t.system_key='client'")->fetchAll();
    [$data,$errors]=validateInventoryOperation(['contact_id'=>$f['client'],'source_location_id'=>$f['location'],'scheduled_date'=>commerceToday(),'notes'=>$prefix],'delivery',$storage,$contacts);checkPartial(!$errors,'Operación manual inválida.');
    $manualId=saveInventoryOperation($db,$data,null,null,[['product_id'=>$f['product'],'demand'=>'4.000','quantity'=>'4.000']]);$manual[]=$manualId;
    transitionInventoryOperation($db,$manualId,1,'confirm_operation');transitionInventoryOperation($db,$manualId,2,'check_ready');
    $stock=loadInventoryProductStock($db,$f['product']);checkPartial($stock['total']==='4.000'&&$stock['reserved']==='4.000'&&$stock['available']==='0.000','Las reservas no distinguen cantidades a la mano y disponibles.');
    $before=partialRecord($db,'inventory_operations',$backorder);try{transitionInventoryOperation($db,$backorder,(int)$before['version'],'check_ready');throw new RuntimeException('Dos entregas reservaron las mismas unidades.');}catch(InvalidArgumentException $e){checkPartial(str_contains($e->getMessage(),'reservas'),'Error de reserva inesperado.');}
    cancelStockOperation($db,$manualId,3);$before=partialRecord($db,'inventory_operations',$backorder);transitionInventoryOperation($db,$backorder,(int)$before['version'],'check_ready');$before=partialRecord($db,'inventory_operations',$backorder);validateInventoryStockOperation($db,$backorder,(int)$before['version']);
    checkPartial(loadInventoryProductStock($db,$f['product'])['total']==='0.000','La segunda entrega no descontó cuatro unidades.');
    $invoice2=createCommerceInvoice($db,'sale',$sale);checkPartial($invoice2!==$invoice&&partialRecord($db,'accounting_invoices',$invoice2)['total_amount']==='13.92','No se generó la segunda factura de venta.');
    // Cancelled drafts release billable quantities and stay in the linked history.
    transitionInvoiceStatus($db,$invoice2,1,'cancel_invoice',[]);$replacement=createCommerceInvoice($db,'sale',$sale);checkPartial($replacement!==$invoice2,'Se reabrió una factura cancelada en vez de sustituirla.');$register($replacement);
    $o=partialRecord($db,'inventory_operations',$delivery);$return=createStockReturn($db,$delivery,(int)$o['version']);$move($return,'2.000');
    $indicators=loadInventoryProductIndicators($db,$f['product']);checkPartial($indicators['incoming']==='3'&&$indicators['outgoing']==='2','La devolución se contabilizó como salida en vez de entrada.');
    checkPartial(loadInventoryProductStock($db,$f['product'])['total']==='2.000'&&commerceLineProgress($db,'sale',$sale)[0]['moved']==='8.000','La devolución no restituyó existencias y cantidades entregadas.');
    $i=partialRecord($db,'accounting_invoices',$invoice);$credit=createCreditNote($db,$invoice,(int)$i['version'],'Devolución parcial');
    $q=$db->prepare('SELECT id FROM accounting_invoice_lines WHERE invoice_id=?');$q->execute([$credit]);$creditLine=(int)$q->fetchColumn();
    [, $body]=partialRequest('contabilidad/index.php?type=customer&id='.$credit,$cookie);
    partialId(partialRequest('contabilidad/index.php?type=customer&id='.$credit,$cookie,['csrf'=>partialToken($body),'version'=>'1','action'=>'confirm_invoice','invoice_date'=>commerceToday(),'credit_quantities'=>[$creditLine=>'2.000']]));
    checkPartial(partialRecord($db,'accounting_invoices',$credit)['total_amount']==='6.96'&&invoiceCredits($db,$invoice)==='6.96','La nota de crédito parcial no conserva cantidades e impuestos.');
    checkPartial(commerceLineProgress($db,'sale',$sale)[0]['invoiced']==='8.000','La nota de crédito no redujo la cantidad facturada.');
    $creditRecord=partialRecord($db,'accounting_invoices',$credit);checkPartial($creditRecord['payment_state']==='paid','Una nota aplicada a deuda sin cobro exigió reembolso de dinero no recibido.');
    try{transitionInvoiceStatus($db,$credit,(int)$creditRecord['version'],'register_payment',['account_id'=>$f['account'],'amount'=>'1.00','payment_date'=>commerceToday()]);throw new RuntimeException('Se reembolsó dinero no recibido.');}catch(InvalidArgumentException $e){}
    // A paid invoice supports a cash refund through its new credit note.
    $i=partialRecord($db,'accounting_invoices',$invoice);transitionInvoiceStatus($db,$invoice,(int)$i['version'],'register_payment',['account_id'=>$f['account'],'amount'=>'13.92','payment_date'=>commerceToday()]);
    $payments=invoicePaymentDetails($db,$invoice)['payments'];$i=partialRecord($db,'accounting_invoices',$invoice);transitionInvoiceStatus($db,$invoice,(int)$i['version'],'settle_payment',['payment_id'=>$payments[0]['id']]);
    $i=partialRecord($db,'accounting_invoices',$invoice);$fullCredit=createCreditNote($db,$invoice,(int)$i['version'],'Reembolso del resto');$register($fullCredit);
    $c=partialRecord($db,'accounting_invoices',$fullCredit);checkPartial(creditRefundAmount($db,$c)==='13.92'&&partialRecord($db,'accounting_invoices',$invoice)['payment_state']==='reversed','No se calculó el reembolso o la reversión completa.');
    transitionInvoiceStatus($db,$fullCredit,(int)$c['version'],'register_payment',['account_id'=>$f['account'],'amount'=>'13.92','payment_date'=>commerceToday()]);$p=invoicePaymentDetails($db,$fullCredit)['payments'][0];$c=partialRecord($db,'accounting_invoices',$fullCredit);transitionInvoiceStatus($db,$fullCredit,(int)$c['version'],'settle_payment',['payment_id'=>$p['id']]);
    checkPartial(partialRecord($db,'accounting_invoices',$fullCredit)['payment_state']==='paid','No se confirmó el reembolso de la nota de crédito.');
    // Related line changes and quantities above the pending demand are refused.
    $ret=createStockReturn($db,$delivery,(int)$o['version']);$r=partialRecord($db,'inventory_operations',$ret);$lines=loadInventoryOperationLines($db,$ret);$lines[0]['quantity']='999.000';
    try{saveInventoryOperation($db,['operation_type'=>$r['operation_type'],'contact_id'=>(int)$r['contact_id'],'scheduled_date'=>$r['scheduled_date'],'source_location_id'=>(int)$r['source_location_id'],'destination_location_id'=>(int)$r['destination_location_id'],'source_warehouse_id'=>inventoryId($r['source_warehouse_id']),'destination_warehouse_id'=>inventoryId($r['destination_warehouse_id']),'notes'=>$r['notes']],$ret,(int)$r['version'],$lines);throw new RuntimeException('Se aceptó una devolución superior al original.');}catch(InvalidArgumentException $e){}
    $mixed=saveInventoryOperation($db,$data,null,null,[['product_id'=>$f['product'],'demand'=>'2.000','quantity'=>'2.000'],['product_id'=>$f['other_product'],'demand'=>'5.000','quantity'=>'0.000']]);$manual[]=$mixed;
    transitionInventoryOperation($db,$mixed,1,'confirm_operation');transitionInventoryOperation($db,$mixed,2,'check_ready');
    checkPartial(loadInventoryProductStock($db,$f['other_product'])['reserved']==='0.000','Se reservó un producto con cantidad cero en una operación parcial.');cancelStockOperation($db,$mixed,3);
    // Force two separate DB connections to wait on the same stock row before reserving.
    $racing=[];
    for($n=0;$n<2;$n++){$op=saveInventoryOperation($db,$data,null,null,[['product_id'=>$f['product'],'demand'=>'2.000','quantity'=>'2.000']]);$manual[]=$op;$racing[]=$op;transitionInventoryOperation($db,$op,1,'confirm_operation');}
    $holder=createDatabaseConnection();$holder->beginTransaction();$lock=$holder->prepare('SELECT quantity FROM inventory_stock WHERE product_id=? AND location_id=? FOR UPDATE');$lock->execute([$f['product'],$f['location']]);
    $workers=[];
    try{
        foreach($racing as $op){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/reservation-worker.php',(string)$op,'2'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);checkPartial(is_resource($process),'No se inició el proceso de reserva.');fclose($pipes[0]);checkPartial(trim((string)fgets($pipes[1]))==='READY','La conexión de prueba no está lista.');$workers[]=[$process,$pipes];}
        usleep(150000);$holder->commit();$results=[];
        foreach($workers as [$process,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);checkPartial($exit===0&&$errors==='','Falló una conexión de reserva: '.$exit.' '.$errors.' '.end($results));}
        sort($results);checkPartial($results===['RESERVED','WAITING'],'Las reservas simultáneas comprometieron dos veces el mismo stock.');
    }finally{if($holder->inTransaction())$holder->rollBack();}
    foreach($racing as $op)cancelStockOperation($db,$op,(int)partialRecord($db,'inventory_operations',$op)['version']);
    // Cancellation is explicit on the order; cancelling a picking never cancels its order.
    foreach(['draft','sent'] as $state){
        $draft=$createOrder('purchase','1.000',false);
        if($state==='sent')transitionPurchaseOrder($db,$draft,1,'sent');
        $path='compras/index.php?id='.$draft;[, $body]=partialRequest($path,$cookie);
        checkPartial(str_contains($body,'value="cancel_purchase"')&&str_contains($body,'Cancelado'),'Falta el botón o estado Cancelado en Compras.');
        $version=$state==='sent'?2:1;
        [$code]=partialRequest($path,$cookie,['action'=>'cancel_purchase','version'=>$version]);checkPartial($code===403,'Cancelación sin CSRF aceptada.');
        partialId(partialRequest($path,$cookie,['csrf'=>partialToken($body),'action'=>'cancel_purchase','version'=>$version]));
        checkPartial(partialRecord($db,'purchase_orders',$draft)['status']==='cancel','No se canceló la solicitud.');
        [, $body]=partialRequest($path,$cookie);
        checkPartial(str_contains($body,'<fieldset disabled')&&!str_contains($body,'value="confirm_purchase"')&&!str_contains($body,'Guardar solicitud')&&!str_contains($body,'data-order-remove'),'La compra cancelada permite modificar o confirmar.');
        [, $list]=partialRequest('compras/index.php?status=cancel&q='.rawurlencode(partialRecord($db,'purchase_orders',$draft)['name']),$cookie);
        checkPartial(str_contains($list,'href="index.php?id='.$draft.'"'),'El filtro no muestra compras canceladas.');
        try{transitionPurchaseOrder($db,$draft,$version+1,'purchase');throw new RuntimeException('Se confirmó una compra cancelada.');}catch(InvalidArgumentException $e){}
        try{savePurchaseOrder($db,[],[],$draft,$version+1);throw new RuntimeException('Se editó una compra cancelada.');}catch(InvalidArgumentException $e){}
    }
    foreach(['sale','purchase'] as $kind){
        $id=$createOrder($kind,'1.000');$table=$kind==='sale'?'sales_quotations':'purchase_orders';$module=$kind==='sale'?'ventas':'compras';$action=$kind==='sale'?'cancel_quotation':'cancel_purchase';
        $operation=createLinkedStockOperation($db,$kind,$id);transitionInventoryOperation($db,$operation,1,'confirm_operation');
        if($kind==='sale'){
            transitionInventoryOperation($db,$operation,2,'check_ready');
            checkPartial(inventoryReserved($db,$f['product'],$f['location'])!=='0.000','La entrega no reservó existencias.');
            cancelStockOperation($db,$operation,3);
            checkPartial(partialRecord($db,$table,$id)['status']==='sale','Cancelar la entrega canceló la venta.');
            $replacement=createLinkedStockOperation($db,$kind,$id);checkPartial($replacement!==$operation,'Se reutilizó la entrega cancelada.');
            transitionInventoryOperation($db,$replacement,1,'confirm_operation');transitionInventoryOperation($db,$replacement,2,'check_ready');
        }
        $path=$module.'/index.php?id='.$id;[, $body]=partialRequest($path,$cookie);$token=partialToken($body);
        checkPartial(str_contains($body,'value="'.$action.'"'),'Falta Cancelar en la orden confirmada.');
        partialRequest($path,$cookie,['csrf'=>$token,'action'=>$action,'version'=>1]);
        checkPartial(partialRecord($db,$table,$id)['status']===($kind==='sale'?'sale':'purchase'),'Una versión antigua canceló la orden.');
        $stock=loadInventoryProductStock($db,$f['product'])['total'];
        partialId(partialRequest($path,$cookie,['csrf'=>$token,'action'=>$action,'version'=>2]));
        checkPartial(partialRecord($db,$table,$id)['status']==='cancel','No se canceló la orden confirmada.');
        $linked=commerceLinks($db,$kind,$id);
        checkPartial(count(array_filter($linked['operations'],fn($o)=>$o['status']!=='cancel'))===0,'La cancelación dejó movimientos pendientes.');
        checkPartial(!$linked['can_stock']&&!$linked['can_invoice'],'Una orden cancelada permite generar documentos.');
        [, $cancelledForm]=partialRequest($path,$cookie);checkPartial(str_contains($cancelledForm,'data-related-operation'),'Se perdió el acceso al historial de movimientos cancelados.');
        $opIds=array_column($linked['operations'],'id');$q=$db->prepare('SELECT COUNT(*) FROM inventory_reservations WHERE operation_id IN ('.implode(',',array_fill(0,count($opIds),'?')).')');$q->execute($opIds);
        checkPartial((int)$q->fetchColumn()===0&&loadInventoryProductStock($db,$f['product'])['total']===$stock,'La cancelación no liberó reservas o alteró existencias.');
        try{createLinkedStockOperation($db,$kind,$id);throw new RuntimeException('Se generó un movimiento desde una orden cancelada.');}catch(InvalidArgumentException $e){}
        try{createCommerceInvoice($db,$kind,$id);throw new RuntimeException('Se facturó una orden cancelada.');}catch(InvalidArgumentException $e){}
    }
    // Active invoices and completed stock moves must reject the whole cancellation.
    $db->prepare("UPDATE inventory_products SET invoice_policy='ordered' WHERE id=?")->execute([$f['product']]);
    foreach(['sale','purchase'] as $kind){
        $id=$createOrder($kind,'1.000');$table=$kind==='sale'?'sales_quotations':'purchase_orders';$op=createLinkedStockOperation($db,$kind,$id);$invoice=createCommerceInvoice($db,$kind,$id);
        $cancel=fn(int $order,int $version)=>$kind==='sale'?transitionSalesQuotation($db,$order,$version,'cancel'):transitionPurchaseOrder($db,$order,$version,'cancel');
        try{$cancel($id,2);throw new RuntimeException('Se canceló una orden con factura activa.');}catch(InvalidArgumentException $e){checkPartial(str_contains($e->getMessage(),'facturas activas'),'Rechazo incorrecto de factura activa.');}
        checkPartial(partialRecord($db,'inventory_operations',$op)['status']==='draft'&&(int)partialRecord($db,$table,$id)['version']===2,'El rechazo dejó cambios parciales.');
        transitionInvoiceStatus($db,$invoice,1,'cancel_invoice',[]);$cancel($id,2);
        checkPartial(partialRecord($db,$table,$id)['status']==='cancel','Las facturas ya canceladas bloquean la orden.');
        try{$cancel($kind==='sale'?$sale:$purchase,2);throw new RuntimeException('Se canceló una orden con movimientos hechos.');}catch(InvalidArgumentException $e){checkPartial(str_contains($e->getMessage(),'movimientos hechos'),'Rechazo incorrecto de movimiento hecho.');}
    }
    foreach(['ventas/index.php?id='.$sale,'compras/index.php?id='.$purchase,'inventario/operaciones.php?type=delivery&sale_id='.$sale,'contabilidad/index.php?type=customer&sale_id='.$sale] as $path){[$status,$body]=partialRequest($path,$cookie);checkPartial($status===200,'No abre documentos relacionados.');}
    echo "PASS: compra y venta parciales, relaciones por línea, pendientes automáticos, facturas sucesivas, impuestos, vencimiento, precios por proveedor, reservas, cancelación, sustitución, devolución y nota de crédito parcial.\n";
}finally{
    if($db->inTransaction())$db->rollBack();
    foreach($orders as [$kind,$id]){
        $fk=$kind==='sale'?'sale_id':'purchase_id';$q=$db->prepare('SELECT id FROM accounting_invoices WHERE '.$fk.'=? ORDER BY id DESC');$q->execute([$id]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $invoice){foreach(['accounting_invoice_payments','accounting_invoice_reversals','accounting_invoice_lines'] as $t)$db->prepare('DELETE FROM '.$t.' WHERE invoice_id=?')->execute([$invoice]);$db->prepare('DELETE FROM accounting_invoices WHERE id=?')->execute([$invoice]);}
    }
    $operations=$manual;
    foreach($orders as [$kind,$id]){$q=$db->prepare('SELECT id FROM inventory_operations WHERE '.($kind==='sale'?'quotation_id':'purchase_order_id').'=?');$q->execute([$id]);$operations=array_merge($operations,$q->fetchAll(PDO::FETCH_COLUMN));}
    rsort($operations,SORT_NUMERIC);foreach($operations as $id){foreach(['inventory_reservations','inventory_stock_moves','inventory_operation_lines'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE operation_id=?')->execute([$id]);$db->prepare('DELETE FROM inventory_operations WHERE id=?')->execute([$id]);}
    foreach($orders as [$kind,$id]){$db->prepare('DELETE FROM '.($kind==='sale'?'sales_quotation_lines WHERE quotation_id':'purchase_order_lines WHERE order_id').'=?')->execute([$id]);$db->prepare('DELETE FROM '.($kind==='sale'?'sales_quotations':'purchase_orders').' WHERE id=?')->execute([$id]);}
    if(isset($f['product'])){foreach(['inventory_stock','product_supplier_prices'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE product_id=?')->execute([$f['product']]);$db->prepare('DELETE FROM inventory_products WHERE id=? AND name=?')->execute([$f['product'],$prefix]);}
    if(isset($f['other_product'])){$db->prepare('DELETE FROM inventory_stock WHERE product_id=?')->execute([$f['other_product']]);$db->prepare('DELETE FROM inventory_products WHERE id=? AND name=?')->execute([$f['other_product'],$prefix.' sin stock']);}
    foreach(['client','supplier'] as $party)if(isset($f[$party]))$db->prepare('DELETE FROM accounting_payments WHERE contact_id=?')->execute([$f[$party]]);
    foreach(['client','supplier','salesperson'] as $role)if(isset($f[$role]))$db->prepare('DELETE FROM contacts WHERE id=? AND name=?')->execute([$f[$role],$prefix.$role]);
    foreach(['compras_tax','ventas_tax'] as $tax)if(isset($f[$tax]))$db->prepare('DELETE FROM business_taxes WHERE id=? AND name=?')->execute([$f[$tax],$prefix]);
    foreach(['term'=>'accounting_payment_terms','location'=>'inventory_locations','warehouse'=>'inventory_warehouses'] as $key=>$table)if(isset($f[$key]))$db->prepare('DELETE FROM '.$table.' WHERE id=? AND name=?')->execute([$f[$key],$prefix]);
    if(is_file($cookie))unlink($cookie);
    if(isset($f['account']))$db->prepare('DELETE FROM accounting_payment_accounts WHERE id=? AND name=?')->execute([$f['account'],$prefix]);
}
