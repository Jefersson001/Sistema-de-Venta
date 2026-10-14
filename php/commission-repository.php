<?php
declare(strict_types=1);
require_once __DIR__.'/sales-repository.php';
require_once __DIR__.'/treasury-repository.php';

function salesCommissionStates(): array
{
    return ['estimated'=>'Estimada','waiting'=>'Pendiente de cobro','pending'=>'Pendiente de pago',
        'partial'=>'Parcialmente pagada','paid'=>'Pagada','cancel'=>'Cancelada','adjustment'=>'Ajuste por recuperar'];
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

function loadSalesCommissions(PDO $db, ?array $range = null, ?int $sellerId = null, ?int $invoiceId = null): array
{
    $where = " WHERE i.invoice_type='customer' AND i.document_type='invoice' AND i.salesperson_id IS NOT NULL
        AND (EXISTS(SELECT 1 FROM accounting_invoice_lines x WHERE x.invoice_id=i.id AND (x.commission_unit_amount>0 OR (x.commission_unit_amount IS NULL AND x.commission_reference_price IS NOT NULL AND x.unit_price>x.commission_reference_price)))
        OR EXISTS(SELECT 1 FROM sales_commission_payments x WHERE x.invoice_id=i.id))";
    $params = [];
    if ($range) { $where .= ' AND i.invoice_date BETWEEN ? AND ?'; $params[] = $range['date_from']; $params[] = $range['date_to']; }
    if ($sellerId) { $where .= ' AND i.salesperson_id=?'; $params[] = $sellerId; }
    if ($invoiceId) { $where .= ' AND i.id=?'; $params[] = $invoiceId; }
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
            $quantity = bcsub($line['quantity'], $line['returned_quantity'], 3);
            if (bccomp($quantity,'0',3)<0) $quantity='0.000';
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
        $balance = bcsub($row['commission_earned'],$row['commission_paid'],2);
        $row['commission_pending'] = bccomp($balance,'0',2)>0 ? $balance : '0.00';
        $row['commission_overpaid'] = bccomp($balance,'0',2)<0 ? bcsub('0',$balance,2) : '0.00';
        $row['commission_waiting'] = bcsub($row['commission_generated'],$row['commission_earned'],2);
        $row['commission_state'] = match (true) {
            bccomp($row['commission_overpaid'],'0',2)>0 => 'adjustment',
            $row['status']==='cancel' || bccomp($net,'0',2)<=0 => 'cancel',
            $row['status']==='draft' => 'estimated',
            bccomp($row['commission_earned'],'0',2)<=0 => 'waiting',
            bccomp($row['commission_paid'],'0',2)<=0 => 'pending',
            bccomp($row['commission_pending'],'0',2)>0 || bccomp($row['commission_waiting'],'0',2)>0 => 'partial',
            default => 'paid'
        };
        $row['commission_state_label'] = salesCommissionStates()[$row['commission_state']];
    }
    unset($row); return $rows;
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
            $groups[$key][$field]=bcadd($groups[$key][$field],$row[$field],2);
        }
    }
    foreach ($groups as &$group) { $group['sales_count']=count($group['sale_ids']); unset($group['sale_ids']); }
    unset($group); return array_values($groups);
}

function loadSalesCommissionPayments(PDO $db,int $invoiceId): array
{
    $q=$db->prepare('SELECT p.*,d.name,d.movement_date,d.amount AS payment_amount,d.currency_code,a.name AS account_name,m.name AS method_name,r.id AS reversed_by_id
        FROM sales_commission_payments p JOIN accounting_treasury_documents d ON d.id=p.treasury_document_id
        JOIN accounting_payment_accounts a ON a.id=d.account_id JOIN accounting_payment_methods m ON m.id=d.method_id
        LEFT JOIN accounting_treasury_documents r ON r.reversal_of_id=d.id WHERE p.invoice_id=? ORDER BY d.id');
    $q->execute([$invoiceId]); return $q->fetchAll();
}

function paySalesCommission(PDO $db,int $invoiceId,array $input,string $creationKey): int
{
    workflowBegin($db);
    try {
        if (!preg_match('/^[a-f0-9]{32,64}$/',$creationKey)) throw new InvalidArgumentException('La solicitud de pago no es válida. Recarga la página.');
        $q=$db->prepare('SELECT id FROM accounting_treasury_documents WHERE creation_key=?');$q->execute([$creationKey]);
        if ($existing=$q->fetchColumn()) { $db->commit(); return (int)$existing; }
        $q=$db->prepare('SELECT sale_id,salesperson_id FROM accounting_invoices WHERE id=?');$q->execute([$invoiceId]);$relation=$q->fetch();
        if (!$relation || !$relation['salesperson_id']) throw new InvalidArgumentException('La factura no tiene vendedor relacionado.');
        workflowLockOrder($db,['quotation_id'=>$relation['sale_id'],'purchase_order_id'=>null]);
        $q=$db->prepare('SELECT id FROM accounting_invoices WHERE id=? FOR UPDATE');$q->execute([$invoiceId]);
        // Serializar pagos incluso cuando dos ventanas usan distintas claves.
        $q=$db->prepare('SELECT id FROM accounting_treasury_documents WHERE creation_key=?');$q->execute([$creationKey]);
        if ($existing=$q->fetchColumn()) { $db->commit(); return (int)$existing; }
        $commission=loadSalesCommissions($db,null,null,$invoiceId)[0]??null;
        if (!$commission) throw new InvalidArgumentException('No hay una comisión calculada para esta factura.');
        $q=$db->prepare('SELECT id FROM contacts WHERE id=? FOR UPDATE');$q->execute([$commission['salesperson_id']]);
        $amount=salesDecimal($input['amount']??null,16,2);
        if ($amount===null || bccomp($amount,'0',2)<=0 || bccomp($amount,$commission['commission_pending'],2)>0) {
            throw new InvalidArgumentException('El importe debe ser positivo y no superar la comisión disponible: '.$commission['commission_pending'].' '.$commission['currency_code'].'.');
        }
        // Las diferencias por devoluciones o reversiones ya pagadas deben resolverse primero.
        foreach (loadSalesCommissions($db,null,(int)$commission['salesperson_id']) as $other) {
            if (bccomp($other['commission_overpaid'],'0',2)>0) throw new InvalidArgumentException('El vendedor tiene una comisión con ajuste por recuperar. Revisa o revierte su pago antes de pagar otras comisiones.');
        }
        $date=treasuryDate($input['movement_date']??null);
        if ($date<$commission['invoice_date'] || $date>commerceToday()) throw new InvalidArgumentException('La fecha del pago debe estar entre la fecha de factura y hoy.');
        $q=$db->prepare("SELECT MAX(payment_date) FROM accounting_invoice_payments WHERE invoice_id=? AND status='settled'");$q->execute([$invoiceId]);
        if ($last=$q->fetchColumn()) if ($date<$last) throw new InvalidArgumentException('El pago de comisión no puede ser anterior al cobro confirmado del cliente.');
        $settings=accountingCurrencies($db,$date);
        $settings['exchange_rate']=accountingExchangeRate($input['exchange_rate']??$settings['exchange_rate']);
        $currency=$input['currency_code']??$commission['currency_code'];
        if (!is_string($currency)) throw new InvalidArgumentException('Selecciona una moneda válida.');
        $money=accountingConvert($amount,$commission['currency_code'],$currency,$settings);
        if (salesDecimal($money,16,2)===null || bccomp($money,'0',2)<=0) throw new InvalidArgumentException('El importe convertido no es válido.');
        $q=$db->query("SELECT id FROM accounting_cash_concepts WHERE system_key='sales_commission'");$concept=$q->fetchColumn();
        $data=validateTreasuryDocument($db,['kind'=>'expense','account_id'=>$input['account_id']??null,
            'currency_code'=>$currency,'amount'=>$money,'movement_date'=>$date,'exchange_rate'=>$settings['exchange_rate'],
            'concept_id'=>(string)$concept,'contact_id'=>(string)$commission['salesperson_id'],'method_id'=>$input['method_id']??null,
            'reference'=>'Comisión de '.$commission['name']],null,true);
        if (bccomp(treasuryBalance($db,(int)$data['account_id'],$currency,$date),$money,2)<0) throw new InvalidArgumentException('El saldo de la cuenta de egreso no alcanza para pagar esta comisión.');
        $id=treasuryInsertDocument($db,$data+['status'=>'done','creation_key'=>$creationKey]);
        treasuryEntry($db,['account_id'=>$data['account_id'],'currency_code'=>$currency,'movement_date'=>$date,'amount'=>bcsub('0',$money,2),
            'category'=>'movement','document_id'=>$id,'concept_id'=>$data['concept_id'],'contact_id'=>$data['contact_id'],'method_id'=>$data['method_id']]);
        $db->prepare('INSERT INTO sales_commission_payments(invoice_id,treasury_document_id,amount) VALUES (?,?,?)')->execute([$invoiceId,$id,$amount]);
        $db->commit(); return $id;
    } catch (Throwable $exception) { if ($db->inTransaction()) $db->rollBack(); throw $exception; }
}
