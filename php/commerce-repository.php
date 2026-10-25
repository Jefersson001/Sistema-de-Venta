<?php
declare(strict_types=1);
require_once __DIR__ . '/sales-repository.php';
require_once __DIR__ . '/sales-delivery-repository.php';
require_once __DIR__ . '/workflow-invoices.php';

function purchaseStatuses(): array { return ['draft' => 'Solicitud de cotización', 'sent' => 'Solicitud enviada', 'purchase' => 'Orden de compra', 'cancel' => 'Cancelado']; }

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
    $products = $db->query('SELECT id, name, purchase_enabled, purchase_tax_id AS tax_id, cost_price AS price, product_type, invoice_policy, cost_price, currency_code FROM inventory_products ORDER BY name, id')->fetchAll();
    $assigned = array_map(static fn ($line) => (int) $line['product_id'], $existing['lines'] ?? []);
    $products = array_values(array_filter($products, static fn ($product) => $product['purchase_enabled'] || in_array((int) $product['id'], $assigned, true)));
    if(!permissionHas('inventario.products'))$products=[];
    if(!permissionHas('inventario.products','view_cost'))foreach($products as &$product){$product['price']='0.00';$product['cost_price']='0.00';}unset($product);
    foreach($products as &$product)$product['sale_price']=$product['price'];unset($product);
    return ['settings'=>$existing?['primary_code'=>$existing['currency_primary_code'],'alternate_code'=>$existing['currency_alternate_code'],'exchange_rate'=>$existing['exchange_rate']]:accountingCurrencies($db),'customers' => permissionFilterContacts($contacts->fetchAll()), 'products' => $products, 'supplierPrices' => permissionHas('inventario.products','view_cost')?$db->query('SELECT product_id,supplier_id,price FROM product_supplier_prices')->fetchAll():[],
        'taxes' => $db->query("SELECT id, name, rate FROM business_taxes WHERE module_key = 'compras' ORDER BY name, id")->fetchAll(),
        'terms' => $db->query('SELECT id, name FROM accounting_payment_terms ORDER BY name, id')->fetchAll(), 'salespeople' => [], 'storage' => loadStorageRelations($db)];
}

function validatePurchaseOrder(array $input, array $catalogs, ?array $existing): array
{
    $mapped = $input; $mapped['customer_id'] = $input['supplier_id'] ?? '';
    if(is_array($mapped['lines']??null))foreach($mapped['lines'] as &$raw) {
        if(is_array($raw) && !isset($raw['unit_price']))foreach($catalogs['supplierPrices'] as $supplierPrice)if((int)$supplierPrice['supplier_id']===salesId($input['supplier_id']??null) && (int)$supplierPrice['product_id']===salesId($raw['product_id']??null))$raw['unit_price']=$supplierPrice['price'];
    }unset($raw);
    $mode = $input['order_type'] ?? ($existing['order_type'] ?? 'receipt');
    $mapped['order_type'] = $mode === 'receipt' ? 'delivery' : $mode;
    $mapped['salesperson_id'] = '';
    $old = $existing ? ['customer_id' => $existing['supplier_id'], 'invoice_address' => $existing['invoice_address'], 'delivery_address' => $existing['invoice_address'], 'lines' => $existing['lines'], 'currency_code'=>$existing['currency_code'],'exchange_rate'=>$existing['exchange_rate']] : null;
    [$data, $lines, $errors] = validateSalesQuotation($mapped, $catalogs, $old);
    if (! is_string($mode) || ! in_array($mode, ['receipt', 'invoice'], true)) { $errors[] = 'Selecciona un tipo de orden de compra válido.'; }
    $data['supplier_id'] = $data['customer_id']; $data['order_type'] = $mode === 'invoice' ? 'invoice' : 'receipt';
    unset($data['customer_id'], $data['salesperson_id'], $data['delivery_address'], $data['warehouse_id'], $data['location_id'], $data['storage_label']);
    $location = salesId($input['destination_location_id'] ?? null);
    $destination = storageRecord($catalogs['storage']['locations'], $location);
    if (array_filter($lines, static fn($l)=>$l['product_type']==='goods') && (! $destination || $destination['location_type'] !== 'internal')) { $errors[] = 'Selecciona una ubicación interna de destino para la recepción.'; }
    // Invoicing first never removes the physical destination of goods.
    $data['destination_location_id'] = $location;
    $errors = array_map(static fn ($error) => str_replace(['cliente', 'Venta', 'Ventas', 'cotización'], ['proveedor', 'Compra', 'Compras', 'solicitud'], $error), $errors);
    return [$data, $lines, $errors];
}

function savePurchaseOrder(PDO $db, array $data, array $lines, ?int $id, ?int $version): int
{
    workflowBegin($db);
    try {
        if ($id) {
            $lock = $db->prepare('SELECT status, version FROM purchase_orders WHERE id = ? FOR UPDATE'); $lock->execute([$id]); $current = $lock->fetch();
            if (! $current || (int) $current['version'] !== $version) throw new InvalidArgumentException('La orden cambió en otra ventana. Recarga antes de guardar.');
            if ($current['status'] === 'purchase') throw new InvalidArgumentException('La orden de compra confirmada no admite cambios.');
            if ($current['status'] === 'cancel') throw new InvalidArgumentException('La orden de compra cancelada no admite cambios.');
            $db->prepare('UPDATE purchase_orders SET ' . implode(', ', array_map(static fn ($key) => $key . ' = :' . $key, array_keys($data))) . ', version = version + 1 WHERE id = :id')->execute($data + ['id' => $id]);
            $db->prepare('DELETE FROM purchase_order_lines WHERE order_id = ?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO purchase_orders (name, ' . implode(', ', array_keys($data)) . ') VALUES (:temporary_reference, :' . implode(', :', array_keys($data)) . ')')->execute($data + ['temporary_reference' => bin2hex(random_bytes(16))]);
            $id = (int) $db->lastInsertId(); $db->prepare('UPDATE purchase_orders SET name = ? WHERE id = ?')->execute(['P' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
        }
        $insert = $db->prepare('INSERT INTO purchase_order_lines (order_id, position, product_id, product_name, quantity, unit_price, tax_id, tax_name, tax_rate, tax_amount, product_type, invoice_policy, unit_cost, base_amount, total_amount)
            VALUES (:order_id, :position, :product_id, :product_name, :quantity, :unit_price, :tax_id, :tax_name, :tax_rate, :tax_amount, :product_type, :invoice_policy, :unit_cost, :base_amount, :total_amount)');
        foreach ($lines as $position => $line) { $insert->execute($line + ['order_id' => $id, 'position' => $position]); }
        $db->commit(); return $id;
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}

function transitionPurchaseOrder(PDO $db, int $id, ?int $version, string $target): void
{
    workflowBegin($db);
    try {
        $lock = $db->prepare('SELECT status, version FROM purchase_orders WHERE id = ? FOR UPDATE'); $lock->execute([$id]); $order = $lock->fetch();
        if (! $order || (int) $order['version'] !== $version) throw new InvalidArgumentException('La orden cambió. Recarga antes de continuar.');
        if ($target === 'cancel') {
            if (!in_array($order['status'], ['draft', 'sent', 'purchase'], true)) throw new InvalidArgumentException('La orden de compra ya está cancelada.');
            cancelPendingOrderOperations($db, 'purchase', $id);
            $db->prepare("UPDATE purchase_orders SET status='cancel',version=version+1 WHERE id=?")->execute([$id]);
            $db->commit(); return;
        }
        if (!(($target === 'sent' && $order['status'] === 'draft') || ($target === 'purchase' && in_array($order['status'], ['draft', 'sent'], true)))) throw new InvalidArgumentException('El cambio de estado no es válido.');
        $lines = $db->prepare('SELECT COUNT(*) FROM purchase_order_lines WHERE order_id = ?'); $lines->execute([$id]);
        if (! $lines->fetchColumn()) throw new InvalidArgumentException('Agrega al menos un producto antes de confirmar.');
        $db->prepare('UPDATE purchase_orders SET status = ?, approved_date = ?, version = version + 1 WHERE id = ?')->execute([$target, $target === 'purchase' ? commerceToday() : null, $id]);
        $db->commit();
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}

function commerceLinks(PDO $db, string $kind, int $id): array
{
    $source = $kind === 'sale' ? loadSalesQuotation($db,$id) : loadPurchaseOrder($db,$id);
    $q=$db->prepare('SELECT id,name,status,operation_type,return_of_id FROM inventory_operations WHERE '.($kind==='sale'?'quotation_id':'purchase_order_id').'=? ORDER BY id'); $q->execute([$id]);$operations=$q->fetchAll();
    $q=$db->prepare('SELECT id,name,status,invoice_type,document_type FROM accounting_invoices WHERE '.($kind==='sale'?'sale_id':'purchase_id').'=? ORDER BY id');$q->execute([$id]);$invoices=$q->fetchAll();
    $progress=commerceLineProgress($db,$kind,$id);$can=false;$stock=false;
    foreach($progress as $l){$can=$can||bccomp($l['to_invoice'],'0',2)>0;$stock=$stock||($l['product_type']==='goods' && bccomp($l['pending'],'0',2)>0);}
    $active=array_values(array_filter($invoices,static fn($i)=>$i['status']!=='cancel' && $i['document_type']==='invoice'));
    $confirmed=$source && $source['status']===($kind==='sale'?'sale':'purchase');
    return ['source'=>$source,'operation'=>$operations[0]??null,'invoice'=>$active?end($active):null,'operations'=>$operations,'invoices'=>$invoices,'progress'=>$progress,'can_invoice'=>$confirmed&&$can,'can_stock'=>$confirmed&&$stock];
}

function createPurchaseReceipt(PDO $db, int $id): int { return createLinkedStockOperation($db, 'purchase', $id); }

function createCommerceInvoice(PDO $db, string $kind, int $id): int {
    if (!in_array($kind,['sale','purchase'],true)) throw new InvalidArgumentException('Origen de factura inválido.');
    return workflowCreateInvoice($db,$kind,$id);
}
