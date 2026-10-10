<?php
declare(strict_types=1);
require_once __DIR__ . '/commerce-repository.php';
require_once __DIR__.'/payments-repository.php';

function invoiceStatuses(): array { return ['draft' => 'Borrador', 'posted' => 'Registrado', 'cancel' => 'Cancelado']; }
function invoicePaymentStates(): array { return ['not_paid' => 'Sin pagar', 'in_payment' => 'En proceso de pago', 'partial' => 'Parcialmente pagado', 'paid' => 'Pagado', 'reversed' => 'Revertido']; }
function invoiceFilterStates(): array { return invoiceStatuses() + ['sent' => 'Enviado'] + invoicePaymentStates(); }

function invoicePaymentDetails(PDO $db, int $id): array
{
    $payments = $db->prepare('SELECT l.*,p.name AS payment_name,p.currency_code AS payment_currency FROM accounting_invoice_payments l LEFT JOIN accounting_payments p ON p.id=l.payment_id WHERE l.invoice_id = ? ORDER BY l.payment_date,l.id'); $payments->execute([$id]); $rows = $payments->fetchAll();
    $settled = '0.00'; $pending = '0.00';
    foreach ($rows as $row) {
        if ($row['status'] === 'settled') $settled = bcadd($settled, $row['amount'], 2);
        elseif ($row['status'] === 'pending') $pending = bcadd($pending, $row['amount'], 2);
    }
    $credits=$db->prepare('SELECT id,name,status FROM accounting_invoices WHERE reversal_of_id=? ORDER BY id');$credits->execute([$id]);
    $reversal = $db->prepare('SELECT * FROM accounting_invoice_reversals WHERE invoice_id = ?'); $reversal->execute([$id]);
    return ['payments' => $rows, 'settled' => $settled, 'pending' => $pending, 'reversal' => $reversal->fetch() ?: null, 'credited' => invoiceCredits($db,$id), 'credits'=>$credits->fetchAll()];
}

function invoiceRecomputePaymentState(PDO $db, array $invoice): void
{
    $details = invoicePaymentDetails($db, (int) $invoice['id']);
    $state = 'not_paid';
    $amount = $invoice['document_type']==='credit' ? creditRefundAmount($db,$invoice) : bcsub($invoice['total_amount'],$details['credited'],2);
    if ($details['reversal'] && !$details['credits']) $state='reversed';
    elseif ($invoice['document_type'] === 'invoice' && bccomp($details['credited'], $invoice['total_amount'],2)>=0 && bccomp($details['credited'],'0',2)>0) $state = 'reversed';
    elseif (bccomp($details['settled'], $amount, 2) >= 0) $state = 'paid';
    elseif (bccomp($details['pending'], '0', 2) > 0 && bccomp(bcadd($details['settled'], $details['pending'], 2), $amount, 2) >= 0) $state = 'in_payment';
    elseif (bccomp($details['settled'], '0', 2) > 0) $state = 'partial';
    elseif (bccomp($details['pending'], '0', 2) > 0) $state = 'in_payment';
    $db->prepare('UPDATE accounting_invoices SET payment_state = ? WHERE id = ?')->execute([$state, $invoice['id']]);
    if ($invoice['document_type']==='invoice') {
        $q=$db->prepare("SELECT * FROM accounting_invoices WHERE reversal_of_id=? AND status='posted'");$q->execute([$invoice['id']]);
        foreach($q->fetchAll() as $note) invoiceRecomputePaymentState($db,$note);
    }
}

function transitionInvoiceStatus(PDO $db, int $id, ?int $version, string $action, array $input): void
{
    if(in_array($action,['register_payment','settle_payment','cancel_payment'],true)){paymentFromInvoice($db,$id,$version,$action,$input);return;}
    $query=$db->prepare('SELECT sale_id,purchase_id,reversal_of_id FROM accounting_invoices WHERE id=?');$query->execute([$id]);$relation=$query->fetch();
    if(!$relation)throw new InvalidArgumentException('La factura no existe.');$original=$relation['reversal_of_id'];
    workflowBegin($db);
    try {
        workflowLockOrder($db,['quotation_id'=>$relation['sale_id'],'purchase_order_id'=>$relation['purchase_id']]);
        if($original){$q=$db->prepare('SELECT id FROM accounting_invoices WHERE id=? FOR UPDATE');$q->execute([$original]);}
        $query = $db->prepare('SELECT * FROM accounting_invoices WHERE id = ? FOR UPDATE'); $query->execute([$id]); $invoice = $query->fetch();
        if (! $invoice || (int) $invoice['version'] !== $version) throw new InvalidArgumentException('La factura cambió en otra ventana. Recarga antes de continuar.');
        $details = invoicePaymentDetails($db, $id);
        $effectiveAmount=$invoice['document_type']==='credit'?creditRefundAmount($db,$invoice):bcsub($invoice['total_amount'],$details['credited'],2);
        if ($action === 'cancel_invoice') {
            if (array_filter($details['credits'],static fn($c)=>$c['status']!=='cancel') || $invoice['status'] === 'cancel' || bccomp($details['credited'],'0',2)>0 || bccomp(bcadd($details['pending'], $details['settled'], 2), '0', 2) > 0) throw new InvalidArgumentException('Para cancelar la factura no debe tener pagos activos ni una reversión.');
            $db->prepare("UPDATE accounting_invoices SET status = 'cancel' WHERE id = ?")->execute([$id]);
        } else {
            if ($invoice['status'] !== 'posted' || $invoice['payment_state']==='reversed') throw new InvalidArgumentException('La acción requiere una factura registrada y no revertida.');
            if ($action === 'mark_invoice_sent') {
                if ($invoice['sent_at']) throw new InvalidArgumentException('La factura ya está marcada como enviada.');
                $db->prepare('UPDATE accounting_invoices SET sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
            } else throw new InvalidArgumentException('La acción de factura no es válida.');
            invoiceRecomputePaymentState($db, $invoice);
        }
        if ($invoice['reversal_of_id']) {
            $q=$db->prepare('SELECT * FROM accounting_invoices WHERE id=?');$q->execute([$invoice['reversal_of_id']]);invoiceRecomputePaymentState($db,$q->fetch());
        }
        $db->prepare('UPDATE accounting_invoices SET version = version + 1 WHERE id = ?')->execute([$id]);
        $db->commit();
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}
