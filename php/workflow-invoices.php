<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow-repository.php';

function workflowCreateInvoice(PDO $db,string $kind,int $id): int
{
    workflowBegin($db);
    try{
        $sale=$kind==='sale';$table=$sale?'sales_quotations':'purchase_orders';$fk=$sale?'sale_id':'purchase_id';
        $q=$db->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE");$q->execute([$id]);$order=$q->fetch();
        if(!$order || $order['status']!==($sale?'sale':'purchase'))throw new InvalidArgumentException('Confirma la orden antes de generar la factura.');
        $q=$db->prepare("SELECT id FROM accounting_invoices WHERE $fk=? AND document_type='invoice' AND status='draft' ORDER BY id DESC LIMIT 1");$q->execute([$id]);$draft=$q->fetchColumn();
        if($draft){$db->commit();return (int)$draft;}
        $lines=array_values(array_filter(commerceLineProgress($db,$kind,$id),static fn($l)=>bccomp($l['to_invoice'],'0',3)>0));
        if(!$lines){
            $q=$db->prepare("SELECT id FROM accounting_invoices WHERE $fk=? AND document_type='invoice' AND status='posted' ORDER BY id DESC LIMIT 1");$q->execute([$id]);$previous=$q->fetchColumn();
            if($previous){$db->commit();return (int)$previous;}
            throw new InvalidArgumentException('Confirma la orden y valida cantidades de su entrega o recepción antes de generar la factura.');
        }
        $base='0.00';$tax='0.00';$total='0.00';
        foreach($lines as &$l){$l=array_merge($l,commerceAmounts($l['to_invoice'],$l['unit_price'],$l['tax_rate']));$base=bcadd($base,$l['base_amount'],2);$tax=bcadd($tax,$l['tax_amount'],2);$total=bcadd($total,$l['total_amount'],2);}unset($l);
        $date=commerceToday();$due=commerceDueDate($db,(int)$order['payment_term_id']?:null,$date);
        $db->prepare('INSERT INTO accounting_invoices (invoice_type,sale_id,purchase_id,contact_id,invoice_address,invoice_date,due_date,payment_term_id,salesperson_id,base_amount,tax_amount,total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$sale?'customer':'supplier',$sale?$id:null,$sale?null:$id,$order[$sale?'customer_id':'supplier_id'],$order['invoice_address']??'',$date,$due,$order['payment_term_id'],$order['salesperson_id']??null,$base,$tax,$total]);
        $invoice=(int)$db->lastInsertId();$db->prepare('UPDATE accounting_invoices SET name=? WHERE id=?')->execute([($sale?'FC':'FP').str_pad((string)$invoice,5,'0',STR_PAD_LEFT),$invoice]);
        $q=$db->prepare('INSERT INTO accounting_invoice_lines (invoice_id,position,product_id,product_name,quantity,unit_price,tax_id,tax_name,tax_rate,base_amount,tax_amount,total_amount,unit_cost,sale_line_id,purchase_line_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($lines as $pos=>$l)$q->execute([$invoice,$pos,$l['product_id'],$l['product_name'],$l['to_invoice'],$l['unit_price'],$l['tax_id'],$l['tax_name'],$l['tax_rate'],$l['base_amount'],$l['tax_amount'],$l['total_amount'],$l['unit_cost'],$sale?$l['id']:null,$sale?null:$l['id']]);
        $db->commit();return $invoice;
    }catch(Throwable $e){$db->rollBack();throw $e;}
}

function invoiceCredits(PDO $db,int $id): string
{
    $q=$db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM accounting_invoices WHERE reversal_of_id=? AND document_type='credit' AND status='posted'");$q->execute([$id]);return (string)$q->fetchColumn();
}

function creditRefundAmount(PDO $db,array $credit): string
{
    if ($credit['status'] !== 'posted') return '0.00';
    $q=$db->prepare('SELECT total_amount FROM accounting_invoices WHERE id=?');$q->execute([$credit['reversal_of_id']]);$total=(string)$q->fetchColumn();
    $q=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM accounting_invoice_payments WHERE invoice_id=? AND status='settled'");$q->execute([$credit['reversal_of_id']]);$paid=(string)$q->fetchColumn();
    $unpaid=bcsub($total,$paid,2);$prior='0.00';
    $q=$db->prepare("SELECT id,total_amount FROM accounting_invoices WHERE reversal_of_id=? AND status='posted' ORDER BY id");$q->execute([$credit['reversal_of_id']]);
    foreach($q->fetchAll() as $row) {
        $before=bccomp($prior,$unpaid,2)>0?bcsub($prior,$unpaid,2):'0.00';
        $after=bcadd($prior,$row['total_amount'],2);$after=bccomp($after,$unpaid,2)>0?bcsub($after,$unpaid,2):'0.00';
        if((int)$row['id']===(int)$credit['id'])return bcsub($after,$before,2);
        $prior=bcadd($prior,$row['total_amount'],2);
    }
    return '0.00';
}

function createCreditNote(PDO $db,int $id,?int $version,string $reason): int
{
    $q=$db->prepare('SELECT sale_id,purchase_id FROM accounting_invoices WHERE id=?');$q->execute([$id]);$relation=$q->fetch();if(!$relation)throw new InvalidArgumentException('La factura no existe.');
    workflowBegin($db);
    try{
        workflowLockOrder($db,['quotation_id'=>$relation['sale_id'],'purchase_order_id'=>$relation['purchase_id']]);
        $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=? FOR UPDATE');$q->execute([$id]);$i=$q->fetch();
        if(!$i || $i['status']!=='posted' || $i['document_type']!=='invoice' || (int)$i['version']!==$version)throw new InvalidArgumentException('La nota de crédito requiere una factura registrada. Recarga antes de continuar.');
        $q=$db->prepare("SELECT COUNT(*) FROM accounting_invoice_payments WHERE invoice_id=? AND status='pending'");$q->execute([$id]);
        if($q->fetchColumn())throw new InvalidArgumentException('Confirma o cancela los pagos en proceso antes de crear la nota de crédito.');
        $reason=trim($reason);if($reason==='' || mb_strlen($reason)>255)throw new InvalidArgumentException('Indica un motivo de hasta 255 caracteres.');
        $q=$db->prepare("SELECT id FROM accounting_invoices WHERE reversal_of_id=? AND status='draft' AND document_type='credit' LIMIT 1");$q->execute([$id]);$draft=$q->fetchColumn();if($draft){$db->commit();return (int)$draft;}
        $q=$db->prepare('SELECT * FROM accounting_invoice_lines WHERE invoice_id=? ORDER BY position,id');$q->execute([$id]);$lines=[];$base='0.00';$tax='0.00';$total='0.00';
        foreach($q->fetchAll() as $l){
            $used=$db->prepare("SELECT COALESCE(SUM(l.quantity),0) FROM accounting_invoice_lines l JOIN accounting_invoices c ON c.id=l.invoice_id WHERE l.original_line_id=? AND c.status!='cancel'");$used->execute([$l['id']]);$qty=bcsub($l['quantity'],(string)$used->fetchColumn(),3);
            if(bccomp($qty,'0',3)<=0)continue;
            $l=array_merge($l,commerceAmounts($qty,$l['unit_price'],$l['tax_rate']),['quantity'=>$qty]);$lines[]=$l;$base=bcadd($base,$l['base_amount'],2);$tax=bcadd($tax,$l['tax_amount'],2);$total=bcadd($total,$l['total_amount'],2);
        }
        if(!$lines)throw new InvalidArgumentException('La factura ya fue revertida por sus notas de crédito.');
        $db->prepare("INSERT INTO accounting_invoices (invoice_type,document_type,reversal_of_id,sale_id,purchase_id,contact_id,invoice_address,invoice_date,payment_term_id,salesperson_id,base_amount,tax_amount,total_amount) VALUES (?,'credit',?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$i['invoice_type'],$id,$i['sale_id'],$i['purchase_id'],$i['contact_id'],$i['invoice_address'],commerceToday(),$i['payment_term_id'],$i['salesperson_id'],$base,$tax,$total]);
        $new=(int)$db->lastInsertId();$db->prepare('UPDATE accounting_invoices SET name=? WHERE id=?')->execute(['NC'.str_pad((string)$new,5,'0',STR_PAD_LEFT),$new]);
        $db->prepare('UPDATE accounting_invoices SET credit_reason=? WHERE id=?')->execute([$reason,$new]);
        $q=$db->prepare('INSERT INTO accounting_invoice_lines (invoice_id,position,product_id,product_name,quantity,unit_price,tax_id,tax_name,tax_rate,base_amount,tax_amount,total_amount,unit_cost,sale_line_id,purchase_line_id,original_line_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($lines as $pos=>$l)$q->execute([$new,$pos,$l['product_id'],$l['product_name'],$l['quantity'],$l['unit_price'],$l['tax_id'],$l['tax_name'],$l['tax_rate'],$l['base_amount'],$l['tax_amount'],$l['total_amount'],$l['unit_cost'],$l['sale_line_id'],$l['purchase_line_id'],$l['id']]);
        $db->commit();return $new;
    }catch(Throwable $e){$db->rollBack();throw $e;}
}

function saveCreditNoteQuantities(PDO $db,array $invoice,array $input): void
{
    $q=$db->prepare('SELECT * FROM accounting_invoice_lines WHERE invoice_id=? ORDER BY id');$q->execute([$invoice['id']]);$lines=$q->fetchAll();$base='0.00';$tax='0.00';$total='0.00';$positive=false;
    foreach($lines as $l){
        $qty=salesDecimal($input[(string)$l['id']]??$l['quantity'],9,3);if($qty===null)throw new InvalidArgumentException('Indica cantidades válidas en la nota de crédito.');
        $orig=$db->prepare('SELECT quantity FROM accounting_invoice_lines WHERE id=?');$orig->execute([$l['original_line_id']]);$maximum=(string)$orig->fetchColumn();
        $used=$db->prepare("SELECT COALESCE(SUM(l.quantity),0) FROM accounting_invoice_lines l JOIN accounting_invoices i ON i.id=l.invoice_id WHERE l.original_line_id=? AND i.status!='cancel' AND i.id!=?");$used->execute([$l['original_line_id'],$invoice['id']]);
        if(bccomp($qty,bcsub($maximum,(string)$used->fetchColumn(),3),3)>0)throw new InvalidArgumentException('La nota de crédito supera la cantidad facturada pendiente de revertir.');
        $a=commerceAmounts($qty,$l['unit_price'],$l['tax_rate']);$positive=$positive||bccomp($qty,'0',3)>0;
        $db->prepare('UPDATE accounting_invoice_lines SET quantity=?,base_amount=?,tax_amount=?,total_amount=? WHERE id=?')->execute([$qty,$a['base_amount'],$a['tax_amount'],$a['total_amount'],$l['id']]);
        $base=bcadd($base,$a['base_amount'],2);$tax=bcadd($tax,$a['tax_amount'],2);$total=bcadd($total,$a['total_amount'],2);
    }
    if(!$positive)throw new InvalidArgumentException('La nota de crédito necesita al menos una cantidad positiva.');
    $db->prepare('UPDATE accounting_invoices SET base_amount=?,tax_amount=?,total_amount=? WHERE id=?')->execute([$base,$tax,$total,$invoice['id']]);
}
