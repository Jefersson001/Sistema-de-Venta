<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/connection.php';require_once dirname(__DIR__).'/php/payments-repository.php';require_once dirname(__DIR__).'/php/dashboard-repository.php';
$db=createDatabaseConnection();$prefix='Test pagos '.bin2hex(random_bytes(8));$cookie=tempnam(sys_get_temp_dir(),'payments-');$f=[];$orders=[];$payments=[];
function payCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function payRequest(string $path,string $cookie,?array $post=null):array{
    $url=str_starts_with($path,'modules/')||$path==='index.php'?'http://localhost/project/'.$path:'http://localhost/project/modules/'.$path;
    $c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);if($post!==null)curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$redirect=curl_getinfo($c,CURLINFO_REDIRECT_URL);if($body===false)throw new RuntimeException(curl_error($c));curl_close($c);payCheck(!preg_match('/Fatal error|Warning:|Notice:/',$body),'Error PHP: '.$path);return[$status,$body,$redirect];
}
function payToken(string $html):string{payCheck(preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m)===1,'Falta CSRF.');return$m[1];}
function payCreated(array $r):int{payCheck($r[0]===303&&preg_match('/id=(\d+)/',$r[2],$m)===1,'Falló una acción: '.strip_tags($r[1]));return(int)$m[1];}
function payInvoice(PDO $db,int $id):array{$q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$id]);return$q->fetch();}
try{
    $baseline=loadDashboard($db);$roles=array_flip($db->query('SELECT id,system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach(['client','other','supplier'] as $role){$db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix.$role,$roles[$role==='supplier'?'supplier':'client']]);$f[$role]=(int)$db->lastInsertId();}
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,product_type,invoice_policy) VALUES(?,1,1,'service','ordered')")->execute([$prefix]);$f['product']=(int)$db->lastInsertId();
    $createInvoice=function(string $kind,int $contact,string $amount,string $currency='USD')use($db,$cookie,$f,&$orders):int{
        $module=$kind==='sale'?'ventas':'compras';[, $body]=payRequest($module.'/index.php?new=1',$cookie);$token=payToken($body);
        $order=payCreated(payRequest($module.'/index.php?new=1',$cookie,['csrf'=>$token,'action'=>$kind==='sale'?'save_quotation':'save_purchase','order_type'=>'invoice','order_date'=>commerceToday(),'currency_code'=>$currency,'exchange_rate'=>'100',$kind==='sale'?'customer_id':'supplier_id'=>$contact,'lines'=>[['product_id'=>$f['product'],'quantity'=>'1.000','unit_price'=>$amount]]]));$orders[]=[$kind,$order];
        payCreated(payRequest($module.'/index.php?id='.$order,$cookie,['csrf'=>$token,'version'=>'1','action'=>$kind==='sale'?'confirm_sale':'confirm_purchase']));$id=createCommerceInvoice($db,$kind,$order);$type=$kind==='sale'?'customer':'supplier';[, $body]=payRequest('contabilidad/index.php?type='.$type.'&id='.$id,$cookie);$yesterday=(new DateTimeImmutable(commerceToday()))->modify('-1 day')->format('Y-m-d');
        payCreated(payRequest('contabilidad/index.php?type='.$type.'&id='.$id,$cookie,['csrf'=>payToken($body),'action'=>'confirm_invoice','version'=>'1','invoice_date'=>$yesterday,'due_date'=>$yesterday]));return$id;
    };
    $first=$createInvoice('sale',$f['client'],'100.00');$second=$createInvoice('sale',$f['client'],'70.00');$foreign=$createInvoice('sale',$f['other'],'50.00');$bill=$createInvoice('purchase',$f['supplier'],'75.00');$vesInvoice=$createInvoice('sale',$f['client'],'1000.00','VES');
    $d=loadDashboard($db);payCheck(bcsub($d['receivables']['USD'],$baseline['receivables']['USD']??'0',2)==='220.00'&&bcsub($d['receivables']['VES'],$baseline['receivables']['VES']??'0',2)==='1000.00','El inicio mezcla monedas o no incluye saldos nuevos.');payCheck($d['overdue']===$baseline['overdue']+5,'Las facturas vencidas no se calculan desde fechas y saldos.');
    [, $home]=payRequest('index.php',$cookie);payCheck(str_contains($home,'modules/ventas/index.php?new=1')&&!str_contains($home,'Sin datos disponibles')&&str_contains($home,'Pagos sin aplicar'),'El inicio conserva datos ficticios o acciones deshabilitadas.');
    $createPayment=function(string $type,int $contact,string $amount,string $currency='USD')use($db,$cookie,$prefix,&$payments):int{
        [, $form]=payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie);payCheck(preg_match('/name="creation_key" value="([a-f0-9]+)"/',$form,$m)===1,'Falta protección de creación.');
        $post=['csrf'=>payToken($form),'creation_key'=>$m[1],'action'=>'save_payment','contact_id'=>$contact,'direction'=>$type==='customer'?'inbound':'outbound','amount'=>$amount,'currency_code'=>$currency,'exchange_rate'=>'100','payment_date'=>commerceToday(),'reference'=>$prefix];
        $id=payCreated(payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie,$post));$payments[]=$id;payCheck(payCreated(payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie,$post))===$id,'Se duplicó el pago por repetir Guardar.');return$id;
    };
    $payment=$createPayment('customer',$f['client'],'150.00');$p=loadAccountingPayment($db,$payment);payCheck(!$p['allocations']&&$p['available']==='150.00','Un pago independiente se aplicó automáticamente.');
    try{transitionAccountingPayment($db,$payment,1,'apply_payment',['invoice_id'=>$first,'amount'=>'1.00']);throw new RuntimeException('Se aplicó un borrador.');}catch(InvalidArgumentException $e){}
    [, $body]=payRequest('contabilidad/pagos.php?type=customer&id='.$payment,$cookie);$token=payToken($body);payCreated(payRequest('contabilidad/pagos.php?type=customer&id='.$payment,$cookie,['csrf'=>$token,'version'=>'1','action'=>'post_payment']));
    $p=loadAccountingPayment($db,$payment);transitionAccountingPayment($db,$payment,(int)$p['version'],'apply_payment',['invoice_id'=>$first,'amount'=>'60.00']);$p=loadAccountingPayment($db,$payment);transitionAccountingPayment($db,$payment,(int)$p['version'],'apply_payment',['invoice_id'=>$second,'amount'=>'70.00']);
    $p=loadAccountingPayment($db,$payment);payCheck($p['available']==='20.00'&&payInvoice($db,$first)['payment_state']==='in_payment','No se mantiene el importe restante o el estado en proceso.');
    foreach([['invoice_id'=>$foreign,'amount'=>'10.00'],['invoice_id'=>$first,'amount'=>'30.00'],['invoice_id'=>$bill,'amount'=>'10.00']] as $bad)try{transitionAccountingPayment($db,$payment,(int)$p['version'],'apply_payment',$bad);throw new RuntimeException('Se aceptó otra persona, tipo o un sobrepago.');}catch(InvalidArgumentException $e){}
    transitionAccountingPayment($db,$payment,(int)$p['version'],'settle_payment');payCheck(payInvoice($db,$first)['payment_state']==='partial'&&payInvoice($db,$second)['payment_state']==='paid','Confirmar el pago no actualizó todas sus facturas.');
    [, $body]=payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie);$i=payInvoice($db,$first);$p=loadAccountingPayment($db,$payment);
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$body);$xpath=new DOMXPath($dom);
    payCheck($xpath->query('//*[@id="invoice-tab-payments"]')->length===0&&$xpath->query('//*[@id="invoice-lines"]//*[@id="invoice-payments"]')->length===1,'Los pagos siguen en una pestaña separada.');
    payCheck($xpath->query('//dialog[@id="invoice-payment-wizard"]//form/input[@name="action" and @value="register_payment"]')->length===1&&$xpath->query('//dialog[@open]')->length===0&&$xpath->query('//button[@data-invoice-wizard-open="invoice-payment-wizard"]')->length===1,'El registro de pago no está en un asistente cerrado con botón de apertura.');
    $candidate=$xpath->query('//*[@id="invoice-lines"]//form[input[@name="existing_payment_id" and @value="'.$payment.'"]]')->item(0);
    payCheck($candidate!==null&&$xpath->query('.//button[normalize-space(.)="Agregar"]',$candidate)->length===1&&$xpath->query('preceding::dl[contains(@class,"quotation-totals")]',$candidate)->length>=1,'El pago no aparece después de los totales con Agregar.');
    $apply=[];foreach($xpath->query('.//input[@type="hidden"]',$candidate) as $input)$apply[$input->getAttribute('name')]=$input->getAttribute('value');
    payCheck($apply['amount']==='20.00'&&$apply['payment_version']===(string)$p['version'],'Agregar no conserva el importe disponible y la versión del pago.');
    [$status,$failed]=payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie,['csrf'=>payToken($body),'action'=>'register_payment','version'=>$i['version'],'amount'=>'50000.00','payment_date'=>commerceToday(),'reference'=>'Conservar referencia']);
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$failed);$xpath=new DOMXPath($dom);
    payCheck($status===200&&$xpath->query('//dialog[@id="invoice-payment-wizard" and @data-reopen]')->length===1&&$xpath->query('//dialog//input[@name="reference" and @value="Conservar referencia"]')->length===1&&payInvoice($db,$first)['payment_state']==='partial','Un pago inválido no conserva el asistente o alteró la factura.');
    payCreated(payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie,$apply));payCheck(loadAccountingPayment($db,$payment)['available']==='0.00','Aplicar desde factura duplicó o perdió el importe disponible.');
    $p=loadAccountingPayment($db,$payment);transitionAccountingPayment($db,$payment,(int)$p['version'],'unapply_payment',['invoice_id'=>$first]);$p=loadAccountingPayment($db,$payment);payCheck($p['available']==='80.00'&&payInvoice($db,$first)['payment_state']==='not_paid'&&payInvoice($db,$second)['payment_state']==='paid','Retirar una aplicación alteró las otras facturas o no liberó el importe.');
    transitionAccountingPayment($db,$payment,(int)$p['version'],'cancel_payment');payCheck(payInvoice($db,$second)['payment_state']==='not_paid','Cancelar no recalculó la factura restante.');
    // Create in VES from a USD invoice; both amounts are retained in the application.
    [, $body]=payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie);$i=payInvoice($db,$first);
    payCreated(payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie,['csrf'=>payToken($body),'action'=>'register_payment','version'=>$i['version'],'amount'=>'2000.00','currency_code'=>'VES','exchange_rate'=>'100','payment_date'=>commerceToday(),'reference'=>$prefix]));
    $allocations=invoicePaymentDetails($db,$first)['payments'];$a=end($allocations);$payments[]=(int)$a['payment_id'];payCheck($a['amount']==='20.00'&&$a['payment_amount']==='2000.00'&&$a['payment_currency']==='VES','La conversión no conservó ambos importes.');
    $i=payInvoice($db,$first);transitionInvoiceStatus($db,$first,(int)$i['version'],'settle_payment',['payment_id'=>$a['id']]);
    $vesPayment=$createPayment('customer',$f['client'],'8000.00','VES');transitionAccountingPayment($db,$vesPayment,1,'post_payment');transitionAccountingPayment($db,$vesPayment,2,'settle_payment');$p=loadAccountingPayment($db,$vesPayment);
    transitionAccountingPayment($db,$vesPayment,(int)$p['version'],'apply_payment',['invoice_id'=>$first,'amount'=>'8000.00']);payCheck(payInvoice($db,$first)['payment_state']==='paid'&&loadAccountingPayment($db,$vesPayment)['available']==='0.00','Un pago confirmado en moneda alterna no completó la factura.');
    $supplierPayment=$createPayment('supplier',$f['supplier'],'75.00');transitionAccountingPayment($db,$supplierPayment,1,'post_payment');
    [, $body]=payRequest('contabilidad/index.php?type=supplier&id='.$bill,$cookie);@$dom->loadHTML('<?xml encoding="UTF-8">'.$body);$xpath=new DOMXPath($dom);
    payCheck($xpath->query('//*[@id="invoice-lines"]//form/input[@name="existing_payment_id" and @value="'.$supplierPayment.'"]')->length===1&&$xpath->query('//dialog[@id="invoice-payment-wizard"]')->length===1,'La factura de proveedor no muestra los pagos disponibles y el asistente.');
    transitionAccountingPayment($db,$supplierPayment,2,'apply_payment',['invoice_id'=>$bill,'amount'=>'75.00']);transitionAccountingPayment($db,$supplierPayment,3,'settle_payment');payCheck(payInvoice($db,$bill)['payment_state']==='paid','El pago al proveedor no liquidó su factura.');
    $d=loadDashboard($db);payCheck(bcsub($d['receivables']['USD'],$baseline['receivables']['USD']??'0',2)==='120.00'&&bcsub($d['payables']['USD']??'0',$baseline['payables']['USD']??'0',2)==='0.00','El inicio no descuenta pagos confirmados.');
    // FX snapshots can be used in the opposite direction, independently of later settings.
    $usdPayment=$createPayment('customer',$f['client'],'10.00');transitionAccountingPayment($db,$usdPayment,1,'post_payment');transitionAccountingPayment($db,$usdPayment,2,'settle_payment');transitionAccountingPayment($db,$usdPayment,3,'apply_payment',['invoice_id'=>$vesInvoice,'amount'=>'10.00']);payCheck(payInvoice($db,$vesInvoice)['payment_state']==='paid','No se aplicaron USD a la factura en VES.');
    foreach(['contabilidad/pagos.php?type=customer','contabilidad/pagos.php?type=supplier','contabilidad/configuracion.php?section=accounts','contabilidad/configuracion.php?section=methods','contabilidad/configuracion.php?section=currencies'] as $path){[$status,$body]=payRequest($path,$cookie);payCheck($status===200,'No abre una vista del módulo.');}
    [$status]=payRequest('contabilidad/pagos.php?type=customer&id='.$payment,$cookie,['action'=>'cancel_payment','version'=>'1']);payCheck($status===403,'Se aceptó un cambio sin CSRF.');
    echo "PASS: pagos independientes tree/form, creación repetida, varias facturas por pago, aplicación desde factura, saldos, retiro, cancelación, clientes/proveedores, conversión USD/VES y resumen real.\n";
}finally{
    if($db->inTransaction())$db->rollBack();
    foreach($orders as [$kind,$id]){$q=$db->prepare('SELECT id FROM accounting_invoices WHERE '.($kind==='sale'?'sale_id':'purchase_id').'=? ORDER BY id DESC');$q->execute([$id]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $invoice){foreach(['accounting_invoice_payments','accounting_invoice_reversals','accounting_invoice_lines'] as $t)$db->prepare('DELETE FROM '.$t.' WHERE invoice_id=?')->execute([$invoice]);$db->prepare('DELETE FROM accounting_invoices WHERE id=?')->execute([$invoice]);}$db->prepare('DELETE FROM '.($kind==='sale'?'sales_quotation_lines WHERE quotation_id':'purchase_order_lines WHERE order_id').'=?')->execute([$id]);$db->prepare('DELETE FROM '.($kind==='sale'?'sales_quotations':'purchase_orders').' WHERE id=?')->execute([$id]);}
    foreach(['client','other','supplier'] as $party)if(isset($f[$party]))$db->prepare('DELETE FROM accounting_payments WHERE contact_id=?')->execute([$f[$party]]);
    if(isset($f['product']))$db->prepare('DELETE FROM inventory_products WHERE id=? AND name=?')->execute([$f['product'],$prefix]);
    foreach(['client','other','supplier'] as $party)if(isset($f[$party]))$db->prepare('DELETE FROM contacts WHERE id=? AND name=?')->execute([$f[$party],$prefix.$party]);
    if(is_file($cookie))unlink($cookie);
}
