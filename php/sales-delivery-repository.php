<?php
declare(strict_types=1);
require_once __DIR__ . '/storage-repository.php';

function loadSalesDelivery(PDO $db, int $quotationId): ?array
{
    $query = $db->prepare("SELECT id, name, status FROM inventory_operations WHERE quotation_id = ? AND operation_type = 'delivery'");
    $query->execute([$quotationId]); return $query->fetch() ?: null;
}

function createSalesDelivery(PDO $db, int $quotationId): int
{
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM sales_quotations WHERE id = ? FOR UPDATE');
        $query->execute([$quotationId]); $order = $query->fetch();
        if (! $order || $order['status'] !== 'sale') { throw new InvalidArgumentException('Confirma la orden de venta antes de crear la entrega.'); }
        $existing = loadSalesDelivery($db, $quotationId);
        if ($existing) { $db->commit(); return (int) $existing['id']; }
        $linesQuery = $db->prepare('SELECT * FROM sales_quotation_lines WHERE quotation_id = ? ORDER BY position, id');
        $linesQuery->execute([$quotationId]); $lines = $linesQuery->fetchAll();
        if (! $lines) { throw new InvalidArgumentException('La orden debe tener productos para generar una entrega.'); }
        // Preserve the confirmed order's warehouse. Infer a location only when it is unambiguous.
        $warehouse = $order['warehouse_id']; $location = $order['location_id'];
        if (! $location && $warehouse) {
            $locations = $db->prepare("SELECT id FROM inventory_locations WHERE warehouse_id = ? AND location_type = 'internal' ORDER BY id LIMIT 2");
            $locations->execute([$warehouse]); $ids = $locations->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) { $location = $ids[0]; }
        }
        $customer = $db->prepare('SELECT partner_location_id FROM contacts WHERE id = ?'); $customer->execute([$order['customer_id']]);
        $destination = storagePartnerLocation(loadStorageRelations($db), 'customer', (int) $customer->fetchColumn() ?: null);
        if (! $destination) { throw new InvalidArgumentException('Configura una ubicación externa de cliente.'); }
        $db->prepare("INSERT INTO inventory_operations (operation_type, quotation_id, contact_id, scheduled_date, source_warehouse_id, source_location_id, destination_location_id, notes)
            VALUES ('delivery', ?, ?, ?, ?, ?, ?, '')")->execute([$quotationId, $order['customer_id'], $order['order_date'], $warehouse, $location, $destination['id']]);
        $id = (int) $db->lastInsertId();
        $db->prepare('UPDATE inventory_operations SET name = ? WHERE id = ?')->execute(['OUT/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
        $insert = $db->prepare('INSERT INTO inventory_operation_lines (operation_id, position, product_id, demand, quantity) VALUES (?, ?, ?, ?, ?)');
        foreach ($lines as $position => $line) { $insert->execute([$id, $position, $line['product_id'], $line['quantity'], '0.000']); }
        $db->commit(); return $id;
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}
