<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/commission-repository.php';
require_once dirname(__DIR__).'/php/commerce-repository.php';
require_once dirname(__DIR__).'/php/invoice-status-repository.php';
require_once dirname(__DIR__).'/php/cash-flow-repository.php';
require_once dirname(__DIR__).'/php/commission-schema.php';
require_once __DIR__.'/treasury-cleanup.php';
function commissionCheck(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function commissionReject(callable $action): void { try{$action();throw new RuntimeException('Se aceptó un pago inválido.');}catch(InvalidArgumentException){} }
function commissionInvoiceView(string $html, string $amount, string $currency, string $total): void {
    $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($dom);
    commissionCheck($xpath->query('//*[@data-invoice-line-commission][text()="'.$amount.'"]')->length===1,'La factura no muestra la comisión original de la línea.');
    commissionCheck($xpath->query('//*[@data-invoice-commission-total]/dd[text()="'.$amount.' '.$currency.'"]')->length===1,'El total de comisión de la factura no coincide con sus líneas y moneda.');
    commissionCheck($xpath->query('//*[@data-invoice-total]/dd[text()="'.$total.'"]')->length===1,'Se sumó nuevamente la comisión al total de la factura.');
}
function commissionRequest(string $path,string $cookie,?array $post=null): array {
    $curl=curl_init('http://localhost/project/modules/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);
    if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$location=curl_getinfo($curl,CURLINFO_REDIRECT_URL);curl_close($curl);
    commissionCheck(is_string($body)&&!preg_match('/Fatal error|Warning:|Notice:/',$body),'Error PHP en '.$path);return[$status,$body,$location];
}
$db=createDatabaseConnection();$prefix='Test comisiones '.bin2hex(random_bytes(8));$f=[];$orders=[];$cookie=tempnam(sys_get_temp_dir(),'commissions-');
try {
    $settings=accountingCurrencies($db);$code=$settings['primary_code'];$today=commerceToday();
    $roles=array_flip($db->query('SELECT id,system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach(['salesperson','client'] as $role){$db->prepare('INSERT INTO contacts(name,type_id) VALUES (?,?)')->execute([$prefix.$role,$roles[$role]]);$f[$role]=(int)$db->lastInsertId();}
    $db->prepare('UPDATE contacts SET salesperson_id=? WHERE id=?')->execute([$f['salesperson'],$f['client']]);
    $db->prepare("INSERT INTO inventory_products(name,sale_enabled,sale_price,cost_price,currency_code,product_type,invoice_policy) VALUES (?,1,5,1,?,'service','ordered')")->execute([$prefix,$code]);$f['product']=(int)$db->lastInsertId();
    [, $productForm]=commissionRequest('inventario/index.php?id='.$f['product'],$cookie);
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$productForm,$productToken);
    commissionCheck(str_contains($productForm,'name="commission_type"')&&str_contains($productForm,'name="commission_value"'),'Falta la configuración de comisión por producto.');
    $productPost=['csrf'=>$productToken[1],'action'=>'save_product','name'=>$prefix,'sale_enabled'=>'1','sale_price'=>'5.00','cost_price'=>'1.00','currency_code'=>$code,'product_type'=>'service','invoice_policy'=>'ordered','commission_type'=>'fixed','commission_value'=>'2.00'];
    [$status]=commissionRequest('inventario/index.php?id='.$f['product'],$cookie,$productPost);commissionCheck($status===303,'No se guardó la comisión fija.');
    foreach([['commission_type'=>'invalid'],['commission_type'=>['bad']],['commission_value'=>['bad']],['commission_value'=>'-1'],['commission_value'=>'1.001'],['commission_type'=>'percent','commission_value'=>'1001']] as $invalid) {
        [$status,$body]=commissionRequest('inventario/index.php?id='.$f['product'],$cookie,array_replace($productPost,$invalid));
        commissionCheck($status===200&&str_contains($body,'notice-error'),'Se aceptó una configuración de comisión inválida: '.json_encode($invalid));
    }
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES (?,'cash',?)")->execute([$prefix,$code]);$f['account']=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES (?,'bank',?)")->execute([$prefix.' empty',$code]);$f['empty_account']=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type,currency_code) VALUES (?,'bank',?)")->execute([$prefix.' alternate',$settings['alternate_code']]);$f['alternate_account']=(int)$db->lastInsertId();
    $opening=saveTreasuryDocument($db,['kind'=>'opening','account_id'=>(string)$f['alternate_account'],'currency_code'=>$settings['alternate_code'],'amount'=>'1000.00','movement_date'=>$today],null,null,bin2hex(random_bytes(32)));confirmTreasuryDocument($db,$opening,1);
    $method=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $db->prepare("INSERT INTO business_taxes(name,module_key,rate) VALUES (?,'ventas',10)")->execute([$prefix]);$f['tax']=(int)$db->lastInsertId();
    $db->prepare('UPDATE inventory_products SET sale_tax_id=? WHERE id=?')->execute([$f['tax'],$f['product']]);$productPost['sale_tax_id']=(string)$f['tax'];
    [$status,$body]=commissionRequest('ventas/producto.php?id='.$f['product'],$cookie);$productSnapshot=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    commissionCheck($status===200&&$productSnapshot['product']['commission_type']==='fixed'&&$productSnapshot['product']['commission_value']==='2.00'&&(int)$productSnapshot['tax']['id']===$f['tax'],'El endpoint no devuelve la comisión y el impuesto configurados.');
    [$status]=commissionRequest('ventas/producto.php?id[]=1',$cookie);commissionCheck($status===422,'Se aceptó un identificador de producto inválido.');
    [$status]=commissionRequest('ventas/producto.php?id=999999999',$cookie);commissionCheck($status===404,'No se informa que el producto no existe.');
    [$status]=commissionRequest('ventas/producto.php?id='.$f['product'],$cookie,[]);commissionCheck($status===405,'El endpoint del producto admite escrituras.');
    $input=['customer_id'=>(string)$f['client'],'order_type'=>'invoice','order_date'=>$today,'currency_code'=>$code,
        'lines'=>[['product_id'=>(string)$f['product'],'quantity'=>'2.000','tax_id'=>(string)$f['tax']]]];
    [$data,$lines,$errors]=validateSalesQuotation($input,loadSalesCatalogs($db,null));commissionCheck(!$errors,'La venta no se valida: '.implode(' ',$errors));
    commissionCheck($lines[0]['unit_price']==='7.00'&&$lines[0]['commission_unit_amount']==='2.00','La regla fija no cargó el precio automático.');
    $automatic=$input;unset($automatic['lines'][0]['tax_id']);
    [, $automaticLines,$automaticErrors]=validateSalesQuotation($automatic,loadSalesCatalogs($db,null));
    commissionCheck(!$automaticErrors&&$automaticLines[0]['tax_id']===$f['tax'],'No se trae el impuesto del producto al validar la venta.');
    $manual=$input;$manual['lines'][0]['unit_price']='8.00';
    [, $manualLines,$manualErrors]=validateSalesQuotation($manual,loadSalesCatalogs($db,null));
    commissionCheck(!$manualErrors&&salesCommissionAmount('2.000','8.00',$manualLines[0]['commission_reference_price'],$manualLines[0]['commission_unit_amount'])==='4.00','Editar el precio cambió la comisión fija configurada.');
    [$store,$storeLines,$storeErrors]=validateSalesQuotation($input+['salesperson_id'=>'','salesperson_explicit'=>'1'],loadSalesCatalogs($db,null));
    commissionCheck(!$storeErrors&&$store['salesperson_id']===null&&$storeLines[0]['unit_price']==='5.00','La venta de tienda recibió un aumento de vendedor.');
    [$converted,$convertedLines,$convertedErrors]=validateSalesQuotation(array_replace($input,['currency_code'=>$settings['alternate_code'],'exchange_rate'=>'100']),loadSalesCatalogs($db,null));
    commissionCheck(!$convertedErrors&&$convertedLines[0]['unit_price']==='700.00'&&$convertedLines[0]['commission_reference_price']==='500.00'&&$convertedLines[0]['commission_unit_amount']==='200.00','No se convirtieron conjuntamente precio y comisión.');
    $sale=saveSalesQuotation($db,$data,$lines,null,null);$orders[]=$sale;
    commissionCheck(loadSalesQuotation($db,$sale)['commission_estimated']==='4.00','Se confundió comisión con costo o impuesto.');
    if(in_array('--product-browser-review',$argv,true)){echo 'REVIEW '.json_encode(['product'=>$f['product'],'sale'=>$sale,'seller'=>$f['salesperson'],'client'=>$f['client']])."\n";fgets(STDIN);}
    [$status]=commissionRequest('inventario/index.php?id='.$f['product'],$cookie,array_replace($productPost,['commission_type'=>'percent','commission_value'=>'40']));commissionCheck($status===303,'No se guardó el porcentaje.');
    [, $percentLines,$percentErrors]=validateSalesQuotation($input,loadSalesCatalogs($db,null));
    commissionCheck(!$percentErrors&&$percentLines[0]['unit_price']==='7.00'&&$percentLines[0]['commission_unit_amount']==='2.00','El porcentaje no se aplica al precio en tienda.');
    $db->prepare("UPDATE inventory_products SET sale_price=10,commission_type='percent',commission_value=80 WHERE id=?")->execute([$f['product']]);
    $old=loadSalesQuotation($db,$sale);$input['lines'][0]['line_id']=(string)$old['lines'][0]['id'];
    [$data,$lines,$errors]=validateSalesQuotation($input,loadSalesCatalogs($db,$old),$old);
    commissionCheck(!$errors&&$lines[0]['commission_reference_price']==='5.00'&&$lines[0]['commission_unit_amount']==='2.00','Cambiar el producto alteró la referencia o comisión histórica.');
    saveSalesQuotation($db,$data,$lines,$sale,(int)$old['version']);transitionSalesQuotation($db,$sale,2,'sale');
    $invoice=workflowCreateInvoice($db,'sale',$sale);
    $commission=fn()=>loadSalesCommissions($db,null,null,$invoice)[0];
    commissionCheck($commission()['commission_lines'][0]['commission_unit_amount']==='2.00','La comisión configurada no viajó a la factura.');
    commissionCheck($commission()['commission_state']==='estimated'&&$commission()['commission_generated']==='4.00','La factura no recibió la referencia.');
    [, $html]=commissionRequest('contabilidad/index.php?type=customer&id='.$invoice,$cookie);
    commissionInvoiceView($html,'4.00',$code,'15.40');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$token);
    [$status]=commissionRequest('contabilidad/index.php?type=customer&id='.$invoice,$cookie,['csrf'=>$token[1],'version'=>'1','action'=>'confirm_invoice','invoice_date'=>$today,'due_date'=>$today]);
    commissionCheck($status===303,'No se registró la factura.');
    [, $saleForm]=commissionRequest('ventas/index.php?id='.$sale,$cookie);
    $saleDom=new DOMDocument();libxml_use_internal_errors(true);$saleDom->loadHTML('<?xml encoding="UTF-8">'.$saleForm);$saleXpath=new DOMXPath($saleDom);
    commissionCheck($saleXpath->query('//*[@data-quotation-tax-group]/dd[text()="1.40"]')->length===1&&$saleXpath->query('//*[@data-quotation-tax-group]/dt[contains(text(),"10 %")]')->length===1,'El desglose de impuestos no aparece al abrir una venta guardada.');
    $paymentInput=['amount'=>'1.00','currency_code'=>$code,'movement_date'=>$today,'account_id'=>(string)$f['account'],'method_id'=>(string)$method];
    commissionReject(fn()=>paySalesCommission($db,$invoice,$paymentInput,bin2hex(random_bytes(32))));
    $collect=function(string $amount)use($db,$invoice,$code,$today,$f,$method): void {
        $q=$db->prepare('SELECT version FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);
        paymentFromInvoice($db,$invoice,(int)$q->fetchColumn(),'register_payment',['amount'=>$amount,'currency_code'=>$code,'payment_date'=>$today,'account_id'=>(string)$f['account'],'method_id'=>(string)$method]);
        $q=$db->prepare("SELECT p.id,p.version FROM accounting_payments p JOIN accounting_invoice_payments l ON l.payment_id=p.id WHERE l.invoice_id=? AND p.status='pending' ORDER BY p.id DESC LIMIT 1");$q->execute([$invoice]);$p=$q->fetch();
        transitionAccountingPayment($db,(int)$p['id'],(int)$p['version'],'settle_payment');
    };
    $collect('7.70');commissionCheck($commission()['commission_earned']==='2.00'&&$commission()['commission_waiting']==='2.00','Cobro parcial no habilitó la proporción correcta.');
    [, $invoiceHtml]=commissionRequest('contabilidad/index.php?type=customer&id='.$invoice,$cookie);
    commissionInvoiceView($invoiceHtml,'4.00',$code,'15.40');
    foreach([['amount'=>'3.00'],['amount'=>'0'],['amount'=>[]],['account_id'=>''],['method_id'=>''],['account_id'=>(string)$f['empty_account']],['currency_code'=>[]]] as $bad)commissionReject(fn()=>paySalesCommission($db,$invoice,array_replace($paymentInput,$bad),bin2hex(random_bytes(32))));
    $concept=(string)$db->query("SELECT id FROM accounting_cash_concepts WHERE system_key='sales_commission'")->fetchColumn();
    commissionReject(fn()=>saveTreasuryDocument($db,$paymentInput+['kind'=>'expense','concept_id'=>$concept,'contact_id'=>(string)$f['salesperson']],null,null,bin2hex(random_bytes(32))));
    $key=bin2hex(random_bytes(32));$first=paySalesCommission($db,$invoice,$paymentInput,$key);
    commissionCheck(paySalesCommission($db,$invoice,$paymentInput,$key)===$first,'Un reenvío duplicó el egreso.');
    commissionCheck($commission()['commission_paid']==='1.00'&&$commission()['commission_pending']==='1.00','El pago parcial no se aplicó.');
    $q=$db->prepare('SELECT COUNT(*),SUM(amount) FROM accounting_treasury_entries WHERE document_id=?');$q->execute([$first]);$entry=$q->fetch(PDO::FETCH_NUM);
    commissionCheck((int)$entry[0]===1&&$entry[1]==='-1.00','El pago no produjo exactamente un egreso.');
    [, $html]=commissionRequest('ventas/comisiones.php?id='.$invoice,$cookie);
    if(in_array('--browser-review',$argv,true)){echo 'REVIEW '.json_encode(['invoice'=>$invoice,'sale'=>$sale,'seller'=>$f['salesperson'],'account'=>$f['account']])."\n";fgets(STDIN);}
    commissionCheck(str_contains($html,'Pagar comisión')&&str_contains($html,'Cuenta de egreso')&&str_contains($html,'Comisión aplicada'),'Falta el formulario o historial de comisiones.');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$token);preg_match('/name="creation_key" value="([a-f0-9]+)"/',$html,$creation);
    [$status]=commissionRequest('ventas/comisiones.php?id='.$invoice,$cookie,$paymentInput+['csrf'=>'wrong','creation_key'=>$creation[1],'action'=>'pay_commission']);commissionCheck($status===403,'No se protegió el pago con CSRF.');
    [$status,$body,$location]=commissionRequest('ventas/comisiones.php?id='.$invoice,$cookie,$paymentInput+['csrf'=>$token[1],'creation_key'=>$creation[1],'action'=>'pay_commission']);
    commissionCheck($status===303&&str_contains($location,'tesoreria.php?id='),'No se registró el egreso desde el asistente.');
    $collect('7.70');commissionCheck($commission()['commission_earned']==='4.00'&&$commission()['commission_pending']==='2.00','Cobro completo incorrecto.');
    $db->beginTransaction();$q=$db->prepare('SELECT id FROM sales_quotations WHERE id=? FOR UPDATE');$q->execute([$sale]);
    $workers=[];
    try {
        for($n=0;$n<2;$n++) {
            $process=proc_open([PHP_BINARY,__DIR__.'/commission-payment-worker.php',(string)$invoice,'2.00',(string)$f['account'],(string)$method,$code,$today],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            commissionCheck(is_resource($process),'No se pudo iniciar la prueba concurrente.');$workers[]=[$process,$pipes];
            commissionCheck(trim((string)fgets($pipes[1]))==='READY','No inició el trabajador concurrente.');
        }
        $db->commit();$accepted=[];$rejected=0;
        foreach($workers as [$process,$pipes]){fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);commissionCheck(proc_close($process)===0&&$errors==='','Falló el trabajador: '.$errors);if(preg_match('/PAID (\d+)/',$output,$match))$accepted[]=(int)$match[1];if(str_contains($output,'REJECTED'))$rejected++;}
        $workers=[];commissionCheck(count($accepted)===1&&$rejected===1,'Dos pagos concurrentes excedieron la comisión.');$last=$accepted[0];
    } finally {if($db->inTransaction())$db->rollBack();foreach($workers as [$process,$pipes]){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}}
    commissionCheck($commission()['commission_state']==='paid'&&$commission()['commission_paid']==='4.00','La comisión no terminó pagada.');
    commissionReject(fn()=>paySalesCommission($db,$invoice,$paymentInput,bin2hex(random_bytes(32))));
    $range=cashFlowRange([]);$flow=array_column(loadCashFlowSummary($db,$range,$f['account'])['currencies'],null,'code')[$code];
    commissionCheck($flow['income']==='15.40'&&$flow['expense']==='4.00'&&$flow['balance_end']==='11.40','Ingresos y egresos no incluyen el cobro completo y la comisión una vez.');
    $groups=summarizeSalesCommissions(loadSalesCommissions($db,$range,$f['salesperson']));
    commissionCheck($groups[0]['sales_count']===1&&$groups[0]['sales_amount']==='14.00'&&$groups[0]['commission_paid']==='4.00','El resumen incluye impuestos en la comisión o duplica ventas.');
    [$status]=commissionRequest('ventas/comisiones.php?seller_id=&period=today',$cookie);commissionCheck($status===200,'Seleccionar todos los vendedores no abre el listado.');
    [$status]=commissionRequest('ventas/comisiones.php?seller_id[]=1',$cookie);commissionCheck($status===404,'Se aceptó un filtro de vendedor inválido.');
    [, $body]=commissionRequest('dashboard/resumen.php',$cookie);$snapshot=json_decode($body,true,512,JSON_THROW_ON_ERROR);$found=array_values(array_filter($snapshot['commissions'],fn($r)=>$r['seller_id']===$f['salesperson']));
    commissionCheck($found[0]['commission_paid']==='4.00','El dashboard no recibe las comisiones actuales.');
    $reverse=reverseTreasuryDocument($db,$last,1,['reason'=>$prefix,'movement_date'=>$today]);
    commissionCheck($commission()['commission_pending']==='2.00'&&$commission()['commission_paid']==='2.00','La reversión no restauró el saldo de comisión.');
    [, $html]=commissionRequest('contabilidad/tesoreria.php?id='.$reverse,$cookie);commissionCheck(str_contains($html,'Comisión relacionada'),'La reversión perdió su enlace.');
    $alternatePay=paySalesCommission($db,$invoice,array_replace($paymentInput,['amount'=>'2.00','currency_code'=>$settings['alternate_code'],'account_id'=>(string)$f['alternate_account'],'exchange_rate'=>'100.00000000']),bin2hex(random_bytes(32)));
    $alternateDocument=loadTreasuryDocument($db,$alternatePay);
    commissionCheck($alternateDocument['amount']==='200.00'&&$commission()['commission_paid']==='4.00','Se mezclaron el importe de comisión y la moneda del egreso.');
    $alternateFlow=array_column(loadCashFlowSummary($db,$range,$f['alternate_account'])['currencies'],null,'code')[$settings['alternate_code']];
    commissionCheck($alternateFlow['expense']==='200.00'&&$alternateFlow['balance_end']==='800.00','El egreso convertido no aparece en su cuenta y moneda.');
    $q=$db->prepare('SELECT version FROM accounting_invoices WHERE id=?');$q->execute([$invoice]);$credit=createCreditNote($db,$invoice,(int)$q->fetchColumn(),$prefix);
    $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$credit]);$creditRecord=$q->fetch();
    $q=$db->prepare('SELECT id FROM accounting_invoice_lines WHERE invoice_id=?');$q->execute([$credit]);$creditLine=(int)$q->fetchColumn();
    $db->beginTransaction();saveCreditNoteQuantities($db,$creditRecord,[(string)$creditLine=>'1.000']);$db->commit();
    [, $html]=commissionRequest('contabilidad/index.php?type=customer&id='.$credit,$cookie);preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$token);
    [$status]=commissionRequest('contabilidad/index.php?type=customer&id='.$credit,$cookie,['csrf'=>$token[1],'version'=>'1','action'=>'confirm_invoice','invoice_date'=>$today]);commissionCheck($status===303,'No se registró la devolución.');
    commissionCheck($commission()['commission_generated']==='2.00'&&$commission()['commission_overpaid']==='2.00'&&$commission()['commission_state']==='adjustment','La devolución borró el pago o no mostró el ajuste.');
    [, $invoiceHtml]=commissionRequest('contabilidad/index.php?type=customer&id='.$invoice,$cookie);
    commissionInvoiceView($invoiceHtml,'4.00',$code,'15.40');
    $noSeller=invoiceCommissionSummary($db,['id'=>$invoice,'salesperson_id'=>null]);
    commissionCheck($noSeller['total']==='0.00'&&$noSeller['lines'][0]['commission_amount']==='0.00','Una factura sin vendedor muestra comisión.');
    installSalesCommissions($db);commissionCheck($commission()['commission_paid']==='4.00','Reinstalar alteró pagos existentes.');
    commissionCheck(salesCommissionAmount('3.000','4.00','5.00')==='0.00'&&salesCommissionAmount('1.125','7.00','5.00')==='2.25','Diferencias negativas o cantidades decimales incorrectas.');
    commissionCheck(bcadd(salesCommissionPortion('0.500','5.01','5.00','0.000'),salesCommissionPortion('0.500','5.01','5.00','0.500'),2)==='0.01','La facturación parcial duplicó un céntimo de comisión.');
    echo "PASS: referencia congelada, impuestos separados, cobros parciales, comisiones, pagos, CSRF, egresos, dashboard, reversiones y devoluciones.\n";
} finally {
    if($db->inTransaction())$db->rollBack();
    if(isset($f['salesperson'])) {
        $q=$db->prepare('SELECT id FROM accounting_treasury_documents WHERE contact_id=? ORDER BY id DESC');$q->execute([$f['salesperson']]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $db->prepare('DELETE FROM sales_commission_payments WHERE treasury_document_id=?')->execute([$id]);
            $db->prepare('DELETE FROM accounting_treasury_entries WHERE document_id=?')->execute([$id]);
            $db->prepare('DELETE FROM accounting_treasury_documents WHERE id=?')->execute([$id]);
        }
    }
    if(isset($f['client']))cleanupFixtureTreasury($db,[$f['client']],$prefix);
    if(isset($f['alternate_account'])){$q=$db->prepare('SELECT id FROM accounting_treasury_documents WHERE account_id=? ORDER BY id DESC');$q->execute([$f['alternate_account']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){$db->prepare('DELETE FROM accounting_treasury_entries WHERE document_id=?')->execute([$id]);$db->prepare('DELETE FROM accounting_treasury_documents WHERE id=?')->execute([$id]);}}
    foreach($orders as $order) {
        $q=$db->prepare('SELECT id FROM accounting_invoices WHERE sale_id=? ORDER BY id DESC');$q->execute([$order]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id) { foreach(['accounting_invoice_payments','accounting_invoice_reversals','accounting_invoice_lines'] as $table)$db->prepare('DELETE FROM '.$table.' WHERE invoice_id=?')->execute([$id]);$db->prepare('DELETE FROM accounting_invoices WHERE id=?')->execute([$id]); }
        $db->prepare('DELETE FROM sales_quotation_lines WHERE quotation_id=?')->execute([$order]);$db->prepare('DELETE FROM sales_quotations WHERE id=?')->execute([$order]);
    }
    if(isset($f['client']))$db->prepare('DELETE FROM accounting_payments WHERE contact_id=?')->execute([$f['client']]);
    foreach(['client','salesperson'] as $role)if(isset($f[$role]))$db->prepare('DELETE FROM contacts WHERE id=? AND name=?')->execute([$f[$role],$prefix.$role]);
    foreach(['product'=>'inventory_products','tax'=>'business_taxes','account'=>'accounting_payment_accounts','empty_account'=>'accounting_payment_accounts','alternate_account'=>'accounting_payment_accounts'] as $key=>$table)if(isset($f[$key]))$db->prepare('DELETE FROM '.$table.' WHERE id=? AND name=?')->execute([$f[$key],$prefix.match($key){'empty_account'=>' empty','alternate_account'=>' alternate',default=>''}]);
    if(is_file($cookie))unlink($cookie);
}
