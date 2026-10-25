<?php
declare(strict_types=1);
require_once __DIR__.'/http-session.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/connection.php';require_once dirname(__DIR__).'/php/payments-repository.php';require_once dirname(__DIR__).'/php/dashboard-repository.php';
require_once dirname(__DIR__).'/php/cash-flow-repository.php';
require_once __DIR__.'/treasury-cleanup.php';
$db=createDatabaseConnection();$prefix='Test pagos '.bin2hex(random_bytes(8));$cookie=tempnam(sys_get_temp_dir(),'payments-');$f=[];$orders=[];$payments=[];
function payCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function payRequest(string $path,string $cookie,?array $post=null):array{
    $url=str_starts_with($path,'modules/')||$path==='index.php'?'http://localhost/project/'.$path:'http://localhost/project/modules/'.$path;
    $c=testHttpCurl($url, $cookie ?? $cookieJar ?? $cookieFile ?? null);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);if($post!==null)curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$redirect=curl_getinfo($c,CURLINFO_REDIRECT_URL);if($body===false)throw new RuntimeException(curl_error($c));curl_close($c);payCheck(!preg_match('/Fatal error|Warning:|Notice:/',$body),'Error PHP: '.$path);return[$status,$body,$redirect];
}
function payToken(string $html):string{payCheck(preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m)===1,'Falta CSRF.');return$m[1];}
function payCreated(array $r):int{payCheck($r[0]===303&&preg_match('/id=(\d+)/',$r[2],$m)===1,'Falló una acción: '.strip_tags($r[1]));return(int)$m[1];}
function payInvoice(PDO $db,int $id):array{$q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$id]);return$q->fetch();}
try{
    $baseline=loadDashboard($db);$cashRange=cashFlowRange([]);$cashBaseline=array_column(loadCashFlowSummary($db,$cashRange)['currencies'],null,'code');$roles=array_flip($db->query('SELECT id,system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach(['client','other','supplier'] as $role){$db->prepare('INSERT INTO contacts(name,type_id) VALUES(?,?)')->execute([$prefix.$role,$roles[$role==='supplier'?'supplier':'client']]);$f[$role]=(int)$db->lastInsertId();}
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type) VALUES (?,'cash')")->execute([$prefix.' account']);$f['account']=(int)$db->lastInsertId();
    $f['method']=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES (?,'bank','USD')")->execute([$prefix.' restricted']);$f['restricted']=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,purchase_enabled,product_type,invoice_policy) VALUES(?,1,1,'service','ordered')")->execute([$prefix]);$f['product']=(int)$db->lastInsertId();
    $createInvoice=function(string $kind,int $contact,string $amount,string $currency='USD')use($db,$cookie,$f,&$orders):int{
        $module=$kind==='sale'?'ventas':'compras';[, $body]=payRequest($module.'/index.php?new=1',$cookie);$token=payToken($body);
        $order=payCreated(payRequest($module.'/index.php?new=1',$cookie,['csrf'=>$token,'action'=>$kind==='sale'?'save_quotation':'save_purchase','order_type'=>'invoice','order_date'=>commerceToday(),'currency_code'=>$currency,'exchange_rate'=>'100',$kind==='sale'?'customer_id':'supplier_id'=>$contact,'lines'=>[['product_id'=>$f['product'],'quantity'=>'1.00','unit_price'=>$amount]]]));$orders[]=[$kind,$order];
        payCreated(payRequest($module.'/index.php?id='.$order,$cookie,['csrf'=>$token,'version'=>'1','action'=>$kind==='sale'?'confirm_sale':'confirm_purchase']));$id=createCommerceInvoice($db,$kind,$order);$type=$kind==='sale'?'customer':'supplier';[, $body]=payRequest('contabilidad/index.php?type='.$type.'&id='.$id,$cookie);$yesterday=(new DateTimeImmutable(commerceToday()))->modify('-1 day')->format('Y-m-d');
        payCreated(payRequest('contabilidad/index.php?type='.$type.'&id='.$id,$cookie,['csrf'=>payToken($body),'action'=>'confirm_invoice','version'=>'1','invoice_date'=>$yesterday,'due_date'=>$yesterday]));return$id;
    };
    $first=$createInvoice('sale',$f['client'],'100.00');$second=$createInvoice('sale',$f['client'],'70.00');$foreign=$createInvoice('sale',$f['other'],'50.00');$bill=$createInvoice('purchase',$f['supplier'],'75.00');$vesInvoice=$createInvoice('sale',$f['client'],'1000.00','VES');
    // Explicit accounts are required both in standalone payments and invoice wizards.
    foreach(['customer'=>$first,'supplier'=>$bill] as $type=>$invoiceId){
        [, $newForm]=payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie);
        $formDom=new DOMDocument();libxml_use_internal_errors(true);$formDom->loadHTML('<?xml encoding="UTF-8">'.$newForm);$formXpath=new DOMXPath($formDom);
        payCheck($formXpath->query('//select[@name="account_id" and @required]/option[@value="" and @selected]')->length===1
            &&$formXpath->query('//select[@name="account_id"]/option[@selected and @value!=""]')->length===0,'Un pago nuevo selecciona una cuenta automáticamente.');
        payCheck($formXpath->query('//select[@name="method_id" and @required]/option[1][@value=""]')->length===1&&$formXpath->query('//select[@name="method_id"]/option[@selected and @value!=""]')->length===0,'El medio de pago no exige elección explícita.');
        payCheck(preg_match('/name="creation_key" value="([a-f0-9]+)"/',$newForm,$keyMatch)===1,'Falta sesión de creación.');
        $contact=$f[$type==='customer'?'client':'supplier'];
        $valid=['csrf'=>payToken($newForm),'creation_key'=>$keyMatch[1],'action'=>'save_payment','contact_id'=>$contact,'direction'=>$type==='customer'?'inbound':'outbound','amount'=>'1.00','currency_code'=>'USD','payment_date'=>commerceToday(),'reference'=>$prefix,'method_id'=>$f['method']];
        $invoicePath='contabilidad/index.php?type='.$type.'&id='.$invoiceId;[, $wizard]=payRequest($invoicePath,$cookie);
        $formDom->loadHTML('<?xml encoding="UTF-8">'.$wizard);$formXpath=new DOMXPath($formDom);
        payCheck($formXpath->query('//dialog[@id="invoice-payment-wizard"]//select[@name="account_id" and @required]/option[@value="" and @selected]')->length===1,'El asistente propone una cuenta automáticamente.');
        payCheck($formXpath->query('//dialog[@id="invoice-payment-wizard"]//select[@name="method_id" and @required]/option[1][@value=""]')->length===1,'El asistente no exige seleccionar medio de pago.');
        $oldInvoice=payInvoice($db,$invoiceId);
        foreach([[],['account_id'=>''],['account_id'=>'0'],['account_id'=>[]],['account_id'=>'999999999'],['account_id'=>$f['restricted'],'currency_code'=>'VES','exchange_rate'=>'100']] as $bad){
            $before=$db->query('SELECT COUNT(*) FROM accounting_payments WHERE contact_id='.(int)$contact)->fetchColumn();
            [$status,$failed]=payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie,array_replace($valid,$bad));
            payCheck($status===200&&str_contains($failed,'cuenta')&&$db->query('SELECT COUNT(*) FROM accounting_payments WHERE contact_id='.(int)$contact)->fetchColumn()===$before,'Se creó un pago sin cuenta válida.');
            $input=['csrf'=>payToken($wizard),'action'=>'register_payment','version'=>$oldInvoice['version'],'amount'=>'1.00','currency_code'=>'USD','payment_date'=>commerceToday(),'reference'=>$prefix,'method_id'=>$f['method']];
            [$status,$failed]=payRequest($invoicePath,$cookie,array_replace($input,$bad));
            payCheck($status===200&&str_contains($failed,'data-reopen')&&str_contains($failed,'cuenta')&&payInvoice($db,$invoiceId)===$oldInvoice&&!invoicePaymentDetails($db,$invoiceId)['payments'],'Una cuenta inválida alteró la factura o creó un pago.');
        }
        foreach([null,'',0,[],'999999999'] as $badMethod){
            $post=$valid+['account_id'=>$f['account']];if($badMethod===null)unset($post['method_id']);else $post['method_id']=$badMethod;
            [$status,$failed]=payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie,$post);payCheck($status===200&&str_contains($failed,'medio de pago'),'Se aceptó pago sin medio válido.');
            $post['action']='register_payment';$post['csrf']=payToken($wizard);$post['version']=$oldInvoice['version'];
            [$status,$failed]=payRequest($invoicePath,$cookie,$post);payCheck($status===200&&str_contains($failed,'medio de pago')&&payInvoice($db,$invoiceId)===$oldInvoice,'Se aceptó pago de factura sin medio válido.');
        }
    }
    $d=loadDashboard($db);payCheck(bcsub($d['receivables']['USD'],$baseline['receivables']['USD']??'0',2)==='220.00'&&bcsub($d['receivables']['VES'],$baseline['receivables']['VES']??'0',2)==='1000.00','El inicio mezcla monedas o no incluye saldos nuevos.');payCheck($d['overdue']===$baseline['overdue']+5,'Las facturas vencidas no se calculan desde fechas y saldos.');
    [, $home]=payRequest('index.php',$cookie);payCheck(str_contains($home,'modules/dashboard/index.php')&&str_contains($home,'Mis módulos')&&!str_contains($home,'Pagos sin aplicar')&&!str_contains($home,'Nueva venta'),'El inicio no quedó limitado al panel de módulos.');
    [, $dashboardPage]=payRequest('dashboard/index.php',$cookie);payCheck(str_contains($dashboardPage,'../ventas/index.php?status=all')&&str_contains($dashboardPage,htmlspecialchars(dashboardMoney($d['receivables']),ENT_QUOTES,'UTF-8'))&&str_contains($dashboardPage,'Pagos sin aplicar'),'Dashboard no muestra los datos reales o perdió sus acciones.');
    $createPayment=function(string $type,int $contact,string $amount,string $currency='USD')use($db,$cookie,$prefix,$f,&$payments):int{
        [, $form]=payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie);payCheck(preg_match('/name="creation_key" value="([a-f0-9]+)"/',$form,$m)===1,'Falta protección de creación.');
        $post=['csrf'=>payToken($form),'creation_key'=>$m[1],'action'=>'save_payment','account_id'=>$f['account'],'method_id'=>$f['method'],'contact_id'=>$contact,'direction'=>$type==='customer'?'inbound':'outbound','amount'=>$amount,'currency_code'=>$currency,'exchange_rate'=>'100','payment_date'=>commerceToday(),'reference'=>$prefix];
        $id=payCreated(payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie,$post));$payments[]=$id;payCheck(payCreated(payRequest('contabilidad/pagos.php?type='.$type.'&new=1',$cookie,$post))===$id,'Se duplicó el pago por repetir Guardar.');
        [, $savedForm]=payRequest('contabilidad/pagos.php?type='.$type.'&id='.$id,$cookie);$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$savedForm);
        payCheck((new DOMXPath($dom))->query('//select[@name="account_id"]/option[@selected and @value="'.$f['account'].'"]')->length===1,'Editar un borrador no conserva su cuenta.');return$id;
    };
    $payment=$createPayment('customer',$f['client'],'150.00');$p=loadAccountingPayment($db,$payment);payCheck(!$p['allocations']&&$p['available']==='150.00','Un pago independiente se aplicó automáticamente.');
    try{transitionAccountingPayment($db,$payment,1,'apply_payment',['invoice_id'=>$first,'amount'=>'1.00']);throw new RuntimeException('Se aplicó un borrador.');}catch(InvalidArgumentException $e){}
    [, $body]=payRequest('contabilidad/pagos.php?type=customer&id='.$payment,$cookie);$token=payToken($body);payCreated(payRequest('contabilidad/pagos.php?type=customer&id='.$payment,$cookie,['csrf'=>$token,'version'=>'1','action'=>'post_payment']));
    $p=loadAccountingPayment($db,$payment);transitionAccountingPayment($db,$payment,(int)$p['version'],'apply_payment',['invoice_id'=>$first,'amount'=>'60.00']);$p=loadAccountingPayment($db,$payment);transitionAccountingPayment($db,$payment,(int)$p['version'],'apply_payment',['invoice_id'=>$second,'amount'=>'70.00']);
    $p=loadAccountingPayment($db,$payment);payCheck($p['available']==='20.00'&&payInvoice($db,$first)['payment_state']==='in_payment','No se mantiene el importe restante o el estado en proceso.');
    foreach([['invoice_id'=>$foreign,'amount'=>'10.00'],['invoice_id'=>$first,'amount'=>'30.00'],['invoice_id'=>$bill,'amount'=>'10.00']] as $bad)try{transitionAccountingPayment($db,$payment,(int)$p['version'],'apply_payment',$bad);throw new RuntimeException('Se aceptó otra persona, tipo o un sobrepago.');}catch(InvalidArgumentException $e){}
    transitionAccountingPayment($db,$payment,(int)$p['version'],'settle_payment');payCheck(payInvoice($db,$first)['payment_state']==='partial'&&payInvoice($db,$second)['payment_state']==='paid','Confirmar el pago no actualizó todas sus facturas.');
    $cashTotals=array_column(loadCashFlowSummary($db,$cashRange)['currencies'],null,'code');payCheck(bcsub($cashTotals['USD']['income'],$cashBaseline['USD']['income']??'0.00',2)==='150.00','Un pago aplicado a dos facturas debe contar una vez y conservar el anticipo sin aplicar.');
    [, $body]=payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie);$i=payInvoice($db,$first);$p=loadAccountingPayment($db,$payment);
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$body);$xpath=new DOMXPath($dom);
    payCheck($xpath->query('//*[@id="invoice-tab-payments"]')->length===0&&$xpath->query('//*[@id="invoice-lines"]//*[@id="invoice-payments"]')->length===1,'Los pagos siguen en una pestaña separada.');
    payCheck($xpath->query('//dialog[@id="invoice-payment-wizard"]//form/input[@name="action" and @value="register_payment"]')->length===1&&$xpath->query('//dialog[@open]')->length===0&&$xpath->query('//button[@data-invoice-wizard-open="invoice-payment-wizard"]')->length===1,'El registro de pago no está en un asistente cerrado con botón de apertura.');
    $candidate=$xpath->query('//*[@id="invoice-lines"]//form[input[@name="existing_payment_id" and @value="'.$payment.'"]]')->item(0);
    payCheck($candidate!==null&&$xpath->query('.//button[normalize-space(.)="Agregar"]',$candidate)->length===1&&$xpath->query('preceding::dl[contains(@class,"quotation-totals")]',$candidate)->length>=1,'El pago no aparece después de los totales con Agregar.');
    $apply=[];foreach($xpath->query('.//input[@type="hidden"]',$candidate) as $input)$apply[$input->getAttribute('name')]=$input->getAttribute('value');
    payCheck($apply['amount']==='20.00'&&$apply['payment_version']===(string)$p['version'],'Agregar no conserva el importe disponible y la versión del pago.');
    [$status,$failed]=payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie,['csrf'=>payToken($body),'action'=>'register_payment','account_id'=>$f['account'],'method_id'=>$f['method'],'version'=>$i['version'],'amount'=>'0.00','payment_date'=>commerceToday(),'reference'=>'Conservar referencia']);
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$failed);$xpath=new DOMXPath($dom);
    payCheck($status===200&&$xpath->query('//dialog[@id="invoice-payment-wizard" and @data-reopen]')->length===1&&$xpath->query('//dialog//input[@name="reference" and @value="Conservar referencia"]')->length===1&&payInvoice($db,$first)['payment_state']==='partial','Un pago inválido no conserva el asistente o alteró la factura.');
    payCheck($xpath->query('//dialog[@id="invoice-payment-wizard"]//select[@name="account_id"]/option[@selected and @value="'.$f['account'].'"]')->length===1,'El error del asistente descartó la cuenta elegida.');
    payCreated(payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie,$apply));payCheck(loadAccountingPayment($db,$payment)['available']==='0.00','Aplicar desde factura duplicó o perdió el importe disponible.');
    $p=loadAccountingPayment($db,$payment);transitionAccountingPayment($db,$payment,(int)$p['version'],'unapply_payment',['invoice_id'=>$first]);$p=loadAccountingPayment($db,$payment);payCheck($p['available']==='80.00'&&payInvoice($db,$first)['payment_state']==='not_paid'&&payInvoice($db,$second)['payment_state']==='paid','Retirar una aplicación alteró las otras facturas o no liberó el importe.');
    transitionAccountingPayment($db,$payment,(int)$p['version'],'reverse_payment',['reason'=>$prefix,'movement_date'=>commerceToday()]);payCheck(payInvoice($db,$second)['payment_state']==='not_paid','Revertir no recalculó la factura restante.');
    // Create in VES from a USD invoice; both amounts are retained in the application.
    [, $body]=payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie);$i=payInvoice($db,$first);
    payCreated(payRequest('contabilidad/index.php?type=customer&id='.$first,$cookie,['csrf'=>payToken($body),'action'=>'register_payment','account_id'=>$f['account'],'method_id'=>$f['method'],'version'=>$i['version'],'amount'=>'2000.00','currency_code'=>'VES','exchange_rate'=>'100','payment_date'=>commerceToday(),'reference'=>$prefix]));
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
    // Excess payments stay in the same payment, with no automatic assignment to other invoices.
    foreach (['customer' => 'sale', 'supplier' => 'purchase'] as $type => $kind) {
        $contact = $f[$type === 'customer' ? 'client' : 'supplier'];
        $small = $createInvoice($kind, $contact, '30.00');
        $next = $createInvoice($kind, $contact, '25.00');
        $path = 'contabilidad/index.php?type='.$type.'&id='.$small;
        [, $body] = payRequest($path, $cookie); $i = payInvoice($db, $small);
        $post = ['csrf' => payToken($body), 'action' => 'register_payment', 'account_id' => $f['account'], 'method_id' => $f['method'], 'version' => $i['version'], 'amount' => '50.00', 'currency_code' => 'USD', 'payment_date' => commerceToday()];
        payCreated(payRequest($path, $cookie, $post));
        $allocation = invoicePaymentDetails($db, $small)['payments'][0];
        $excess = loadAccountingPayment($db, (int)$allocation['payment_id']);
        payCheck($excess['amount'] === '50.00' && $excess['allocated'] === '30.00' && $excess['available'] === '20.00' && payInvoice($db, $small)['payment_state'] === 'in_payment', 'Registrar 50 para factura 30 no conserva el excedente o el estado en proceso.');
        payCheck(payInvoice($db, $next)['payment_state'] === 'not_paid' && !invoicePaymentDetails($db, $next)['payments'], 'El excedente se asignó automáticamente a otra factura.');
        [$status] = payRequest($path, $cookie, $post);
        payCheck($status === 200 && count(invoicePaymentDetails($db, $small)['payments']) === 1, 'Reenviar el asistente duplicó el exceso.');
        $i = payInvoice($db, $small);
        payCreated(payRequest($path, $cookie, ['csrf' => payToken($body), 'action' => 'settle_payment', 'version' => $i['version'], 'payment_id' => $allocation['id']]));
        $excess = loadAccountingPayment($db, $excess['id']);
        payCheck(payInvoice($db, $small)['payment_state'] === 'paid' && $excess['status'] === 'settled' && $excess['available'] === '20.00', 'Confirmar no liquida la factura o pierde el excedente.');
        $nextPath = 'contabilidad/index.php?type='.$type.'&id='.$next;
        [, $body] = payRequest($nextPath, $cookie); @$dom->loadHTML('<?xml encoding="UTF-8">'.$body); $xpath = new DOMXPath($dom);
        $candidate = $xpath->query('//*[@id="invoice-lines"]//form[input[@name="existing_payment_id" and @value="'.$excess['id'].'"]]')->item(0);
        payCheck($candidate !== null, 'La siguiente factura no propone el excedente disponible.');
        $apply = []; foreach ($xpath->query('.//input[@type="hidden"]', $candidate) as $input) $apply[$input->getAttribute('name')] = $input->getAttribute('value');
        payCreated(payRequest($nextPath, $cookie, $apply));
        payCheck(loadAccountingPayment($db, $excess['id'])['available'] === '0.00' && payInvoice($db, $next)['payment_state'] === 'partial' && invoicePaymentDetails($db, $next)['settled'] === '20.00', 'Agregar el excedente no deja el saldo correcto en la siguiente factura.');
        $i = payInvoice($db, $next);
        payCreated(payRequest($nextPath, $cookie, ['csrf' => payToken($body), 'action' => 'register_payment', 'account_id' => $f['account'], 'method_id' => $f['method'], 'version' => $i['version'], 'amount' => '5.00', 'payment_date' => commerceToday()]));
        payCheck(payInvoice($db, $next)['payment_state'] === 'in_payment', '20 confirmados y 5 pendientes sobre factura 25 no muestran En proceso de pago.');
        $items = invoicePaymentDetails($db, $next)['payments']; $pending = end($items); $i = payInvoice($db, $next);
        payCreated(payRequest($nextPath, $cookie, ['csrf' => payToken($body), 'action' => 'cancel_payment', 'version' => $i['version'], 'payment_id' => $pending['id']]));
        payCheck(payInvoice($db, $next)['payment_state'] === 'partial', 'Retirar el pago pendiente no restaura el estado parcial.');
        $released = loadAccountingPayment($db, (int)$pending['payment_id']); $i = payInvoice($db, $next);
        transitionAccountingPayment($db, $released['id'], (int)$released['version'], 'apply_payment', ['invoice_id' => $next, 'invoice_version' => $i['version'], 'amount' => '5.00']);
        $i = payInvoice($db, $next);
        payCreated(payRequest($nextPath, $cookie, ['csrf' => payToken($body), 'action' => 'settle_payment', 'version' => $i['version'], 'payment_id' => $pending['id']]));
        payCheck(payInvoice($db, $next)['payment_state'] === 'paid', 'La segunda factura no queda Pagado al confirmar todos sus pagos.');
    }
    $fxInvoice = $createInvoice('sale', $f['client'], '30.00');
    $path = 'contabilidad/index.php?type=customer&id='.$fxInvoice;
    [, $body] = payRequest($path, $cookie); $i = payInvoice($db, $fxInvoice);
    payCreated(payRequest($path, $cookie, ['csrf' => payToken($body), 'action' => 'register_payment', 'account_id' => $f['account'], 'method_id' => $f['method'], 'version' => $i['version'], 'amount' => '5000.00', 'currency_code' => 'VES', 'exchange_rate' => '100', 'payment_date' => commerceToday()]));
    $allocation = invoicePaymentDetails($db, $fxInvoice)['payments'][0]; $excess = loadAccountingPayment($db, (int)$allocation['payment_id']);
    payCheck($allocation['amount'] === '30.00' && $allocation['payment_amount'] === '3000.00' && $excess['available'] === '2000.00', 'El exceso en VES no conserva las unidades de factura y pago.');
    payCheck(paymentApplicableAmount('50.00', '0.03', 'VES', 'USD', ['primary_code' => 'USD', 'alternate_code' => 'VES', 'exchange_rate' => '0.5']) === '0.01', 'El redondeo genera una aplicación superior al saldo.');
    echo "PASS: pagos independientes, excedente 50/30 aplicado después a otra factura, estados mixtos confirmados/en proceso, clientes/proveedores, USD/VES, redondeo y saldos del inicio.\n";
}finally{
    if($db->inTransaction())$db->rollBack();
    cleanupFixtureTreasury($db,array_values(array_intersect_key($f,array_flip(['client','other','supplier']))),$prefix);
    foreach($orders as [$kind,$id]){$q=$db->prepare('SELECT id FROM accounting_invoices WHERE '.($kind==='sale'?'sale_id':'purchase_id').'=? ORDER BY id DESC');$q->execute([$id]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $invoice){foreach(['accounting_invoice_payments','accounting_invoice_reversals','accounting_invoice_lines'] as $t)$db->prepare('DELETE FROM '.$t.' WHERE invoice_id=?')->execute([$invoice]);$db->prepare('DELETE FROM accounting_invoices WHERE id=?')->execute([$invoice]);}$db->prepare('DELETE FROM '.($kind==='sale'?'sales_quotation_lines WHERE quotation_id':'purchase_order_lines WHERE order_id').'=?')->execute([$id]);$db->prepare('DELETE FROM '.($kind==='sale'?'sales_quotations':'purchase_orders').' WHERE id=?')->execute([$id]);}
    foreach(['client','other','supplier'] as $party)if(isset($f[$party]))$db->prepare('DELETE FROM accounting_payments WHERE contact_id=?')->execute([$f[$party]]);
    foreach(['account','restricted'] as $key)if(isset($f[$key]))$db->prepare('DELETE FROM accounting_payment_accounts WHERE id=? AND name=?')->execute([$f[$key],$prefix.' '.$key]);
    if(isset($f['product']))$db->prepare('DELETE FROM inventory_products WHERE id=? AND name=?')->execute([$f['product'],$prefix]);
    foreach(['client','other','supplier'] as $party)if(isset($f[$party]))$db->prepare('DELETE FROM contacts WHERE id=? AND name=?')->execute([$f[$party],$prefix.$party]);
    if(is_file($cookie))unlink($cookie);
}
