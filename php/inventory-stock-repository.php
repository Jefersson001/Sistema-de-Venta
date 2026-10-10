<?php
declare(strict_types=1);
require_once __DIR__ . '/storage-repository.php';

function inventoryQuantity(mixed $value): ?string
{
    if (! is_string($value)) { return null; }
    $value = str_replace(',', '.', trim($value));
    if (! preg_match('/^[0-9]{1,9}(\.[0-9]{1,3})?$/', $value)) { return null; }
    $parts = explode('.', $value);
    return (ltrim($parts[0], '0') ?: '0') . '.' . str_pad($parts[1] ?? '', 3, '0');
}

function loadInventoryOperationLines(PDO $db, int $id): array
{
    $query = $db->prepare('SELECT * FROM inventory_operation_lines WHERE operation_id = ? ORDER BY position, id');
    $query->execute([$id]); return $query->fetchAll();
}

function validateInventoryOperationLines(mixed $input, array $products): array
{
    $lines = []; $errors = [];
    if (! is_array($input) || count($input) > 100) { return [[], ['La operación admite hasta 100 líneas.']]; }
    foreach (array_values($input) as $index => $raw) {
        if (! is_array($raw)) { $errors[] = 'Línea no válida.'; continue; }
        if (($raw['product_id'] ?? '') === '') { continue; }
        $product = inventoryId($raw['product_id']);
        $demand = inventoryQuantity($raw['demand'] ?? '1');
        $quantity = inventoryQuantity($raw['quantity'] ?? '0');
        if (! storageRecord($products, $product)) { $errors[] = 'Línea ' . ($index + 1) . ': selecciona un producto válido.'; }
        if ($demand === null || bccomp($demand, '0', 3) <= 0) { $errors[] = 'Línea ' . ($index + 1) . ': la demanda debe ser positiva, con hasta tres decimales.'; }
        if ($quantity === null) { $errors[] = 'Línea ' . ($index + 1) . ': la cantidad debe ser no negativa, con hasta tres decimales.'; }
        $lines[] = ['product_id' => $product, 'demand' => $demand ?? (is_string($raw['demand'] ?? null) ? $raw['demand'] : ''),
            'quantity' => $quantity ?? (is_string($raw['quantity'] ?? null) ? $raw['quantity'] : '')];
    }
    return [$lines, $errors];
}

function validateInventoryStockOperation(PDO $db, int $id, ?int $version): void
{
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM inventory_operations WHERE id = ? FOR UPDATE');
        $query->execute([$id]); $operation = $query->fetch();
        if (! $operation || (int) $operation['version'] !== $version) { throw new InvalidArgumentException('La operación cambió. Recarga antes de validar.'); }
        if ($operation['status'] === 'done') { throw new InvalidArgumentException('La operación ya está realizada.'); }
        if ($operation['status'] !== 'ready') { throw new InvalidArgumentException('Confirma la operación y comprueba su disponibilidad hasta que esté Listo antes de validar.'); }
        $type = $operation['operation_type'];
        $storage = loadStorageRelations($db);
        $source = inventoryId($operation['source_location_id']);
        $destination = inventoryId($operation['destination_location_id']);
        if (($type === 'receipt' && ! $source) || ($type === 'delivery' && ! $destination)) {
            $partner = $db->prepare('SELECT partner_location_id FROM contacts WHERE id = ?'); $partner->execute([$operation['contact_id']]);
            $external = storagePartnerLocation($storage, $type === 'receipt' ? 'supplier' : 'customer', (int) $partner->fetchColumn() ?: null);
            if ($type === 'receipt') { $source = $external ? (int) $external['id'] : null; }
            else { $destination = $external ? (int) $external['id'] : null; }
            $db->prepare('UPDATE inventory_operations SET source_location_id = ?, destination_location_id = ? WHERE id = ?')->execute([$source, $destination, $id]);
        }
        $sourceType = storageRecord($storage['locations'], $source)['location_type'] ?? null;
        $destinationType = storageRecord($storage['locations'], $destination)['location_type'] ?? null;
        if (! $source || ! $destination || $source === $destination
            || $sourceType !== ($type === 'receipt' ? 'supplier' : 'internal')
            || $destinationType !== ($type === 'delivery' ? 'customer' : 'internal')) {
            throw new InvalidArgumentException('Guarda ubicaciones válidas antes de validar.');
        }
        $lines = loadInventoryOperationLines($db, $id); $deltas = []; $moves = [];
        foreach ($lines as $line) {
            if (bccomp($line['quantity'], '0', 3) <= 0) { continue; }
            foreach (['source' => $source, 'destination' => $destination] as $side => $location) {
                if (! $location) { continue; }
                $change = $side === 'source' ? bcsub('0', $line['quantity'], 3) : $line['quantity'];
                $key = $line['product_id'] . ':' . $location;
                $deltas[$key] ??= ['product_id' => (int) $line['product_id'], 'location_id' => $location, 'quantity' => '0.000'];
                $deltas[$key]['quantity'] = bcadd($deltas[$key]['quantity'], $change, 3);
                $moves[] = ['operation_id' => $id, 'line_id' => $line['id'], 'product_id' => $line['product_id'], 'location_id' => $location, 'quantity' => $change];
            }
        }
        if (! $moves) { throw new InvalidArgumentException('Agrega al menos un producto con cantidad mayor que cero antes de validar.'); }
        // All operations lock balances in the same order, including duplicate product lines.
        uasort($deltas, static fn ($a, $b) => [$a['product_id'], $a['location_id']] <=> [$b['product_id'], $b['location_id']]);
        foreach ($deltas as $delta) {
            $db->prepare('INSERT INTO inventory_stock (product_id, location_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)')->execute([$delta['product_id'], $delta['location_id']]);
            $balance = $db->prepare('SELECT quantity FROM inventory_stock WHERE product_id = ? AND location_id = ? FOR UPDATE');
            $balance->execute([$delta['product_id'], $delta['location_id']]);
            $next = bcadd((string) $balance->fetchColumn(), $delta['quantity'], 3);
            $locationType = storageRecord($storage['locations'], $delta['location_id'])['location_type'];
            if ($locationType === 'internal' && bccomp($next, '0', 3) < 0) { throw new InvalidArgumentException('No hay cantidad suficiente a la mano en la ubicación de origen para uno de los productos.'); }
            if (bccomp($next, '999999999999999.999', 3) > 0 || bccomp($next, '-999999999999999.999', 3) < 0) { throw new InvalidArgumentException('La cantidad supera el máximo admitido.'); }
            $db->prepare('UPDATE inventory_stock SET quantity = ? WHERE product_id = ? AND location_id = ?')->execute([$next, $delta['product_id'], $delta['location_id']]);
        }
        $insert = $db->prepare('INSERT INTO inventory_stock_moves (operation_id, line_id, product_id, location_id, quantity) VALUES (:operation_id, :line_id, :product_id, :location_id, :quantity)');
        foreach ($moves as $move) { $insert->execute($move); }
        $db->prepare("UPDATE inventory_operations SET status = 'done', version = version + 1 WHERE id = ?")->execute([$id]);
        $db->commit();
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}

function loadInventoryProductStock(PDO $db, int $id): array
{
    $query = $db->prepare("SELECT s.product_id, s.location_id, s.quantity FROM inventory_stock s JOIN inventory_locations l ON l.id = s.location_id WHERE s.product_id = ? AND l.location_type = 'internal' ORDER BY s.location_id");
    $query->execute([$id]); $rows = $query->fetchAll(); $total = '0.000';
    foreach ($rows as $row) { $total = bcadd($total, $row['quantity'], 3); }
    return ['total' => $total, 'locations' => $rows];
}

function loadInventoryProductIndicators(PDO $db, int $id): array
{
    $values = ['incoming' => '0', 'outgoing' => '0', 'sold' => null, 'purchased' => '0.000'];
    $pending = $db->prepare("SELECT o.operation_type, COUNT(*) AS quantity
        FROM inventory_operation_lines l JOIN inventory_operations o ON o.id = l.operation_id
        JOIN (SELECT line_id, MIN(created_at) AS moved_at FROM inventory_stock_moves GROUP BY line_id) m ON m.line_id = l.id
        WHERE l.product_id = ? AND o.status = 'done' AND o.operation_type IN ('receipt', 'delivery')
        AND m.moved_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 YEAR)
        GROUP BY o.operation_type");
    $pending->execute([$id]);
    foreach ($pending->fetchAll() as $row) {
        $values[$row['operation_type'] === 'receipt' ? 'incoming' : 'outgoing'] = (string) $row['quantity'];
    }
    if ($db->query("SHOW TABLES LIKE 'sales_quotation_lines'")->fetch()) {
        $sold = $db->prepare("SELECT COALESCE(SUM(l.quantity), 0.000) FROM sales_quotation_lines l JOIN sales_quotations q ON q.id = l.quotation_id
            WHERE l.product_id = :product AND q.status = 'sale' AND q.order_date >= :since");
        $sold->execute(['product' => $id, 'since' => inventorySalesHistorySince()]); $values['sold'] = (string) $sold->fetchColumn();
    }
    $bought = $db->prepare("SELECT COALESCE(SUM(l.quantity), 0.000) FROM purchase_order_lines l JOIN purchase_orders o ON o.id = l.order_id
        WHERE l.product_id = :product AND o.status = 'purchase' AND o.approved_date >= :since");
    $bought->execute(['product' => $id, 'since' => (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->modify('-1 year')->format('Y-m-d')]);
    $values['purchased'] = (string) $bought->fetchColumn();
    return $values;
}

function inventorySalesHistorySince(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->modify('-365 days')->format('Y-m-d');
}
