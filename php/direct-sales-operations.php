<?php
declare(strict_types=1);
require_once __DIR__.'/direct-sales-repository.php';
require_once __DIR__.'/commission-repository.php';

function directDate(mixed $value): string {
    $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
    if(!$date||$date->format('Y-m-d')!==$value||$value<'1000-01-01'||$value>commerceToday())throw new InvalidArgumentException('Selecciona una fecha válida, hasta hoy.');
    return $value;
}
function directSellerLocations(PDO $db,int $warehouse): array {
    $q=$db->prepare("SELECT id FROM inventory_locations WHERE warehouse_id=? AND company_id=? AND location_type='internal' ORDER BY id LIMIT 2");$q->execute([$warehouse,companyActiveId()]);return $q->fetchAll();
}
function directText(mixed $value,int $max,bool $required=false): string {
    if(!is_string($value)||mb_strlen(trim($value))>$max||($required&&trim($value)===''))throw new InvalidArgumentException('Escribe una observación válida, de hasta '.$max.' caracteres.');return trim($value);
}
function directSaleRecord(PDO $db,int $id,string $action='read',bool $lock=false): array {
    permissionRequire('venta_directa.sales',$action,$id);
    if($lock){$q=$db->prepare('SELECT sale_id FROM direct_sale_checkouts WHERE id=? AND company_id=?');$q->execute([$id,companyActiveId()]);$order=$q->fetchColumn();if(!$order)throw new InvalidArgumentException('Venta no disponible.',403);workflowLockOrder($db,['quotation_id'=>(int)$order]);}
    $q=$db->prepare('SELECT d.*,i.version AS invoice_version,i.status AS invoice_status,i.contact_id,i.currency_code,i.invoice_date,i.due_date,i.total_amount,i.payment_state FROM direct_sale_checkouts d JOIN accounting_invoices i ON i.id=d.invoice_id WHERE d.id=? AND d.company_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id,companyActiveId()]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('La venta no está disponible en la empresa activa.',403);return $row;
}
function directCredit(PDO $db,int $customer): array {
    $settings=accountingCurrencies($db);$primary=$settings['primary_code'];
    $q=$db->prepare('SELECT * FROM direct_customer_credit WHERE contact_id=? AND company_id=?');$q->execute([$customer,companyActiveId()]);$policy=$q->fetch();
    $limit=$policy&&$policy['credit_limit']!==null?accountingConvert($policy['credit_limit'],$policy['currency_code'],$primary,$settings):null;
    $debt='0.00';$overdue='0.00';
    $q=$db->prepare("SELECT id,total_amount,currency_code,due_date FROM accounting_invoices WHERE contact_id=? AND company_id=? AND invoice_type='customer' AND document_type='invoice' AND status='posted'");$q->execute([$customer,companyActiveId()]);
    foreach($q->fetchAll() as $invoice){$details=invoicePaymentDetails($db,(int)$invoice['id']);$balance=bcsub(bcsub($invoice['total_amount'],$details['credited'],2),$details['settled'],2);if(bccomp($balance,'0',2)<=0)continue;
        $balance=accountingConvert($balance,$invoice['currency_code'],$primary,$settings);$debt=bcadd($debt,$balance,2);
        if($invoice['due_date']&&$invoice['due_date']<commerceToday())$overdue=bcadd($overdue,$balance,2);
    }
    return ['debt'=>$debt,'overdue'=>$overdue,'limit'=>$limit,'currency'=>$primary,'policy'=>$policy['policy']??'warn'];
}
function directCreditWarnings(array $credit,string $newAmount): array {
    $warnings=[];if(bccomp($credit['overdue'],'0',2)>0)$warnings[]='El cliente tiene '.$credit['overdue'].' '.$credit['currency'].' vencidos.';
    if($credit['limit']!==null&&bccomp(bcadd($credit['debt'],$newAmount,2),$credit['limit'],2)>0)$warnings[]='Esta venta supera el límite de crédito de '.$credit['limit'].' '.$credit['currency'].'.';return $warnings;
}
function directSaveCredit(CompanyConnection $db,array $input): void {
    permissionRequire('venta_directa.configuration','configure');$id=accessId($input['contact_id']??null);$mode=$input['policy']??'warn';
    if(!in_array($mode,['warn','block'],true))throw new InvalidArgumentException('Selecciona una política válida.');
    $limit=($input['credit_limit']??'')===''?null:salesDecimal($input['credit_limit'],14,2);if(($input['credit_limit']??'')!==''&&$limit===null)throw new InvalidArgumentException('Indica un límite de crédito válido o déjalo vacío.');
    $db->atomicTransfer(function()use($db,$id,$mode,$limit,$input){
        $q=$db->prepare("SELECT c.id FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.id=? AND c.company_id=? AND t.system_key='client' FOR UPDATE");$q->execute([$id,companyActiveId()]);if(!$q->fetchColumn())throw new InvalidArgumentException('Selecciona un cliente de esta empresa.');
        $q=$db->prepare('SELECT version FROM direct_customer_credit WHERE contact_id=? FOR UPDATE');$q->execute([$id]);$version=$q->fetchColumn();
        if((int)($version?:0)!==(int)($input['version']??0))throw new InvalidArgumentException('La configuración cambió. Recarga.',409);
        $currency=accountingCurrencies($db)['primary_code'];
        $db->prepare('INSERT INTO direct_customer_credit(contact_id,credit_limit,currency_code,policy) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE credit_limit=VALUES(credit_limit),currency_code=VALUES(currency_code),policy=VALUES(policy),version=version+1')->execute([$id,$limit,$currency,$mode]);
    });
}
function directRegisterPayment(CompanyConnection $db,int $saleId,array $input,array $files): int {
    permissionRequire('venta_directa.sales','register_payment',$saleId);$key=directKey($input['request_key']??null);
    // Only explicit payment fields enter the native accounting routine.
    $data=[];foreach(['amount','payment_date','reference','currency_code','account_id','method_id','exchange_rate'] as $field){$value=$input[$field]??'';if(!is_string($value))throw new InvalidArgumentException('El cobro no es válido.');$data[$field]=$value;}
    directDate($data['payment_date']);
    if($data['exchange_rate']==='')unset($data['exchange_rate']);
    $hash=hash('sha256',json_encode([$saleId,$data],JSON_THROW_ON_ERROR));
    $proofs=new PaymentProofUpload($files);
    $result=$db->atomicTransfer(function()use($db,$saleId,$input,$key,$data,$hash,$proofs){
        $sale=directSaleRecord($db,$saleId,'register_payment',true);
        $q=$db->prepare('SELECT id,payload_hash FROM direct_sale_actions WHERE request_key=? FOR UPDATE');$q->execute([$key]);
        if($old=$q->fetch()){if(!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('Esta clave ya se utilizó para otro cobro.',409);return (int)$old['id'];}
        permissionRequire('contabilidad.customer_invoices','register_payment',(int)$sale['invoice_id']);
        // Keep every selected catalog inside the active company, even with multiple companies selected.
        foreach(['account_id'=>'accounting_payment_accounts','method_id'=>'accounting_payment_methods'] as $field=>$table){$q=$db->prepare('SELECT id FROM '.$table.' WHERE id=? AND company_id=?');$q->execute([accessId($data[$field]),companyActiveId()]);if(!$q->fetchColumn())throw new InvalidArgumentException('Selecciona una cuenta y un medio de pago de la empresa activa.');}
        $details=invoicePaymentDetails($db,(int)$sale['invoice_id']);$remaining=bcsub(bcsub(bcsub($sale['total_amount'],$details['credited'],2),$details['settled'],2),$details['pending'],2);
        $normalized=validateAccountingPayment($db,$data+['contact_id'=>$sale['contact_id']],null,$sale+['invoice_type'=>'customer','document_type'=>'invoice']);
        if(bccomp(accountingConvert($normalized['amount'],$normalized['currency_code'],$sale['currency_code'],$normalized),$remaining,2)>0)throw new InvalidArgumentException('El cobro supera el saldo por cubrir. Puedes registrar un cobro parcial.');
        $q=$db->prepare('SELECT COALESCE(MAX(id),0) FROM accounting_invoice_payments WHERE invoice_id=?');$q->execute([$sale['invoice_id']]);$previous=(int)$q->fetchColumn();
        transitionInvoiceStatus($db,(int)$sale['invoice_id'],accessId($input['version']??null),'register_payment',$data);
        $q=$db->prepare('SELECT payment_id FROM accounting_invoice_payments WHERE invoice_id=? AND id>? ORDER BY id DESC LIMIT 1');$q->execute([$sale['invoice_id'],$previous]);$payment=$q->fetchColumn();if(!$payment)throw new RuntimeException('No se vinculó el cobro.');
        $proofs->attach($db,'payment',(int)$payment);
        $db->prepare("INSERT INTO direct_sale_actions(sale_id,seller_id,action_kind,request_key,payload_hash,status,payload_json,payment_id) VALUES(?,?,'payment',?,?,'done',?,?)")->execute([$saleId,$sale['seller_id'],$key,$hash,json_encode($data,JSON_THROW_ON_ERROR),$payment]);return (int)$db->lastInsertId();
    });
    $proofs->commit();return $result;
}
function directDispatchRequest(CompanyConnection $db,int $id,array $input): int {
    permissionRequire('venta_directa.requests','validate',$id);permissionRequire('venta_directa.stock');
    if(permissionScope('venta_directa.requests','validate')!=='all')throw new InvalidArgumentException('La entrega requiere un supervisor.',403);
    return $db->atomicTransfer(function()use($db,$id,$input){
        $q=$db->prepare('SELECT * FROM direct_stock_requests WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$id,companyActiveId()]);$request=$q->fetch();
        if(!$request)throw new InvalidArgumentException('La solicitud no está disponible.');if($request['status']==='transferred')return (int)$request['operation_id'];
        if($request['status']!=='approved'||(int)$request['version']!==(int)($input['version']??0))throw new InvalidArgumentException('Aprueba primero la solicitud o recarga su estado.',409);
        $seller=directSeller($db,(string)$request['seller_id'],'venta_directa.requests','validate',true);
        if((int)$seller['location_id']!==(int)$request['seller_location_id'])throw new InvalidArgumentException('La ubicación del vendedor cambió; cancela y crea otra solicitud.');
        $central=(int)$request['source_location_id'];if(!in_array($central,array_map('intval',array_column(directCentralLocations($db),'id')),true))throw new InvalidArgumentException('El almacén central ya no está disponible.');
        $source=$request['request_kind']==='return'?(int)$seller['location_id']:$central;$destination=$request['request_kind']==='return'?$central:(int)$seller['location_id'];
        $q=$db->prepare('SELECT * FROM direct_stock_request_lines WHERE request_id=? AND approved_quantity>0 ORDER BY product_id');$q->execute([$id]);$lines=[];
        foreach($q->fetchAll() as $line)$lines[]=['product_id'=>$line['product_id'],'quantity'=>$line['approved_quantity'],'demand'=>$line['approved_quantity']];
        if(!$lines)throw new InvalidArgumentException('La solicitud no tiene cantidades aprobadas.');
        [$data,$errors]=validateInventoryOperation(['scheduled_date'=>commerceToday(),'source_location_id'=>(string)$source,'destination_location_id'=>(string)$destination,'notes'=>($request['request_kind']==='return'?'Retorno':'Abastecimiento').' de vendedor #'.$id],'internal',loadStorageRelations($db),[]);if($errors)throw new InvalidArgumentException(implode(' ',$errors));
        $operation=saveInventoryOperation($db,$data,null,null,$lines);directCompleteOperation($db,$operation);
        $db->prepare("UPDATE direct_stock_requests SET status='transferred',version=version+1,source_location_id=?,destination_location_id=?,operation_id=?,fulfilled_by=?,fulfilled_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$source,$destination,$operation,permissionUser()['id'],$id]);return $operation;
    });
}
function directRequestCustomerReturn(CompanyConnection $db,array $input): int {
    permissionRequire('venta_directa.returns','create');$saleId=accessId($input['sale_id']??null);$sale=directSaleRecord($db,$saleId??0);
    if(permissionRuntime()&&!in_array((int)$sale['seller_id'],permissionContacts(),true))throw new InvalidArgumentException('Solo puedes solicitar devoluciones de tus ventas.',403);
    $key=directKey($input['request_key']??null);$reason=directText($input['reason']??'',255,true);$quantities=$input['quantities']??null;
    if(!is_array($quantities)||count($quantities)>100)throw new InvalidArgumentException('Revisa las cantidades a devolver.');
    $lines=[];foreach($quantities as $id=>$qty){$lineId=accessId((string)$id);$qty=inventoryQuantity($qty);if(!$lineId||$qty===null||bccomp($qty,'0',2)<0)throw new InvalidArgumentException('Revisa las cantidades a devolver.');if(bccomp($qty,'0',2)>0)$lines[$lineId]=$qty;}
    if(!$lines)throw new InvalidArgumentException('Selecciona al menos una cantidad a devolver.');ksort($lines);$payload=['reason'=>$reason,'lines'=>$lines];$hash=hash('sha256',json_encode([$saleId,$payload],JSON_THROW_ON_ERROR));
    return $db->atomicTransfer(function()use($db,$sale,$saleId,$key,$hash,$payload){
        $q=$db->prepare('SELECT id FROM direct_sale_checkouts WHERE id=? FOR UPDATE');$q->execute([$saleId]);
        $q=$db->prepare('SELECT id,payload_hash FROM direct_sale_actions WHERE request_key=? FOR UPDATE');$q->execute([$key]);if($old=$q->fetch()){if(!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('La solicitud ya se envió con otros datos.',409);return (int)$old['id'];}
        $q=$db->prepare("SELECT COUNT(*) FROM direct_sale_actions WHERE sale_id=? AND action_kind='return' AND status='pending'");$q->execute([$saleId]);if($q->fetchColumn())throw new InvalidArgumentException('Esta venta ya tiene una devolución pendiente.');
        foreach($payload['lines'] as $id=>$qty){$q=$db->prepare("SELECT l.quantity-COALESCE((SELECT SUM(c.quantity) FROM accounting_invoice_lines c JOIN accounting_invoices i ON i.id=c.invoice_id WHERE c.original_line_id=l.id AND i.status<>'cancel'),0) AS quantity,p.tracking_mode FROM accounting_invoice_lines l JOIN inventory_products p ON p.id=l.product_id WHERE l.id=? AND l.invoice_id=?");$q->execute([$id,$sale['invoice_id']]);$line=$q->fetch();if(!$line||bccomp($qty,$line['quantity'],2)>0||($line['tracking_mode']==='serial'&&bccomp($qty,bcdiv($qty,'1',0),2)!==0))throw new InvalidArgumentException('La cantidad supera lo pendiente de devolver o no es válida para el producto.');}
        $db->prepare("INSERT INTO direct_sale_actions(sale_id,seller_id,action_kind,request_key,payload_hash,payload_json) VALUES(?,?,'return',?,?,?)")->execute([$saleId,$sale['seller_id'],$key,$hash,json_encode($payload,JSON_THROW_ON_ERROR)]);return (int)$db->lastInsertId();
    });
}
function directResolveCustomerReturn(CompanyConnection $db,int $id,array $input,bool $approve): void {
    permissionRequire('venta_directa.returns',$approve?'confirm':'cancel',$id);
    if(permissionScope('venta_directa.returns',$approve?'confirm':'cancel')!=='all')throw new InvalidArgumentException('La devolución requiere revisión del supervisor.',403);
    $db->atomicTransfer(function()use($db,$id,$input,$approve){
        $q=$db->prepare("SELECT * FROM direct_sale_actions WHERE id=? AND company_id=? AND action_kind='return' FOR UPDATE");$q->execute([$id,companyActiveId()]);$request=$q->fetch();if(!$request)throw new InvalidArgumentException('La devolución no está disponible.');if($request['status']==='done'&&$approve)return;
        if($request['status']!=='pending')throw new InvalidArgumentException('La devolución ya fue resuelta.',409);
        $note=directText($input['decision_note']??'',1000,!$approve);$operation=null;$credit=null;
        if($approve){
            $q=$db->prepare('SELECT * FROM direct_sale_checkouts WHERE id=?');$q->execute([$request['sale_id']]);$sale=$q->fetch();
            workflowLockOrder($db,['quotation_id'=>$sale['sale_id']]);
            $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=? FOR UPDATE');$q->execute([$sale['invoice_id']]);$invoice=$q->fetch();
            $payload=json_decode($request['payload_json'],true,8,JSON_THROW_ON_ERROR);
            // Native routines enforce remaining quantities, active payments and order locks.
            $q=$db->prepare("SELECT COUNT(*) FROM accounting_invoices WHERE reversal_of_id=? AND status='draft'");$q->execute([$invoice['id']]);if($q->fetchColumn())throw new InvalidArgumentException('Resuelve la nota de crédito en borrador existente antes de aprobar.');
            $credit=createCreditNote($db,(int)$invoice['id'],(int)$invoice['version'],$payload['reason']);
            $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$credit]);$creditInvoice=$q->fetch();$q=$db->prepare('SELECT id,original_line_id FROM accounting_invoice_lines WHERE invoice_id=?');$q->execute([$credit]);$creditQty=[];
            foreach($q->fetchAll() as $line)$creditQty[$line['id']]=$payload['lines'][$line['original_line_id']]??'0';saveCreditNoteQuantities($db,$creditInvoice,$creditQty);
            $q=$db->prepare('SELECT * FROM inventory_operations WHERE id=? FOR UPDATE');$q->execute([$sale['operation_id']]);$delivery=$q->fetch();
            $q=$db->prepare("SELECT COUNT(*) FROM inventory_operations WHERE return_of_id=? AND status IN ('draft','waiting','ready')");$q->execute([$delivery['id']]);if($q->fetchColumn())throw new InvalidArgumentException('Resuelve la devolución de inventario pendiente antes de continuar.');
            $operation=createStockReturn($db,(int)$delivery['id'],(int)$delivery['version']);
            $invoiceLines=$db->prepare('SELECT id,sale_line_id FROM accounting_invoice_lines WHERE invoice_id=?');$invoiceLines->execute([$invoice['id']]);$bySale=[];
            foreach($invoiceLines->fetchAll() as $l)$bySale[$l['sale_line_id']]=$payload['lines'][$l['id']]??'0.00';
            foreach(loadInventoryOperationLines($db,$operation) as $line){$qty=$bySale[$line['sale_line_id']]??'0.00';
                if(bccomp($qty,'0',2)<=0){$db->prepare('DELETE FROM inventory_operation_lines WHERE id=?')->execute([$line['id']]);continue;}
                if(bccomp($qty,$line['demand'],2)>0)throw new InvalidArgumentException('La devolución supera la mercancía pendiente de devolver.');
                $db->prepare('UPDATE inventory_operation_lines SET demand=? WHERE id=?')->execute([$qty,$line['id']]);
                $product=trackingProduct($db,(int)$line['product_id']);if($product['tracking_mode']!=='none'){
                    $q=$db->prepare("SELECT a.lot_id,a.quantity-COALESCE((SELECT SUM(r.quantity) FROM inventory_lot_lines r JOIN inventory_operation_lines rl ON rl.id=r.line_id JOIN inventory_operations ro ON ro.id=rl.operation_id WHERE rl.return_line_id=? AND r.lot_id=a.lot_id AND ro.status='done'),0) AS remaining FROM inventory_lot_lines a WHERE a.line_id=? ORDER BY a.id");$q->execute([$line['return_line_id'],$line['return_line_id']]);$left=$qty;
                    foreach($q->fetchAll() as $lot){if(bccomp($lot['remaining'],'0',2)<=0||bccomp($left,'0',2)<=0)continue;$take=bccomp($left,$lot['remaining'],2)<0?$left:$lot['remaining'];$db->prepare('INSERT INTO inventory_lot_lines(line_id,lot_id,quantity) VALUES(?,?,?)')->execute([$line['id'],$lot['lot_id'],$take]);$left=bcsub($left,$take,2);}
                    if(bccomp($left,'0',2)>0)throw new InvalidArgumentException('No queda seguimiento suficiente de la entrega original.');
                }
            }
            directCompleteOperation($db,$operation);
            $db->prepare("UPDATE accounting_invoices SET status='posted',version=version+1 WHERE id=?")->execute([$credit]);$q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$invoice['id']]);invoiceRecomputePaymentState($db,$q->fetch());$q->execute([$credit]);invoiceRecomputePaymentState($db,$q->fetch());
        }
        $db->prepare('UPDATE direct_sale_actions SET status=?,operation_id=?,credit_id=?,decision_note=?,decided_by=?,decided_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$approve?'done':'rejected',$operation,$credit,$note,permissionUser()['id'],$id]);
    });
}

function directDaySummary(PDO $db,int $seller,string $date): array {
    $q=$db->prepare("SELECT c.id,c.name FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.id=? AND c.company_id=? AND t.system_key='salesperson'");$q->execute([$seller,companyActiveId()]);if(!$q->fetch())throw new InvalidArgumentException('Selecciona un vendedor de esta empresa.');
    $summary=['date'=>$date,'seller_id'=>$seller,'money'=>[],'stock'=>[],'sales'=>[],'payments'=>[]];
    foreach(accountingCurrencyCodes(accountingCurrencies($db)) as $code)$summary['money'][$code]=['registered'=>'0.00','settled'=>'0.00','pending'=>'0.00','refunds'=>'0.00','cash'=>'0.00'];
    $q=$db->prepare("SELECT i.id,i.name,i.currency_code,i.total_amount,i.due_date,i.contact_id FROM direct_sale_checkouts d JOIN accounting_invoices i ON i.id=d.invoice_id WHERE d.seller_id=? AND d.company_id=? AND i.invoice_date=? AND i.status='posted'");$q->execute([$seller,companyActiveId(),$date]);
    foreach($q->fetchAll() as $invoice){$details=invoicePaymentDetails($db,(int)$invoice['id']);$invoice['balance']=bcsub(bcsub($invoice['total_amount'],$details['credited'],2),$details['settled'],2);$invoice['pending']=$details['pending'];$summary['sales'][]=$invoice;}
    $q=$db->prepare("SELECT p.id,p.name,p.payment_date,p.currency_code,p.status,p.direction,p.created_by,p.account_id,a.name AS account_name,a.account_type,m.name AS method_name,SUM(l.payment_amount) AS amount
        FROM accounting_invoice_payments l JOIN accounting_payments p ON p.id=l.payment_id JOIN accounting_invoices i ON i.id=l.invoice_id
        JOIN direct_sale_checkouts d ON d.invoice_id=CASE WHEN i.document_type='credit' THEN i.reversal_of_id ELSE i.id END JOIN accounting_payment_accounts a ON a.id=p.account_id JOIN accounting_payment_methods m ON m.id=p.method_id
        WHERE d.seller_id=? AND d.company_id=? AND p.payment_date=? AND l.status IN ('pending','settled') AND p.status IN ('pending','settled')
        GROUP BY p.id,p.name,p.payment_date,p.currency_code,p.status,p.direction,p.created_by,p.account_id,a.name,a.account_type,m.name ORDER BY p.id");$q->execute([$seller,companyActiveId(),$date]);$summary['payments']=$q->fetchAll();
    $q=$db->prepare('SELECT u.id FROM auth_users u LEFT JOIN company_user_access a ON a.user_id=u.id AND a.company_id=? WHERE a.contact_id=? OR u.contact_id=?');$q->execute([companyActiveId(),$seller,$seller]);$collectors=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $q=$db->prepare("SELECT payment_id FROM direct_sale_actions WHERE seller_id=? AND company_id=? AND action_kind='payment' AND status='done'");$q->execute([$seller,companyActiveId()]);$ownPayments=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    foreach($summary['payments'] as $p){$code=$p['currency_code'];$summary['money'][$code]??=['registered'=>'0.00','settled'=>'0.00','pending'=>'0.00','refunds'=>'0.00','cash'=>'0.00'];
        $own=in_array((int)$p['created_by'],$collectors,true)||in_array((int)$p['id'],$ownPayments,true);
        if($p['direction']==='outbound'){$summary['money'][$code]['refunds']=bcadd($summary['money'][$code]['refunds'],$p['amount'],2);if($own&&$p['account_type']==='cash')$summary['money'][$code]['cash']=bcsub($summary['money'][$code]['cash'],$p['amount'],2);continue;}
        $summary['money'][$code]['registered']=bcadd($summary['money'][$code]['registered'],$p['amount'],2);$field=$p['status']==='settled'?'settled':'pending';$summary['money'][$code][$field]=bcadd($summary['money'][$code][$field],$p['amount'],2);if($own&&$p['account_type']==='cash')$summary['money'][$code]['cash']=bcadd($summary['money'][$code]['cash'],$p['amount'],2);
    }
    // Movement history is tied to seller snapshots, not to the seller's current warehouse.
    $q=$db->prepare("SELECT r.operation_id,r.request_kind FROM direct_stock_requests r JOIN inventory_operations o ON o.id=r.operation_id WHERE r.seller_id=? AND r.company_id=? AND o.status='done' AND o.scheduled_date=?");$q->execute([$seller,companyActiveId(),$date]);$movements=$q->fetchAll();
    foreach($movements as $move){foreach(loadInventoryOperationLines($db,(int)$move['operation_id']) as $line){$id=$line['product_id'];$p=trackingProduct($db,(int)$id);$summary['stock'][$id]??=['name'=>$p['name'],'received'=>'0.00','returned'=>'0.00','sold'=>'0.00','client_return'=>'0.00'];$field=$move['request_kind']==='return'?'returned':'received';$summary['stock'][$id][$field]=bcadd($summary['stock'][$id][$field],$line['quantity'],2);}}
    $q=$db->prepare("SELECT l.product_id,p.name,o.return_of_id,SUM(l.quantity) AS quantity FROM inventory_operations o JOIN inventory_operation_lines l ON l.operation_id=o.id JOIN inventory_products p ON p.id=l.product_id JOIN sales_quotations s ON s.id=o.quotation_id JOIN direct_sale_checkouts d ON d.sale_id=s.id WHERE d.seller_id=? AND d.company_id=? AND o.status='done' AND o.scheduled_date=? GROUP BY l.product_id,p.name,o.return_of_id");$q->execute([$seller,companyActiveId(),$date]);
    foreach($q->fetchAll() as $line){$id=$line['product_id'];$summary['stock'][$id]??=['name'=>$line['name'],'received'=>'0.00','returned'=>'0.00','sold'=>'0.00','client_return'=>'0.00'];$field=$line['return_of_id']?'client_return':'sold';$summary['stock'][$id][$field]=bcadd($summary['stock'][$id][$field],$line['quantity'],2);}
    $summary['commissions']=[];
    foreach($summary['sales'] as $invoice){$generated='0.00';$q=invoiceCommissionLinesQuery($db);$q->execute([$invoice['id']]);foreach($q->fetchAll() as $line){$qty=bcsub($line['quantity'],$line['returned_quantity'],2);if(bccomp($qty,'0',2)<0)$qty='0.00';$generated=bcadd($generated,salesCommissionPortion($qty,$line['unit_price'],$line['commission_reference_price'],$line['prior_quantity'],$line['commission_unit_amount']),2);}
        $details=invoicePaymentDetails($db,(int)$invoice['id']);$net=bcsub($invoice['total_amount'],$details['credited'],2);$collected=bccomp($details['settled'],$net,2)>0?$net:$details['settled'];$earned=bccomp($net,'0',2)>0?bcdiv(bcadd(bcdiv(bcmul($generated,$collected,8),$net,8),'0.005',8),'1',2):'0.00';
        $summary['commissions'][]=['invoice'=>$invoice['name'],'currency'=>$invoice['currency_code'],'generated'=>$generated,'earned'=>$earned];
    }
    $q=$db->prepare('SELECT warehouse_id,location_id FROM contacts WHERE id=?');$q->execute([$seller]);$storage=$q->fetch();$location=(int)($storage['location_id']??0);
    if(!$location&&$storage['warehouse_id']){foreach(directSellerLocations($db,(int)$storage['warehouse_id']) as $l){if($location){$location=0;break;}$location=(int)$l['id'];}}
    $summary['inventory_at_submission']=$location?directSupplyStock($db,$location):[];$summary['captured_at']=date('c');
    if($location){
        $summary['stock']=[];$q=$db->prepare("SELECT m.product_id,p.name,o.operation_type,o.return_of_id,m.quantity FROM inventory_stock_moves m JOIN inventory_operations o ON o.id=m.operation_id JOIN inventory_products p ON p.id=m.product_id WHERE m.company_id=? AND m.location_id=? AND DATE(m.created_at)=? ORDER BY m.id");$q->execute([companyActiveId(),$location,$date]);
        foreach($q->fetchAll() as $move){$id=$move['product_id'];$summary['stock'][$id]??=['name'=>$move['name'],'received'=>'0.00','returned'=>'0.00','sold'=>'0.00','client_return'=>'0.00'];$positive=bccomp($move['quantity'],'0',2)>0;$qty=$positive?$move['quantity']:bcsub('0',$move['quantity'],2);$field=$move['operation_type']==='delivery'?($positive?'client_return':'sold'):($positive?'received':'returned');$summary['stock'][$id][$field]=bcadd($summary['stock'][$id][$field],$qty,2);}
    }
    return $summary;
}
function directSubmitClosing(CompanyConnection $db,array $input): int {
    permissionRequire('venta_directa.closing','create');$seller=directSeller($db,$input['seller_id']??null,'venta_directa.closing','create');$date=directDate($input['closing_date']??null);$notes=directText($input['notes']??'',1000);
    return $db->atomicTransfer(function()use($db,$seller,$date,$notes,$input){
        directSeller($db,(string)$seller['id'],'venta_directa.closing','create',true);
        $q=$db->prepare('SELECT id FROM direct_day_closings WHERE seller_id=? AND closing_date=? FOR UPDATE');$q->execute([$seller['id'],$date]);if($id=$q->fetchColumn())return (int)$id;
        $summary=directDaySummary($db,(int)$seller['id'],$date);$declared=[];$values=$input['declared']??[];if(!is_array($values))throw new InvalidArgumentException('Revisa el efectivo declarado.');
        foreach($summary['money'] as $currency=>$totals){$cash=salesDecimal($values[$currency]??'0',16,2);if($cash===null)throw new InvalidArgumentException('Indica importes de efectivo válidos.');$declared[$currency]=['cash'=>$cash,'expected'=>$totals['cash'],'difference'=>bcsub($cash,$totals['cash'],2)];}
        $db->prepare('INSERT INTO direct_day_closings(seller_id,closing_date,snapshot_json,declared_json,notes) VALUES(?,?,?,?,?)')->execute([$seller['id'],$date,json_encode($summary,JSON_THROW_ON_ERROR),json_encode($declared,JSON_THROW_ON_ERROR),$notes]);return (int)$db->lastInsertId();
    });
}
function directValidateClosing(CompanyConnection $db,int $id): void {
    permissionRequire('venta_directa.closing','confirm',$id);if(permissionScope('venta_directa.closing','confirm')!=='all')throw new InvalidArgumentException('El cierre requiere un supervisor.',403);
    $db->atomicTransfer(function()use($db,$id){$q=$db->prepare('SELECT status FROM direct_day_closings WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$id,companyActiveId()]);$status=$q->fetchColumn();if(!$status)throw new InvalidArgumentException('El cierre no está disponible.');if($status==='validated')return;$db->prepare("UPDATE direct_day_closings SET status='validated',validated_by=?,validated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([permissionUser()['id'],$id]);});
}
