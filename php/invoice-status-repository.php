<?php
declare(strict_types=1);
require_once __DIR__ . '/commerce-repository.php';

function invoiceStatuses(): array { return ['draft' => 'Borrador', 'posted' => 'Registrado', 'cancel' => 'Cancelado']; }
function invoicePaymentStates(): array { return ['not_paid' => 'Sin pagar', 'in_payment' => 'En proceso de pago', 'partial' => 'Parcialmente pagado', 'paid' => 'Pagado', 'reversed' => 'Revertido']; }
function invoiceFilterStates(): array { return invoiceStatuses() + ['sent' => 'Enviado'] + invoicePaymentStates(); }

function invoicePaymentDetails(PDO $db, int $id): array
{
    $payments = $db->prepare('SELECT * FROM accounting_invoice_payments WHERE invoice_id = ? ORDER BY payment_date, id'); $payments->execute([$id]); $rows = $payments->fetchAll();
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
            } elseif ($action === 'register_payment') {
                $amount = salesDecimal($input['amount'] ?? '', 16, 2);
                $date = $input['payment_date'] ?? '';
                $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
                if (! $amount || bccomp($amount, '0', 2) <= 0) throw new InvalidArgumentException('El importe del pago debe ser positivo, con hasta dos decimales.');
                if (! $parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01' || $date > '9999-12-31') throw new InvalidArgumentException('Selecciona una fecha de pago válida.');
                $reference = is_string($input['reference'] ?? null) ? trim($input['reference']) : '';
                if (mb_strlen($reference) > 160) throw new InvalidArgumentException('La referencia del pago admite hasta 160 caracteres.');
                if (bccomp(bcadd(bcadd($details['settled'], $details['pending'], 2), $amount, 2), $effectiveAmount, 2) > 0) throw new InvalidArgumentException('El pago supera el saldo pendiente de la factura.');
                $db->prepare('INSERT INTO accounting_invoice_payments (invoice_id, amount, payment_date, reference) VALUES (?, ?, ?, ?)')->execute([$id, $amount, $date, $reference]);
            } elseif (in_array($action, ['settle_payment', 'cancel_payment'], true)) {
                $paymentId = salesId($input['payment_id'] ?? null); $payment = null;
                foreach ($details['payments'] as $row) if ((int) $row['id'] === $paymentId) $payment = $row;
                if (! $payment || $payment['status'] === 'cancel' || ($action === 'settle_payment' && $payment['status'] !== 'pending')) throw new InvalidArgumentException('El pago solicitado no admite esa acción.');
                if($action==='cancel_payment' && $invoice['document_type']==='invoice') {
                    $q=$db->prepare("SELECT COUNT(*) FROM accounting_invoice_payments p JOIN accounting_invoices c ON c.id=p.invoice_id WHERE c.reversal_of_id=? AND p.status IN ('pending','settled')");$q->execute([$id]);
                    if($q->fetchColumn())throw new InvalidArgumentException('Cancela primero los reembolsos relacionados antes de anular el pago original.');
                }
                if($action==='settle_payment' && bccomp(bcadd($details['settled'],$payment['amount'],2),$effectiveAmount,2)>0)throw new InvalidArgumentException('El importe supera el saldo actual; cancela el pago y registra el importe correcto.');
                $db->prepare('UPDATE accounting_invoice_payments SET status = ? WHERE id = ? AND invoice_id = ?')->execute([$action === 'settle_payment' ? 'settled' : 'cancel', $paymentId, $id]);
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
