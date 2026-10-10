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
    $reversal = $db->prepare('SELECT * FROM accounting_invoice_reversals WHERE invoice_id = ?'); $reversal->execute([$id]);
    return ['payments' => $rows, 'settled' => $settled, 'pending' => $pending, 'reversal' => $reversal->fetch() ?: null];
}

function invoiceRecomputePaymentState(PDO $db, array $invoice): void
{
    $details = invoicePaymentDetails($db, (int) $invoice['id']);
    $state = 'not_paid';
    if ($details['reversal']) $state = 'reversed';
    elseif (bccomp($details['settled'], $invoice['total_amount'], 2) >= 0) $state = 'paid';
    elseif (bccomp($details['settled'], '0', 2) > 0) $state = 'partial';
    elseif (bccomp($details['pending'], '0', 2) > 0) $state = 'in_payment';
    $db->prepare('UPDATE accounting_invoices SET payment_state = ? WHERE id = ?')->execute([$state, $invoice['id']]);
}

function transitionInvoiceStatus(PDO $db, int $id, ?int $version, string $action, array $input): void
{
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM accounting_invoices WHERE id = ? FOR UPDATE'); $query->execute([$id]); $invoice = $query->fetch();
        if (! $invoice || (int) $invoice['version'] !== $version) throw new InvalidArgumentException('La factura cambió en otra ventana. Recarga antes de continuar.');
        $details = invoicePaymentDetails($db, $id);
        if ($action === 'cancel_invoice') {
            if ($invoice['status'] === 'cancel' || $details['reversal'] || bccomp(bcadd($details['pending'], $details['settled'], 2), '0', 2) > 0) throw new InvalidArgumentException('Para cancelar la factura no debe tener pagos activos ni una reversión.');
            $db->prepare("UPDATE accounting_invoices SET status = 'cancel' WHERE id = ?")->execute([$id]);
        } else {
            if ($invoice['status'] !== 'posted' || $details['reversal']) throw new InvalidArgumentException('La acción requiere una factura registrada y no revertida.');
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
                if (bccomp(bcadd(bcadd($details['settled'], $details['pending'], 2), $amount, 2), $invoice['total_amount'], 2) > 0) throw new InvalidArgumentException('El pago supera el saldo pendiente de la factura.');
                $db->prepare('INSERT INTO accounting_invoice_payments (invoice_id, amount, payment_date, reference) VALUES (?, ?, ?, ?)')->execute([$id, $amount, $date, $reference]);
            } elseif (in_array($action, ['settle_payment', 'cancel_payment'], true)) {
                $paymentId = salesId($input['payment_id'] ?? null); $payment = null;
                foreach ($details['payments'] as $row) if ((int) $row['id'] === $paymentId) $payment = $row;
                if (! $payment || $payment['status'] === 'cancel' || ($action === 'settle_payment' && $payment['status'] !== 'pending')) throw new InvalidArgumentException('El pago solicitado no admite esa acción.');
                $db->prepare('UPDATE accounting_invoice_payments SET status = ? WHERE id = ? AND invoice_id = ?')->execute([$action === 'settle_payment' ? 'settled' : 'cancel', $paymentId, $id]);
            } elseif ($action === 'reverse_invoice') {
                if (bccomp(bcadd($details['settled'], $details['pending'], 2), '0', 2) > 0) throw new InvalidArgumentException('Cancela los pagos activos antes de revertir esta factura.');
                $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
                if ($reason === '' || mb_strlen($reason) > 255) throw new InvalidArgumentException('Indica un motivo de reversión de hasta 255 caracteres.');
                $db->prepare('INSERT INTO accounting_invoice_reversals (invoice_id, reversal_date, reason, amount) VALUES (?, ?, ?, ?)')->execute([$id, commerceToday(), $reason, $invoice['total_amount']]);
                $reversalId = (int) $db->lastInsertId();
                $db->prepare('UPDATE accounting_invoice_reversals SET name = ? WHERE id = ?')->execute(['REV' . str_pad((string) $reversalId, 5, '0', STR_PAD_LEFT), $reversalId]);
            } else throw new InvalidArgumentException('La acción de factura no es válida.');
            invoiceRecomputePaymentState($db, $invoice);
        }
        $db->prepare('UPDATE accounting_invoices SET version = version + 1 WHERE id = ?')->execute([$id]);
        $db->commit();
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}
