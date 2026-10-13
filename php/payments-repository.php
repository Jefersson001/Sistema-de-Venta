<?php
declare(strict_types=1);
require_once __DIR__.'/invoice-status-repository.php';
require_once __DIR__.'/currency-repository.php';

function paymentStatuses(): array {return ['draft'=>'Borrador','pending'=>'En proceso de pago','settled'=>'Pagado','cancel'=>'Cancelado'];}
function loadAccountingPayment(PDO $db,int $id): ?array
{
    $q=$db->prepare('SELECT p.*,c.name AS contact_name,a.name AS account_name,m.name AS method_name FROM accounting_payments p JOIN contacts c ON c.id=p.contact_id JOIN accounting_payment_accounts a ON a.id=p.account_id JOIN accounting_payment_methods m ON m.id=p.method_id WHERE p.id=?');$q->execute([$id]);$p=$q->fetch();if(!$p)return null;
    $q=$db->prepare('SELECT l.*,i.name AS invoice_name,i.invoice_type,i.currency_code,i.document_type FROM accounting_invoice_payments l JOIN accounting_invoices i ON i.id=l.invoice_id WHERE l.payment_id=? ORDER BY l.id');$q->execute([$id]);$p['allocations']=$q->fetchAll();$p['allocated']='0.00';
    foreach($p['allocations'] as $l)if($l['status']!=='cancel')$p['allocated']=bcadd($p['allocated'],$l['payment_amount'],2);
    $p['available']=$p['status']==='cancel'?'0.00':bcsub($p['amount'],$p['allocated'],2);return $p;
}
function validateAccountingPayment(PDO $db,array $input,?array $existing=null,?array $invoice=null): array
{
    $settings=accountingCurrencies($db);$type=$input['partner_type']??$existing['partner_type']??'customer';$direction=$input['direction']??$existing['direction']??($type==='customer'?'inbound':'outbound');
    if(!in_array($type,['customer','supplier'],true)||!in_array($direction,['inbound','outbound'],true))throw new InvalidArgumentException('Selecciona el tipo de contacto y la dirección del pago.');
    $contact=salesId($input['contact_id']??$existing['contact_id']??null);$q=$db->prepare('SELECT c.active,t.system_key FROM contacts c JOIN contact_types t ON t.id=c.type_id WHERE c.id=?');$q->execute([$contact]);$c=$q->fetch();
    $historical=(int)($existing['contact_id']??0)===$contact || ((int)($invoice['contact_id']??0)===$contact && ($invoice['invoice_type']??null)===$type);
    if(!$c||(!$historical&&($c['system_key']!==($type==='customer'?'client':'supplier')||!$c['active'])))throw new InvalidArgumentException('Selecciona un cliente o proveedor válido para el pago.');
    $amount=salesDecimal($input['amount']??'',16,2);if(!$amount||bccomp($amount,'0',2)<=0)throw new InvalidArgumentException('El importe del pago debe ser positivo, con hasta dos decimales.');
    $date=$input['payment_date']??'';$parsed=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
    if(!$parsed||$parsed->format('Y-m-d')!==$date||$date<'1000-01-01'||$date>'9999-12-31')throw new InvalidArgumentException('Selecciona una fecha de pago válida.');
    $reference=is_string($input['reference']??null)?trim($input['reference']):'';if(mb_strlen($reference)>160)throw new InvalidArgumentException('La referencia admite hasta 160 caracteres.');
    $currency=$input['currency_code']??$existing['currency_code']??$invoice['currency_code']??$settings['primary_code'];
    if(!is_string($currency)||!in_array($currency,array_merge(accountingCurrencyCodes($settings),[$existing['currency_code']??null,$invoice['currency_code']??null]),true))throw new InvalidArgumentException('Selecciona una moneda configurada.');
    $account=salesId($input['account_id']??null);
    if(!$account)throw new InvalidArgumentException('Selecciona una cuenta de '.($direction==='inbound'?'ingreso':'egreso').' válida.');
    if(isset($input['method_id'])&&!salesId($input['method_id']))throw new InvalidArgumentException('Selecciona un medio de pago válido.');
    $method=salesId($input['method_id']??null)??(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    $q=$db->prepare('SELECT currency_code FROM accounting_payment_accounts WHERE id=?');$q->execute([$account]);$a=$q->fetch();
    if(!$a)throw new InvalidArgumentException('La cuenta seleccionada no existe.');
    if($a['currency_code']&&$a['currency_code']!==$currency)throw new InvalidArgumentException('La cuenta seleccionada debe admitir la moneda del pago.');
    $q=$db->prepare('SELECT id FROM accounting_payment_methods WHERE id=?');$q->execute([$method]);if(!$q->fetchColumn())throw new InvalidArgumentException('Selecciona un medio de pago válido.');
    $pair=$existing?['primary_code'=>$existing['primary_code'],'alternate_code'=>$existing['alternate_code'],'exchange_rate'=>$existing['exchange_rate']]:$settings;
    $rate=accountingExchangeRate($input['exchange_rate']??$pair['exchange_rate']);
    if($currency!==$pair['primary_code']&&$currency===$pair['alternate_code']&&!$rate)throw new InvalidArgumentException('Indica la tasa de cambio utilizada para registrar el pago en moneda alterna.');
    return ['partner_type'=>$type,'direction'=>$direction,'contact_id'=>$contact,'amount'=>$amount,'currency_code'=>$currency,'primary_code'=>$pair['primary_code'],'alternate_code'=>$pair['alternate_code'],'exchange_rate'=>$rate,'payment_date'=>$date,'reference'=>$reference,'account_id'=>$account,'method_id'=>$method];
}
function insertAccountingPayment(PDO $db,array $data,string $status='draft',?string $key=null): int
{
    $data['status']=$status;$data['creation_key']=$key;
    $q=$db->prepare('INSERT INTO accounting_payments('.implode(',',array_keys($data)).') VALUES (:'.implode(',:',array_keys($data)).')');$q->execute($data);$id=(int)$db->lastInsertId();
    $db->prepare('UPDATE accounting_payments SET name=? WHERE id=?')->execute(['PAG'.str_pad((string)$id,5,'0',STR_PAD_LEFT),$id]);return $id;
}
function saveAccountingPayment(PDO $db,array $input,?int $id,?int $version,string $key): int
{
    workflowBegin($db);
    try{
        $existing=null;
        if($id){$q=$db->prepare('SELECT * FROM accounting_payments WHERE id=? FOR UPDATE');$q->execute([$id]);$existing=$q->fetch();if(!$existing||(int)$existing['version']!==$version||$existing['status']!=='draft')throw new InvalidArgumentException('El pago cambió o ya no está en borrador. Recarga antes de guardar.');}
        else{$q=$db->prepare('SELECT id FROM accounting_payments WHERE creation_key=?');$q->execute([$key]);if($old=$q->fetchColumn()){$db->commit();return(int)$old;}}
        $data=validateAccountingPayment($db,$input,$existing);
        if($id){$db->prepare('UPDATE accounting_payments SET '.implode(',',array_map(static fn($k)=>$k.'=:'.$k,array_keys($data))).',version=version+1 WHERE id=:id')->execute($data+['id'=>$id]);}
        else $id=insertAccountingPayment($db,$data,'draft',$key);
        $db->commit();return $id;
    }catch(Throwable $e){$db->rollBack();if($e instanceof PDOException&&($e->errorInfo[1]??null)===1062&&$key!==''){$q=$db->prepare('SELECT id FROM accounting_payments WHERE creation_key=?');$q->execute([$key]);if($old=$q->fetchColumn())return(int)$old;}throw $e;}
}
function paymentLockInvoices(PDO $db,array $ids): array
{
    $ids=array_values(array_unique(array_map('intval',$ids)));if(!$ids)return [];
    $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id IN('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$records=$q->fetchAll();
    if(count($records)!==count($ids))throw new InvalidArgumentException('La factura seleccionada no existe.');
    $orders=[];foreach($records as $i){if($i['sale_id'])$orders['sales_quotations:'.$i['sale_id']]=['sales_quotations',(int)$i['sale_id']];if($i['purchase_id'])$orders['purchase_orders:'.$i['purchase_id']]=['purchase_orders',(int)$i['purchase_id']];if($i['reversal_of_id'])$ids[]=(int)$i['reversal_of_id'];}
    uasort($orders,static fn($a,$b)=>$a<=>$b);foreach($orders as [$table,$id]){$q=$db->prepare('SELECT id FROM '.$table.' WHERE id=? FOR UPDATE');$q->execute([$id]);}
    $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);$locked=[];foreach($ids as $id){$q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=? FOR UPDATE');$q->execute([$id]);$locked[$id]=$q->fetch();}return $locked;
}
function paymentInvoiceAmount(PDO $db,array $invoice): string
{
    return $invoice['document_type']==='credit'?creditRefundAmount($db,$invoice):bcsub($invoice['total_amount'],invoiceCredits($db,(int)$invoice['id']),2);
}
function paymentValidateInvoice(array $payment,array $invoice): void
{
    $inbound=($invoice['invoice_type']==='customer')===($invoice['document_type']==='invoice');
    if($invoice['status']!=='posted'||$invoice['payment_state']==='reversed')throw new InvalidArgumentException('La acción requiere una factura registrada y no revertida.');
    if((int)$payment['contact_id']!==(int)$invoice['contact_id']||$payment['partner_type']!==$invoice['invoice_type']||$payment['direction']!==($inbound?'inbound':'outbound'))throw new InvalidArgumentException('El pago debe corresponder al mismo contacto y al sentido de la factura o reembolso.');
}
function paymentApplicableAmount(string $available, string $balance, string $paymentCurrency, string $invoiceCurrency, array $pair): string
{
    if (bccomp($balance, '0', 2) <= 0) return '0.00';
    $maximum = accountingConvert($balance, $invoiceCurrency, $paymentCurrency, $pair);
    $amount = bccomp($available, $maximum, 2) < 0 ? $available : $maximum;
    // Rounding in the inverse conversion must not allocate more than the invoice balance.
    if (bccomp(accountingConvert($amount, $paymentCurrency, $invoiceCurrency, $pair), $balance, 2) > 0) $amount = bcsub($amount, '0.01', 2);
    return bccomp($amount, '0', 2) > 0 ? $amount : '0.00';
}
function paymentApplyLocked(PDO $db,array $payment,array $invoice,string $paymentAmount): void
{
    paymentValidateInvoice($payment,$invoice);if(!in_array($payment['status'],['pending','settled'],true))throw new InvalidArgumentException('Registra el pago antes de aplicarlo a una factura.');
    $p=loadAccountingPayment($db,(int)$payment['id']);if(bccomp($paymentAmount,'0',2)<=0||bccomp($paymentAmount,$p['available'],2)>0)throw new InvalidArgumentException('El importe supera el saldo disponible del pago.');
    $amount=accountingConvert($paymentAmount,$payment['currency_code'],$invoice['currency_code'],$payment);$details=invoicePaymentDetails($db,(int)$invoice['id']);
    $remaining=bcsub(paymentInvoiceAmount($db,$invoice),bcadd($details['settled'],$details['pending'],2),2);
    if(bccomp($amount,'0',2)<=0||bccomp($amount,$remaining,2)>0)throw new InvalidArgumentException('El pago supera el saldo pendiente de la factura o su conversión es menor a un céntimo.');
    $q=$db->prepare('SELECT * FROM accounting_invoice_payments WHERE payment_id=? AND invoice_id=?');$q->execute([$payment['id'],$invoice['id']]);$old=$q->fetch();
    if($old&&$old['status']!=='cancel'){$amount=bcadd($amount,$old['amount'],2);$paymentAmount=bcadd($paymentAmount,$old['payment_amount'],2);}
    $db->prepare('INSERT INTO accounting_invoice_payments(payment_id,invoice_id,amount,payment_amount,payment_date,reference,status) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),payment_amount=VALUES(payment_amount),status=VALUES(status),payment_date=VALUES(payment_date),reference=VALUES(reference)')->execute([$payment['id'],$invoice['id'],$amount,$paymentAmount,$payment['payment_date'],$payment['reference'],$payment['status']]);
}
function paymentRecomputeInvoices(PDO $db,array $invoices): void
{
    foreach($invoices as $i){invoiceRecomputePaymentState($db,$i);$db->prepare('UPDATE accounting_invoices SET version=version+1 WHERE id=?')->execute([$i['id']]);}
}
function transitionAccountingPayment(PDO $db,int $id,?int $version,string $action,array $input=[]): void
{
    workflowBegin($db);
    try{
        $q=$db->prepare('SELECT * FROM accounting_payments WHERE id=? FOR UPDATE');$q->execute([$id]);$p=$q->fetch();
        if(!$p||(int)$p['version']!==$version)throw new InvalidArgumentException('El pago cambió en otra ventana. Recarga antes de continuar.');
        $q=$db->prepare('SELECT invoice_id FROM accounting_invoice_payments WHERE payment_id=? AND status!=?');$q->execute([$id,'cancel']);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if($action==='apply_payment')$ids[]=salesId($input['invoice_id']??null)??0;
        $invoices=paymentLockInvoices($db,$ids);
        if(isset($input['invoice_version'],$input['invoice_id']) && (int)($invoices[(int)$input['invoice_id']]['version']??0)!==salesId($input['invoice_version']))throw new InvalidArgumentException('La factura cambió en otra ventana. Recarga antes de continuar.');
        if($action==='post_payment'){if($p['status']!=='draft')throw new InvalidArgumentException('Solo puedes registrar un pago en borrador.');$db->prepare("UPDATE accounting_payments SET status='pending' WHERE id=?")->execute([$id]);}
        elseif($action==='apply_payment'){
            $invoice=$invoices[(int)$input['invoice_id']];if(isset($input['invoice_version'])&&(int)$invoice['version']!==salesId($input['invoice_version']))throw new InvalidArgumentException('La factura cambió en otra ventana. Recarga antes de continuar.');
            $amount=salesDecimal($input['amount']??'',16,2);if(!$amount)throw new InvalidArgumentException('Indica un importe válido a aplicar.');paymentApplyLocked($db,$p,$invoice,$amount);
        }elseif($action==='settle_payment'){
            if($p['status']!=='pending')throw new InvalidArgumentException('Solo puedes confirmar un pago en proceso.');
            foreach(loadAccountingPayment($db,$id)['allocations'] as $a)if($a['status']!=='cancel'){
                $i=$invoices[(int)$a['invoice_id']];paymentValidateInvoice($p,$i);$d=invoicePaymentDetails($db,(int)$i['id']);if(bccomp(bcadd($d['settled'],$a['amount'],2),paymentInvoiceAmount($db,$i),2)>0)throw new InvalidArgumentException('Una aplicación supera el saldo actual de su factura.');
            }
            $db->prepare("UPDATE accounting_payments SET status='settled' WHERE id=?")->execute([$id]);$db->prepare("UPDATE accounting_invoice_payments SET status='settled' WHERE payment_id=? AND status='pending'")->execute([$id]);
        }elseif(in_array($action,['cancel_payment','unapply_payment'],true)){
            if($p['status']==='cancel')throw new InvalidArgumentException('El pago ya está cancelado.');
            $target=$action==='unapply_payment'?salesId($input['invoice_id']??null):null;
            $allocations=loadAccountingPayment($db,$id)['allocations'];$found=false;
            foreach($allocations as $a)if($a['status']!=='cancel'&&(!$target||(int)$a['invoice_id']===$target)){
                $found=true;$i=$invoices[(int)$a['invoice_id']];
                if($i['document_type']==='invoice'){$q=$db->prepare("SELECT COUNT(*) FROM accounting_invoice_payments l JOIN accounting_invoices c ON c.id=l.invoice_id WHERE c.reversal_of_id=? AND l.status IN('pending','settled')");$q->execute([$i['id']]);if($q->fetchColumn())throw new InvalidArgumentException('Retira primero los reembolsos relacionados antes de desvincular el pago original.');}
                $db->prepare("UPDATE accounting_invoice_payments SET status='cancel' WHERE id=?")->execute([$a['id']]);
            }
            if($action==='unapply_payment'&&(!$target||!$found))throw new InvalidArgumentException('La aplicación seleccionada no está activa.');
            if($action==='cancel_payment')$db->prepare("UPDATE accounting_payments SET status='cancel' WHERE id=?")->execute([$id]);
        }else throw new InvalidArgumentException('La acción del pago no es válida.');
        paymentRecomputeInvoices($db,$invoices);$db->prepare('UPDATE accounting_payments SET version=version+1 WHERE id=?')->execute([$id]);$db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
function paymentFromInvoice(PDO $db,int $invoiceId,?int $version,string $action,array $input): void
{
    if($action==='register_payment'){
        workflowBegin($db);
        try{
            $invoices=paymentLockInvoices($db,[$invoiceId]);$i=$invoices[$invoiceId];if((int)$i['version']!==$version)throw new InvalidArgumentException('La factura cambió en otra ventana. Recarga antes de continuar.');
            $inbound=($i['invoice_type']==='customer')===($i['document_type']==='invoice');
            $data=validateAccountingPayment($db,$input+['partner_type'=>$i['invoice_type'],'direction'=>$inbound?'inbound':'outbound','contact_id'=>$i['contact_id']],null,$i);
            paymentValidateInvoice($data, $i);
            $details = invoicePaymentDetails($db, $invoiceId);
            $balance = bcsub(paymentInvoiceAmount($db, $i), bcadd($details['settled'], $details['pending'], 2), 2);
            if (bccomp($balance, '0', 2) <= 0) throw new InvalidArgumentException('La factura ya está cubierta por sus pagos. El importe adicional se puede registrar desde Pagos.');
            $applied = paymentApplicableAmount($data['amount'], $balance, $data['currency_code'], $i['currency_code'], $data);
            if ($i['document_type'] === 'credit' && bccomp(accountingConvert($data['amount'], $data['currency_code'], $i['currency_code'], $data), $balance, 2) > 0) throw new InvalidArgumentException('El reembolso supera el importe pendiente de devolver.');
            $id=insertAccountingPayment($db,$data,'pending');$p=loadAccountingPayment($db,$id);paymentApplyLocked($db,$p,$i,$applied);paymentRecomputeInvoices($db,$invoices);$db->commit();
        }catch(Throwable $e){$db->rollBack();throw $e;}
    }else{
        $allocation=salesId($input['payment_id']??null);$q=$db->prepare('SELECT payment_id FROM accounting_invoice_payments WHERE id=? AND invoice_id=? AND status!=?');$q->execute([$allocation,$invoiceId,'cancel']);$id=$q->fetchColumn();
        $q=$db->prepare('SELECT version FROM accounting_invoices WHERE id=?');$q->execute([$invoiceId]);if((int)$q->fetchColumn()!==$version)throw new InvalidArgumentException('La factura cambió en otra ventana. Recarga antes de continuar.');
        if(!$id)throw new InvalidArgumentException('El pago solicitado no admite esa acción.');$p=loadAccountingPayment($db,(int)$id);
        // From a single invoice, cancel removes that application; the payment stays available.
        transitionAccountingPayment($db,(int)$id,(int)$p['version'],$action==='cancel_payment'?'unapply_payment':'settle_payment',['invoice_id'=>$invoiceId,'invoice_version'=>$version]);
    }
}
