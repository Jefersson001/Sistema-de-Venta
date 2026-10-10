<?php
declare(strict_types=1);
require_once __DIR__ . '/sales-repository.php';
require_once __DIR__ . '/sales-delivery-repository.php';

function commerceToday(): string { return (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->format('Y-m-d'); }
function purchaseStatuses(): array { return ['draft' => 'Solicitud de cotización', 'sent' => 'Solicitud enviada', 'purchase' => 'Orden de compra']; }

function loadPurchaseOrder(PDO $db, int $id): ?array
{
    $query = $db->prepare('SELECT * FROM purchase_orders WHERE id = ?'); $query->execute([$id]); $order = $query->fetch();
    if (! $order) return null;
    $query = $db->prepare('SELECT l.*, p.name AS current_product_name FROM purchase_order_lines l JOIN inventory_products p ON p.id = l.product_id WHERE l.order_id = ? ORDER BY l.position, l.id'); $query->execute([$id]); $order['lines'] = $query->fetchAll();
    $legacy = false; $total = '0.00';
    foreach ($order['lines'] as &$line) {
        if ($line['product_name'] === null) {
            $legacy = true; $line['product_name'] = $line['current_product_name'];
            $line['base_amount'] = bcdiv(bcadd(bcmul($line['quantity'], $line['unit_price'], 5), '0.005', 5), '1', 2); $line['total_amount'] = $line['base_amount'];
        }
        unset($line['current_product_name']); $total = bcadd($total, $line['total_amount'], 2);
    }
    unset($line);
    if ($legacy) { $order['base_amount'] = $total; $order['total_amount'] = $total; }
    return $order;
}

function loadPurchaseCatalogs(PDO $db, ?array $existing): array
{
    $contacts = $db->prepare("SELECT c.*, country.name AS country_name, state.name AS state_name, city.name AS city_name FROM contacts c JOIN contact_types t ON t.id = c.type_id
        LEFT JOIN contact_countries country ON country.id = c.country_id LEFT JOIN contact_states state ON state.id = c.state_id LEFT JOIN contact_cities city ON city.id = c.city_id
        WHERE (t.system_key = 'supplier' AND c.active = 1) OR c.id = ? ORDER BY c.name, c.id");
    $contacts->execute([$existing['supplier_id'] ?? null]);
    $products = $db->query('SELECT id, name, purchase_enabled, purchase_tax_id AS tax_id, 0.00 AS price FROM inventory_products ORDER BY name, id')->fetchAll();
    $assigned = array_map(static fn ($line) => (int) $line['product_id'], $existing['lines'] ?? []);
    $products = array_values(array_filter($products, static fn ($product) => $product['purchase_enabled'] || in_array((int) $product['id'], $assigned, true)));
    return ['customers' => $contacts->fetchAll(), 'products' => $products,
        'taxes' => $db->query("SELECT id, name FROM business_taxes WHERE module_key = 'compras' ORDER BY name, id")->fetchAll(),
        'terms' => $db->query('SELECT id, name FROM accounting_payment_terms ORDER BY name, id')->fetchAll(), 'salespeople' => [], 'storage' => loadStorageRelations($db)];
}

function validatePurchaseOrder(array $input, array $catalogs, ?array $existing): array
{
    $mapped = $input; $mapped['customer_id'] = $input['supplier_id'] ?? '';
    $mode = $input['order_type'] ?? ($existing['order_type'] ?? 'receipt');
    $mapped['order_type'] = $mode === 'receipt' ? 'delivery' : $mode;
    $mapped['salesperson_id'] = '';
    $old = $existing ? ['customer_id' => $existing['supplier_id'], 'invoice_address' => $existing['invoice_address'], 'delivery_address' => $existing['invoice_address']] : null;
    [$data, $lines, $errors] = validateSalesQuotation($mapped, $catalogs, $old);
    if (! is_string($mode) || ! in_array($mode, ['receipt', 'invoice'], true)) { $errors[] = 'Selecciona un tipo de orden de compra válido.'; }
    $data['supplier_id'] = $data['customer_id']; $data['order_type'] = $mode === 'invoice' ? 'invoice' : 'receipt';
    unset($data['customer_id'], $data['salesperson_id'], $data['delivery_address'], $data['warehouse_id'], $data['location_id'], $data['storage_label']);
    $location = salesId($input['destination_location_id'] ?? null);
    $destination = storageRecord($catalogs['storage']['locations'], $location);
    if ($data['order_type'] === 'receipt' && (! $destination || $destination['location_type'] !== 'internal')) { $errors[] = 'Selecciona una ubicación interna de destino para la recepción.'; }
    if ($data['order_type'] === 'invoice') $location = null;
    $data['destination_location_id'] = $location;
    $errors = array_map(static fn ($error) => str_replace(['cliente', 'Venta', 'Ventas', 'cotización'], ['proveedor', 'Compra', 'Compras', 'solicitud'], $error), $errors);
    return [$data, $lines, $errors];
}

function savePurchaseOrder(PDO $db, array $data, array $lines, ?int $id, ?int $version): int
{
    $db->beginTransaction();
    try {
        if ($id) {
            $lock = $db->prepare('SELECT status, version FROM purchase_orders WHERE id = ? FOR UPDATE'); $lock->execute([$id]); $current = $lock->fetch();
            if (! $current || (int) $current['version'] !== $version) throw new InvalidArgumentException('La orden cambió en otra ventana. Recarga antes de guardar.');
            if ($current['status'] === 'purchase') throw new InvalidArgumentException('La orden de compra confirmada no admite cambios.');
            $db->prepare('UPDATE purchase_orders SET ' . implode(', ', array_map(static fn ($key) => $key . ' = :' . $key, array_keys($data))) . ', version = version + 1 WHERE id = :id')->execute($data + ['id' => $id]);
            $db->prepare('DELETE FROM purchase_order_lines WHERE order_id = ?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO purchase_orders (name, ' . implode(', ', array_keys($data)) . ') VALUES (:temporary_reference, :' . implode(', :', array_keys($data)) . ')')->execute($data + ['temporary_reference' => bin2hex(random_bytes(16))]);
            $id = (int) $db->lastInsertId(); $db->prepare('UPDATE purchase_orders SET name = ? WHERE id = ?')->execute(['P' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
        }
        $insert = $db->prepare('INSERT INTO purchase_order_lines (order_id, position, product_id, product_name, quantity, unit_price, tax_id, tax_name, base_amount, total_amount)
            VALUES (:order_id, :position, :product_id, :product_name, :quantity, :unit_price, :tax_id, :tax_name, :base_amount, :total_amount)');
        foreach ($lines as $position => $line) { $insert->execute($line + ['order_id' => $id, 'position' => $position]); }
        $db->commit(); return $id;
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}

function transitionPurchaseOrder(PDO $db, int $id, ?int $version, string $target): void
{
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT status, version FROM purchase_orders WHERE id = ? FOR UPDATE'); $lock->execute([$id]); $order = $lock->fetch();
        if (! $order || (int) $order['version'] !== $version) throw new InvalidArgumentException('La orden cambió. Recarga antes de continuar.');
        if (!(($target === 'sent' && $order['status'] === 'draft') || ($target === 'purchase' && in_array($order['status'], ['draft', 'sent'], true)))) throw new InvalidArgumentException('El cambio de estado no es válido.');
        $lines = $db->prepare('SELECT COUNT(*) FROM purchase_order_lines WHERE order_id = ?'); $lines->execute([$id]);
        if (! $lines->fetchColumn()) throw new InvalidArgumentException('Agrega al menos un producto antes de confirmar.');
        $db->prepare('UPDATE purchase_orders SET status = ?, approved_date = ?, version = version + 1 WHERE id = ?')->execute([$target, $target === 'purchase' ? commerceToday() : null, $id]);
        $db->commit();
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}

function commerceLinks(PDO $db, string $kind, int $id): array
{
    $source = $kind === 'sale' ? loadSalesQuotation($db, $id) : loadPurchaseOrder($db, $id);
    $column = $kind === 'sale' ? 'quotation_id' : 'purchase_order_id';
    $operationQuery = $db->prepare('SELECT id, name, status, operation_type FROM inventory_operations WHERE ' . $column . ' = ?'); $operationQuery->execute([$id]);
    $operation = $operationQuery->fetch() ?: null;
    $invoiceQuery = $db->prepare('SELECT id, name, status, invoice_type FROM accounting_invoices WHERE ' . ($kind === 'sale' ? 'sale_id' : 'purchase_id') . ' = ?'); $invoiceQuery->execute([$id]);
    $invoice = $invoiceQuery->fetch() ?: null;
    $confirmed = $source && $source['status'] === ($kind === 'sale' ? 'sale' : 'purchase');
    return ['source' => $source, 'operation' => $operation, 'invoice' => $invoice,
        'can_invoice' => $confirmed && ($source['order_type'] === 'invoice' || ($operation['status'] ?? '') === 'done')];
}

function createPurchaseReceipt(PDO $db, int $id): int
{
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE'); $query->execute([$id]); $order = $query->fetch();
        if (! $order || $order['status'] !== 'purchase') throw new InvalidArgumentException('Confirma la orden de compra antes de generar la recepción.');
        $query = $db->prepare('SELECT id FROM inventory_operations WHERE purchase_order_id = ?'); $query->execute([$id]); $existing = $query->fetchColumn();
        if ($existing) { $db->commit(); return (int) $existing; }
        if ($order['order_type'] === 'invoice') throw new InvalidArgumentException('Esta orden es de tipo Factura y no genera recepción.');
        $storage = loadStorageRelations($db);
        $supplier = $db->prepare('SELECT partner_location_id FROM contacts WHERE id = ?'); $supplier->execute([$order['supplier_id']]);
        $origin = storagePartnerLocation($storage, 'supplier', salesId($supplier->fetchColumn()));
        $destination = storageRecord($storage['locations'], salesId($order['destination_location_id']));
        if (! $origin || ! $destination || $destination['location_type'] !== 'internal') throw new InvalidArgumentException('Selecciona ubicaciones válidas para la recepción.');
        $lines = $db->prepare('SELECT product_id, quantity FROM purchase_order_lines WHERE order_id = ? ORDER BY position, id'); $lines->execute([$id]); $records = $lines->fetchAll();
        if (! $records) throw new InvalidArgumentException('La orden no tiene líneas de productos.');
        $db->prepare("INSERT INTO inventory_operations (operation_type, purchase_order_id, contact_id, scheduled_date, source_location_id, destination_location_id, destination_warehouse_id, notes)
            VALUES ('receipt', ?, ?, ?, ?, ?, ?, '')")->execute([$id, $order['supplier_id'], $order['order_date'], $origin['id'], $destination['id'], $destination['warehouse_id']]);
        $operationId = (int) $db->lastInsertId(); $db->prepare('UPDATE inventory_operations SET name = ? WHERE id = ?')->execute(['IN/' . str_pad((string) $operationId, 5, '0', STR_PAD_LEFT), $operationId]);
        $insert = $db->prepare('INSERT INTO inventory_operation_lines (operation_id, position, product_id, demand, quantity) VALUES (?, ?, ?, ?, 0.000)');
        foreach ($records as $position => $line) $insert->execute([$operationId, $position, $line['product_id'], $line['quantity']]);
        $db->commit(); return $operationId;
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}

function createCommerceInvoice(PDO $db, string $kind, int $id): int
{
    if (! in_array($kind, ['sale', 'purchase'], true)) throw new InvalidArgumentException('Origen de factura inválido.');
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT id FROM ' . ($kind === 'sale' ? 'sales_quotations' : 'purchase_orders') . ' WHERE id = ? FOR UPDATE'); $query->execute([$id]);
        if (! $query->fetchColumn()) throw new InvalidArgumentException('El documento de origen no existe.');
        $links = commerceLinks($db, $kind, $id);
        if ($links['invoice']) { $db->commit(); return (int) $links['invoice']['id']; }
        if (! $links['can_invoice']) throw new InvalidArgumentException('Confirma la orden y valida su entrega o recepción antes de generar la factura.');
        $order = $links['source']; $customerInvoice = $kind === 'sale';
        if (! $order['lines']) { throw new InvalidArgumentException('La orden no contiene productos para facturar.'); }
        $address = (string) ($order['invoice_address'] ?? '');
        $db->prepare('INSERT INTO accounting_invoices (invoice_type, sale_id, purchase_id, contact_id, invoice_address, invoice_date, payment_term_id, salesperson_id, base_amount, total_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$customerInvoice ? 'customer' : 'supplier', $customerInvoice ? $id : null, $customerInvoice ? null : $id,
                $order[$customerInvoice ? 'customer_id' : 'supplier_id'], $address, commerceToday(), $order['payment_term_id'], $order['salesperson_id'] ?? null, $order['base_amount'], $order['total_amount']]);
        $invoiceId = (int) $db->lastInsertId();
        $db->prepare('UPDATE accounting_invoices SET name = ? WHERE id = ?')->execute([($customerInvoice ? 'FC' : 'FP') . str_pad((string) $invoiceId, 5, '0', STR_PAD_LEFT), $invoiceId]);
        $insert = $db->prepare('INSERT INTO accounting_invoice_lines (invoice_id, position, product_id, product_name, quantity, unit_price, tax_id, tax_name, base_amount, total_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($order['lines'] as $position => $line) $insert->execute([$invoiceId, $position, $line['product_id'], $line['product_name'], $line['quantity'], $line['unit_price'], $line['tax_id'], $line['tax_name'], $line['base_amount'], $line['total_amount']]);
        $db->commit(); return $invoiceId;
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}
