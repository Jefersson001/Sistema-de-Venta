<?php
declare(strict_types=1);
require_once __DIR__.'/sales-repository.php';
require_once __DIR__.'/treasury-repository.php';

function salesCommissionStates(): array
{
    return ['estimated'=>'Estimada','waiting'=>'Pendiente de cobro','pending'=>'Pendiente de pago',
        'compensated'=>'Compensada con adelantos','partial'=>'Parcialmente pagada','paid'=>'Pagada','cancel'=>'Cancelada','adjustment'=>'Ajuste por recuperar'];
}

function invoiceCommissionLinesQuery(PDO $db): PDOStatement
{
    return $db->prepare("SELECT l.*,COALESCE((SELECT SUM(n.quantity) FROM accounting_invoice_lines n JOIN accounting_invoices credit ON credit.id=n.invoice_id
        WHERE n.original_line_id=l.id AND credit.document_type='credit' AND credit.status='posted'),0) AS returned_quantity,
        COALESCE((SELECT SUM(prior.quantity-COALESCE((SELECT SUM(refund.quantity) FROM accounting_invoice_lines refund JOIN accounting_invoices note ON note.id=refund.invoice_id
            WHERE refund.original_line_id=prior.id AND note.status='posted' AND note.document_type='credit'),0))
            FROM accounting_invoice_lines prior JOIN accounting_invoices previous ON previous.id=prior.invoice_id
            WHERE prior.sale_line_id=l.sale_line_id AND prior.id<l.id AND previous.status<>'cancel' AND previous.document_type='invoice' AND prior.commission_reference_price IS NOT NULL),0) AS prior_quantity
        FROM accounting_invoice_lines l WHERE l.invoice_id=? ORDER BY l.position,l.id");
}

function invoiceCommissionSummary(PDO $db, array $invoice): array
{
    $query = invoiceCommissionLinesQuery($db);
    $query->execute([(int) $invoice['id']]);
    $lines = $query->fetchAll();
    $total = '0.00';
    foreach ($lines as &$line) {
        // El documento conserva su comisión original; devoluciones y cobros se muestran en el seguimiento.
        $line['commission_amount'] = empty($invoice['salesperson_id']) ? '0.00' : salesCommissionPortion(
            $line['quantity'], $line['unit_price'], $line['commission_reference_price'],
            $line['prior_quantity'], $line['commission_unit_amount']
        );
        $total = bcadd($total, $line['commission_amount'], 2);
    }
    unset($line);
    return ['lines' => $lines, 'total' => $total];
}

function loadSalesCommissions(PDO $db, ?array $range = null, ?int $sellerId = null, ?int $invoiceId = null, string $permissionCondition = '1=1'): array
{
    $where = " WHERE ".permissionWhere('ventas.commissions','i')." AND i.invoice_type='customer' AND i.document_type='invoice' AND i.salesperson_id IS NOT NULL
        AND (EXISTS(SELECT 1 FROM accounting_invoice_lines x WHERE x.invoice_id=i.id AND (x.commission_unit_amount>0 OR (x.commission_unit_amount IS NULL AND x.commission_reference_price IS NOT NULL AND x.unit_price>x.commission_reference_price)))
        OR EXISTS(SELECT 1 FROM sales_commission_payments x WHERE x.invoice_id=i.id))";
    // Additional permission conditions come from the server's permission helpers.
    $where .= ' AND ('.$permissionCondition.')';
    $params = [];
    if ($sellerId) { $where .= ' AND i.salesperson_id=?'; $params[] = $sellerId; }
    $q = $db->prepare("SELECT i.*,s.name AS seller_name,c.name AS customer_name,o.name AS sale_name,
        COALESCE((SELECT SUM(p.amount) FROM accounting_invoice_payments p WHERE p.invoice_id=i.id AND p.status='settled'),0) AS collected,
        COALESCE((SELECT SUM(n.total_amount) FROM accounting_invoices n WHERE n.reversal_of_id=i.id AND n.status='posted' AND n.document_type='credit'),0) AS credited_total,
        COALESCE((SELECT SUM(n.base_amount) FROM accounting_invoices n WHERE n.reversal_of_id=i.id AND n.status='posted' AND n.document_type='credit'),0) AS credited_base,
        COALESCE((SELECT SUM(p.amount) FROM sales_commission_payments p JOIN accounting_treasury_documents d ON d.id=p.treasury_document_id
            WHERE p.invoice_id=i.id AND d.status='done' AND NOT EXISTS(SELECT 1 FROM accounting_treasury_documents r WHERE r.reversal_of_id=d.id)),0) AS commission_paid
        FROM accounting_invoices i JOIN contacts s ON s.id=i.salesperson_id JOIN contacts c ON c.id=i.contact_id
        LEFT JOIN sales_quotations o ON o.id=i.sale_id".$where.' ORDER BY i.invoice_date,i.id');
    $q->execute($params); $rows = $q->fetchAll();
    $lineQuery = invoiceCommissionLinesQuery($db);
    foreach ($rows as &$row) {
        $row['commission_generated'] = '0.00';
        $lineQuery->execute([$row['id']]); $row['commission_lines'] = $lineQuery->fetchAll();
        foreach ($row['commission_lines'] as &$line) {
            $quantity = bcsub($line['quantity'], $line['returned_quantity'], 2);
            if (bccomp($quantity,'0',2)<0) $quantity='0.00';
            $line['commission_amount'] = salesCommissionPortion($quantity,$line['unit_price'],$line['commission_reference_price'],$line['prior_quantity'],$line['commission_unit_amount']);
            $row['commission_generated'] = bcadd($row['commission_generated'],$line['commission_amount'],2);
        }
        unset($line);
        $row['sales_amount'] = bcsub($row['base_amount'],$row['credited_base'],2);
        $net = bcsub($row['total_amount'],$row['credited_total'],2);
        if ($row['status']==='cancel' || bccomp($net,'0',2)<=0) {
            $row['commission_generated']='0.00'; $row['sales_amount']='0.00';
        }
        $row['commission_earned']='0.00';
        if ($row['status']==='posted' && bccomp($net,'0',2)>0) {
            $collected = bccomp($row['collected'],$net,2)>0 ? $net : $row['collected'];
            $row['commission_earned'] = bcdiv(bcadd(bcdiv(bcmul($row['commission_generated'],$collected,8),$net,8),'0.005',8),'1',2);
        }
        $payableGenerated=$row['status']==='posted'?$row['commission_generated']:'0.00';
        $balance = bcsub($payableGenerated,$row['commission_paid'],2);
        $row['commission_pending'] = bccomp($balance,'0',2)>0 ? $balance : '0.00';
        $row['commission_overpaid'] = bccomp($balance,'0',2)<0 ? bcsub('0',$balance,2) : '0.00';
        $row['commission_waiting'] = bcsub($row['commission_generated'],$row['commission_earned'],2);
        $row['commission_state'] = match (true) {
            bccomp($row['commission_overpaid'],'0',2)>0 => 'adjustment',
            $row['status']==='cancel' || bccomp($net,'0',2)<=0 => 'cancel',
            $row['status']==='draft' => 'estimated',
            bccomp($row['commission_paid'],'0',2)<=0 => 'pending',
            bccomp($row['commission_pending'],'0',2)>0 => 'partial',
            default => 'paid'
        };
        $row['commission_state_label'] = salesCommissionStates()[$row['commission_state']];
    }
    unset($row);
    // Returned commissions already paid offset later unpaid commissions, per seller/company/currency.
    $offsets=[];
    foreach($rows as $row){$key=$row['company_id'].':'.$row['salesperson_id'].':'.$row['currency_code'];$offsets[$key]=bcadd($offsets[$key]??'0.00',$row['commission_overpaid'],2);}
    foreach($rows as &$row){
        $key=$row['company_id'].':'.$row['salesperson_id'].':'.$row['currency_code'];
        $offset=bccomp($offsets[$key],$row['commission_pending'],2)<0?$offsets[$key]:$row['commission_pending'];
        $row['commission_offset']=$offset;$row['commission_pending']=bcsub($row['commission_pending'],$offset,2);$offsets[$key]=bcsub($offsets[$key],$offset,2);
        if(bccomp($offset,'0',2)>0&&bccomp($row['commission_pending'],'0',2)===0)$row['commission_state']='compensated';
        $row['commission_advance']=bccomp($row['commission_paid'],$row['commission_earned'],2)>0?bcsub($row['commission_paid'],$row['commission_earned'],2):'0.00';
        $row['commission_state_label']=salesCommissionStates()[$row['commission_state']];
    }unset($row);
    foreach($rows as &$row){
        $key=$row['company_id'].':'.$row['salesperson_id'].':'.$row['currency_code'];
        $row['commission_recoverable']=bccomp($offsets[$key],$row['commission_overpaid'],2)<0?$offsets[$key]:$row['commission_overpaid'];
        $offsets[$key]=bcsub($offsets[$key],$row['commission_recoverable'],2);
    }unset($row);
    return array_values(array_filter($rows,static fn($row)=>(!$range||($row['invoice_date']>=$range['date_from']&&$row['invoice_date']<=$range['date_to']))&&(!$invoiceId||(int)$row['id']===$invoiceId)));

}

function summarizeSalesCommissions(array $rows): array
{
    $groups=[];
    foreach ($rows as $row) {
        $key=$row['salesperson_id'].'/'.$row['currency_code'];
        $groups[$key] ??= ['seller_id'=>(int)$row['salesperson_id'],'seller_name'=>$row['seller_name'],'currency_code'=>$row['currency_code'],
            'sales_count'=>0,'sales_amount'=>'0.00','commission_generated'=>'0.00','commission_earned'=>'0.00','commission_waiting'=>'0.00',
            'commission_paid'=>'0.00','commission_pending'=>'0.00','commission_overpaid'=>'0.00','sale_ids'=>[]];
        if ($row['status']!=='cancel' && bccomp($row['commission_generated'],'0',2)>0) $groups[$key]['sale_ids'][$row['sale_id'] ?: 'invoice-'.$row['id']]=true;
        foreach (['sales_amount','commission_generated','commission_earned','commission_waiting','commission_paid','commission_pending','commission_overpaid'] as $field) {
            $groups[$key][$field]=bcadd($groups[$key][$field],$field==='commission_overpaid'?$row['commission_recoverable']:$row[$field],2);
        }
    }
    foreach ($groups as &$group) { $group['sales_count']=count($group['sale_ids']); unset($group['sale_ids']); }
    unset($group); return array_values($groups);
}

function salesCommissionPaymentHistory(PDO $db, ?array $range, ?int $sellerId = null, ?int $paymentId = null, int $page = 1): array
{
    $where = [permissionWhere('ventas.commission_payments','p'),"(p.payment_scope='invoice' OR p.id=(SELECT MIN(first_payment.id) FROM sales_commission_payments first_payment WHERE first_payment.treasury_document_id=p.treasury_document_id))"]; $params = [];
    if ($paymentId) { $where[] = 'p.id=?'; $params[] = $paymentId; }
    else {
        if ($range) { $where[] = 'd.movement_date BETWEEN ? AND ?'; $params[] = $range['date_from']; $params[] = $range['date_to']; }
        if ($sellerId) { $where[] = 'i.salesperson_id=?'; $params[] = $sellerId; }
    }
    $from = ' FROM sales_commission_payments p JOIN accounting_treasury_documents d ON d.id=p.treasury_document_id
        JOIN accounting_invoices i ON i.id=p.invoice_id JOIN contacts s ON s.id=i.salesperson_id
        JOIN contacts c ON c.id=i.contact_id JOIN accounting_payment_accounts a ON a.id=d.account_id
        JOIN accounting_payment_methods m ON m.id=d.method_id LEFT JOIN sales_quotations o ON o.id=i.sale_id
        LEFT JOIN accounting_treasury_documents r ON r.reversal_of_id=d.id';
    if ($where) $from .= ' WHERE '.implode(' AND ', $where);
    $query = $db->prepare('SELECT COUNT(*)'.$from); $query->execute($params); $total = (int) $query->fetchColumn();
    $pages = max(1, (int) ceil($total/20)); $page = min(max($page,1),$pages);
    $query = $db->prepare('SELECT p.*,CASE WHEN p.payment_scope=\'seller\' THEN (SELECT SUM(total_payment.amount) FROM sales_commission_payments total_payment WHERE total_payment.treasury_document_id=p.treasury_document_id) ELSE p.amount END AS amount,d.name,d.movement_date,d.amount AS payment_amount,d.currency_code,d.exchange_rate,
        d.reference,d.status,d.created_at AS payment_created_at,a.name AS account_name,m.name AS method_name,s.name AS seller_name,i.salesperson_id,
        c.name AS customer_name,i.name AS invoice_name,i.currency_code AS invoice_currency,i.sale_id,o.name AS sale_name,
        r.id AS reversed_by_id,r.name AS reversal_name'.$from.' ORDER BY d.movement_date DESC,p.id DESC LIMIT 20 OFFSET '.(($page-1)*20));
    $query->execute($params);
    return ['rows'=>$query->fetchAll(),'total'=>$total,'pages'=>$pages,'currentPage'=>$page];
}

function loadSalesCommissionPayments(PDO $db,int $invoiceId): array
{
    $q=$db->prepare('SELECT p.*,(SELECT MIN(first_payment.id) FROM sales_commission_payments first_payment WHERE first_payment.treasury_document_id=p.treasury_document_id) AS id,d.name,d.movement_date,d.amount AS payment_amount,d.currency_code,a.name AS account_name,m.name AS method_name,r.id AS reversed_by_id
        FROM sales_commission_payments p JOIN accounting_treasury_documents d ON d.id=p.treasury_document_id
        JOIN accounting_payment_accounts a ON a.id=d.account_id JOIN accounting_payment_methods m ON m.id=d.method_id
        LEFT JOIN accounting_treasury_documents r ON r.reversal_of_id=d.id WHERE p.invoice_id=? ORDER BY d.id');
    $q->execute([$invoiceId]); return $q->fetchAll();
}

function paySalesCommission(PDO $db,int $invoiceId,array $input,string $creationKey): int
{
    $q=$db->prepare('SELECT salesperson_id,currency_code FROM accounting_invoices WHERE id=?');$q->execute([$invoiceId]);$invoice=$q->fetch();
    if(!$invoice||!$invoice['salesperson_id'])throw new InvalidArgumentException('La factura no tiene vendedor relacionado.');
    return paySellerCommission($db,(int)$invoice['salesperson_id'],$invoice['currency_code'],$input,$creationKey,$invoiceId);
}
require_once __DIR__.'/commission-seller-payments.php';
