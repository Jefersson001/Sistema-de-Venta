<?php
declare(strict_types=1);

function workflowBegin(PDO $db): void
{
    $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
}

function commerceToday(): string { return (new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d'); }

function commerceAmounts(string $quantity, string $price, string $rate = '0'): array
{
    $base = bcdiv(bcadd(bcmul($quantity, $price, 5), '0.005', 5), '1', 2);
    $tax = bcdiv(bcadd(bcdiv(bcmul($base, $rate, 6), '100', 6), '0.005', 6), '1', 2);
    return ['base_amount' => $base, 'tax_amount' => $tax, 'total_amount' => bcadd($base, $tax, 2)];
}

function commerceDueDate(PDO $db, ?int $term, string $date): ?string
{
    if (! $term) return null;
    $q = $db->prepare('SELECT days FROM accounting_payment_terms WHERE id=?'); $q->execute([$term]);
    $days = $q->fetchColumn();
    $due = (new DateTimeImmutable($date))->modify('+' . (int)$days . ' days')->format('Y-m-d');
    if ($due > '9999-12-31') throw new InvalidArgumentException('El vencimiento supera el rango de fechas admitido.');
    return $days === false ? null : $due;
}

function commerceLineProgress(PDO $db, string $kind, int $orderId): array
{
    $isSale = $kind === 'sale'; $lineTable = $isSale ? 'sales_quotation_lines' : 'purchase_order_lines';
    $fk = $isSale ? 'quotation_id' : 'order_id'; $column = $kind . '_line_id';
    $q = $db->prepare("SELECT * FROM $lineTable WHERE $fk=? ORDER BY position,id"); $q->execute([$orderId]); $rows = $q->fetchAll();
    foreach ($rows as &$line) {
        $q = $db->prepare("SELECT COALESCE(SUM(CASE WHEN o.return_of_id IS NULL THEN l.quantity ELSE -l.quantity END),0) FROM inventory_operation_lines l JOIN inventory_operations o ON o.id=l.operation_id WHERE l.$column=? AND o.status='done'"); $q->execute([$line['id']]); $line['moved'] = (string)$q->fetchColumn();
        $q = $db->prepare("SELECT COALESCE(SUM(CASE WHEN i.document_type='invoice' THEN l.quantity ELSE -l.quantity END),0) FROM accounting_invoice_lines l JOIN accounting_invoices i ON i.id=l.invoice_id WHERE l.$column=? AND ((i.document_type='invoice' AND i.status!='cancel') OR (i.document_type='credit' AND i.status='posted'))"); $q->execute([$line['id']]); $line['invoiced'] = (string)$q->fetchColumn();
        $line['pending'] = bccomp($line['quantity'], $line['moved'], 3) > 0 ? bcsub($line['quantity'], $line['moved'], 3) : '0.000';
        $billable = $line['invoice_policy'] === 'ordered' || $line['product_type'] === 'service' ? $line['quantity'] : $line['moved'];
        $line['to_invoice'] = bccomp($billable, $line['invoiced'], 3) > 0 ? bcsub($billable, $line['invoiced'], 3) : '0.000';
    }
    unset($line); return $rows;
}

function workflowLockOrder(PDO $db, array $operation): void
{
    $table = !empty($operation['quotation_id']) ? 'sales_quotations' : (!empty($operation['purchase_order_id']) ? 'purchase_orders' : null);
    if ($table) { $q=$db->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE"); $q->execute([$operation['quotation_id'] ?? $operation['purchase_order_id']]); }
}

function inventoryReserved(PDO $db, int $product, int $location, ?int $exclude = null): string
{
    // Read current committed reservations after locking the stock balance, including
    // a competing reservation committed while this transaction waited for that lock.
    $q=$db->prepare('SELECT quantity FROM inventory_reservations WHERE product_id=? AND location_id=? AND operation_id<>? FOR UPDATE'); $q->execute([$product,$location,$exclude ?? 0]);
    $reserved='0.000';foreach($q->fetchAll(PDO::FETCH_COLUMN) as $quantity)$reserved=bcadd($reserved,$quantity,3);return $reserved;
}

function validateLinkedOperationLines(PDO $db, array $operation, array $lines): array
{
    $column = !empty($operation['quotation_id']) ? 'sale_line_id' : (!empty($operation['purchase_order_id']) ? 'purchase_line_id' : null);
    if (!$column && empty($operation['return_of_id'])) return $lines;
    $existing = loadInventoryOperationLines($db,(int)$operation['id']); $byId=[];
    foreach ($existing as $row) $byId[(int)$row['id']]=$row;
    $progress=[];
    if ($column) foreach(commerceLineProgress($db,$column==='sale_line_id'?'sale':'purchase',(int)($operation['quotation_id']??$operation['purchase_order_id'])) as $row) $progress[(int)$row['id']]=$row;
    $seen=[];
    foreach ($lines as $index => &$line) {
        $old=$byId[(int)($line['id']??0)]??(empty($line['id']) ? ($existing[$index]??null) : null);
        if (!$old || isset($seen[$old['id']]) || (int)$old['product_id']!==(int)$line['product_id'] || bccomp($old['demand'],$line['demand'],3)!==0) throw new InvalidArgumentException('Las líneas relacionadas conservan producto y demanda de su documento de origen. Recarga la operación.');
        $seen[$old['id']]=true;
        $line['id'] = (int)$old['id'];
        if (bccomp($line['quantity'],$old['demand'],3)>0) throw new InvalidArgumentException('La cantidad no puede superar la demanda pendiente del documento de origen.');
        if ($column && empty($operation['return_of_id'])) {
            $source=$progress[(int)$old[$column]]??null;
            if (!$source || $source['product_type']!=='goods' || bccomp($line['quantity'],$source['pending'],3)>0) throw new InvalidArgumentException('La cantidad supera el pendiente de la orden o la línea histórica no tiene una relación verificable.');
        }
        $line['sale_line_id']=$old['sale_line_id']; $line['purchase_line_id']=$old['purchase_line_id']; $line['return_line_id']=$old['return_line_id'];
    }
    unset($line);
    if(count($seen)!==count($existing)) throw new InvalidArgumentException('Conserva todas las líneas relacionadas; indica cero en las que no se moverán.');
    return $lines;
}
