<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/connection.php';
require_once dirname(__DIR__) . '/php/commerce-repository.php';
require_once dirname(__DIR__) . '/php/inventory-stock-repository.php';
$db = createDatabaseConnection(); $prefix = 'Test circuito ' . bin2hex(random_bytes(8)); $cookie = tempnam(sys_get_temp_dir(), 'commerce-');
$fixtures = []; $purchases = []; $sales = [];
function expectCommerce(bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function commerceRequest(string $path, string $cookie, ?array $post = null): array {
    $curl = curl_init('http://localhost/project/modules/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    if ($body === false) throw new RuntimeException(curl_error($curl)); curl_close($curl);
    expectCommerce(! preg_match('/Fatal error|Warning:|Notice:/', $body), 'La página contiene un error PHP: ' . $path);
    return [$status, $body, $redirect];
}
function commerceToken(string $body): string { expectCommerce(preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $matches) === 1, 'Falta CSRF.'); return $matches[1]; }
function commerceCreated(array $response): int { expectCommerce($response[0] === 303 && preg_match('/id=(\d+)/', $response[2], $matches) === 1, 'No se creó o abrió el documento: ' . $response[1]); return (int) $matches[1]; }
try {
    $roles = array_flip($db->query('SELECT id, system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    $db->prepare('INSERT INTO inventory_warehouses (name) VALUES (?)')->execute([$prefix]); $fixtures['warehouse'] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO inventory_locations (name, warehouse_id) VALUES (?, ?)')->execute([$prefix, $fixtures['warehouse']]); $fixtures['location'] = (int) $db->lastInsertId();
    foreach (['supplier', 'salesperson', 'client'] as $role) {
        $db->prepare('INSERT INTO contacts (name, type_id, address, warehouse_id) VALUES (?, ?, ?, ?)')->execute([$prefix . $role, $roles[$role], 'Dirección ' . $role, $role === 'salesperson' ? $fixtures['warehouse'] : null]); $fixtures[$role] = (int) $db->lastInsertId();
    }
    $db->prepare('UPDATE contacts SET salesperson_id = ? WHERE id = ?')->execute([$fixtures['salesperson'], $fixtures['client']]);
    $db->prepare('INSERT INTO inventory_products (name, sale_enabled, purchase_enabled, sale_price) VALUES (?, 1, 1, 6.00)')->execute([$prefix]); $fixtures['product'] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO accounting_payment_terms (name) VALUES (?)')->execute([$prefix]); $fixtures['term'] = (int) $db->lastInsertId();
    foreach (['compras', 'ventas'] as $module) { $db->prepare('INSERT INTO business_taxes (name, module_key) VALUES (?, ?)')->execute([$prefix, $module]); $fixtures[$module . '_tax'] = (int) $db->lastInsertId(); }
    foreach (['purchase', 'sale'] as $kind) {
        $module = $kind === 'purchase' ? 'compras' : 'ventas'; $party = $kind === 'purchase' ? 'supplier' : 'client';
        [$status, $form] = commerceRequest($module . '/index.php?new=1', $cookie); expectCommerce($status === 200, 'No se abrió el formulario de orden.'); $token = commerceToken($form);
        $post = ['csrf' => $token, 'action' => $kind === 'purchase' ? 'save_purchase' : 'save_quotation', 'order_date' => commerceToday(),
            $kind === 'purchase' ? 'supplier_id' : 'customer_id' => $fixtures[$party], 'payment_term_id' => $fixtures['term'],
            'order_type' => $kind === 'purchase' ? 'receipt' : 'delivery', 'destination_location_id' => $fixtures['location'],
            'lines' => [['product_id' => $fixtures['product'], 'quantity' => '2.500', 'unit_price' => $kind === 'purchase' ? '4.00' : '6.00', 'tax_id' => $fixtures[$module . '_tax']]]];
        $id = commerceCreated(commerceRequest($module . '/index.php?new=1', $cookie, $post));
        if ($kind === 'purchase') $purchases[] = $id; else $sales[] = $id;
        $order = $kind === 'purchase' ? loadPurchaseOrder($db, $id) : loadSalesQuotation($db, $id);
        if ($kind === 'purchase') {
            $invalid = $post; $invalid['order_type'] = 'delivery';
            $response = commerceRequest('compras/index.php?new=1', $cookie, $invalid);
            expectCommerce($response[0] === 200 && str_contains($response[1], 'tipo de orden'), 'Compra aceptó un tipo de orden de venta.');
            $invalid = $post; $invalid['lines'][0]['tax_id'] = $fixtures['ventas_tax'];
            $response = commerceRequest('compras/index.php?new=1', $cookie, $invalid);
            expectCommerce($response[0] === 200 && str_contains($response[1], 'notice-error'), 'Compra aceptó un impuesto de Ventas.');
            $post['version'] = '1';
            $response = commerceRequest('compras/index.php?id=' . $id, $cookie, $post); expectCommerce($response[0] === 303, 'No se editó la solicitud.');
            $response = commerceRequest('compras/index.php?id=' . $id, $cookie, $post); expectCommerce($response[0] === 200 && str_contains($response[1], 'otra ventana'), 'Se sobrescribió una versión de compra más reciente.');
            $order = loadPurchaseOrder($db, $id);
        }
        expectCommerce($order['lines'][0]['quantity'] === '2.500' && $order['total_amount'] === ($kind === 'purchase' ? '10.00' : '15.00'), 'No se calcularon los importes de la orden.');
        $response = commerceRequest($module . '/index.php?id=' . $id, $cookie, ['csrf' => $token, 'action' => 'create_invoice']); expectCommerce($response[0] === 200 && str_contains($response[1], 'Confirma'), 'Se facturó una orden sin confirmar.');
        commerceCreated(commerceRequest($module . '/index.php?id=' . $id, $cookie, ['csrf' => $token, 'action' => $kind === 'purchase' ? 'confirm_purchase' : 'confirm_sale', 'version' => $order['version']]));
        $operationAction = $kind === 'purchase' ? 'create_receipt' : 'create_delivery'; $operationType = $kind === 'purchase' ? 'receipt' : 'delivery';
        $operation = commerceCreated(commerceRequest($module . '/index.php?id=' . $id, $cookie, ['csrf' => $token, 'action' => $operationAction]));
        expectCommerce(commerceCreated(commerceRequest($module . '/index.php?id=' . $id, $cookie, ['csrf' => $token, 'action' => $operationAction])) === $operation, 'Se duplicó la entrega o recepción.');
        $operationPath = 'inventario/operaciones.php?type=' . $operationType . '&id=' . $operation;
        [$status, $operationForm] = commerceRequest($operationPath, $cookie); $operationToken = commerceToken($operationForm);
        expectCommerce(str_contains($operationForm, '../' . $module . '/index.php?id=' . $id), 'La operación no permite volver a su orden.');
        $blocked = commerceRequest($operationPath, $cookie, ['csrf' => $operationToken, 'action' => 'create_invoice']); expectCommerce($blocked[0] === 200 && str_contains($blocked[1], 'valida'), 'Se facturó antes de validar el movimiento.');
        $saveOperation = ['csrf' => $operationToken, 'action' => 'save_operation', 'version' => '1', 'contact_id' => $fixtures[$party], 'scheduled_date' => commerceToday(),
            'source_location_id' => $fixtures['location'], 'destination_location_id' => $fixtures['location'], 'notes' => $prefix,
            'lines' => [['product_id' => $fixtures['product'], 'demand' => '2.500', 'quantity' => '2.500']]];
        commerceCreated(commerceRequest($operationPath, $cookie, $saveOperation));
        commerceCreated(commerceRequest($operationPath, $cookie, ['csrf' => $operationToken, 'action' => 'confirm_operation', 'version' => '2']));
        commerceCreated(commerceRequest($operationPath, $cookie, ['csrf' => $operationToken, 'action' => 'check_ready', 'version' => '3']));
        commerceCreated(commerceRequest($operationPath, $cookie, ['csrf' => $operationToken, 'action' => 'validate_operation', 'version' => '4']));
        $invoiceId = commerceCreated(commerceRequest($operationPath, $cookie, ['csrf' => $operationToken, 'action' => 'create_invoice']));
        expectCommerce(commerceCreated(commerceRequest($module . '/index.php?id=' . $id, $cookie, ['csrf' => $token, 'action' => 'create_invoice'])) === $invoiceId, 'Se duplicó la factura desde otro acceso.');
        $invoiceType = $kind === 'purchase' ? 'supplier' : 'customer'; $invoicePath = 'contabilidad/index.php?type=' . $invoiceType . '&id=' . $invoiceId;
        [$status, $invoiceForm] = commerceRequest($invoicePath, $cookie);
        $invoiceTarget = $kind === 'sale' ? '../ventas/index.php?id=' . $id : 'operaciones.php?type=receipt&amp;id=' . $operation;
        expectCommerce($status === 200 && str_contains($invoiceForm, $invoiceTarget), 'La factura no enlaza a su venta o recepción.');
        $invoiceDom = new DOMDocument(); libxml_use_internal_errors(true); $invoiceDom->loadHTML('<?xml encoding="UTF-8">' . $invoiceForm); $invoiceXpath = new DOMXPath($invoiceDom);
        expectCommerce($invoiceXpath->query('//*[@aria-label="Documentos relacionados"]//a')->length === 1
            && $invoiceXpath->query('//*[@aria-label="Documentos relacionados"]//*[@data-related-invoice]')->length === 0
            && $invoiceXpath->query('//*[@aria-label="Documentos relacionados"]//*[@data-related-source]')->length === ($kind === 'sale' ? 1 : 0)
            && $invoiceXpath->query('//*[@aria-label="Documentos relacionados"]//*[@data-related-operation]')->length === ($kind === 'sale' ? 0 : 1),
            'La factura no muestra únicamente su venta o recepción relacionada.');
        $invoiceQuery = $db->prepare('SELECT * FROM accounting_invoices WHERE id = ?'); $invoiceQuery->execute([$invoiceId]); $invoice = $invoiceQuery->fetch();
        expectCommerce($invoice['invoice_type'] === $invoiceType && (int) $invoice['contact_id'] === $fixtures[$party] && $invoice['invoice_address'] === 'Dirección ' . $party && (int) $invoice['payment_term_id'] === $fixtures['term'], 'La factura no copió contacto, dirección o términos.');
        $invoiceLines = $db->prepare('SELECT * FROM accounting_invoice_lines WHERE invoice_id = ?'); $invoiceLines->execute([$invoiceId]); $invoiceLine = $invoiceLines->fetch();
        expectCommerce($invoiceLine['quantity'] === '2.500' && $invoiceLine['unit_price'] === $post['lines'][0]['unit_price'] && (int) $invoiceLine['tax_id'] === $fixtures[$module . '_tax'] && $invoiceLine['product_name'] === $prefix, 'No se copiaron las líneas de factura.');
        $invoicePost = ['csrf' => commerceToken($invoiceForm), 'action' => 'confirm_invoice', 'version' => '1', 'invoice_date' => commerceToday(), 'due_date' => ''];
        $invalidDates = $invoicePost; $invalidDates['invoice_date'] = '2026-02-31';
        $blocked = commerceRequest($invoicePath, $cookie, $invalidDates); expectCommerce($blocked[0] === 200 && str_contains($blocked[1], 'fechas válidas'), 'La factura aceptó una fecha inválida.');
        commerceCreated(commerceRequest($invoicePath, $cookie, $invoicePost));
        $invoicePost['action'] = 'save_invoice'; $invoicePost['version'] = '2';
        $blocked = commerceRequest($invoicePath, $cookie, $invoicePost); expectCommerce($blocked[0] === 200 && str_contains($blocked[1], 'no admite cambios'), 'Se editó una factura confirmada.');
        [$status, $operationForm] = commerceRequest($operationPath, $cookie);
        $operationDom = new DOMDocument(); $operationDom->loadHTML('<?xml encoding="UTF-8">' . $operationForm); $operationXpath = new DOMXPath($operationDom);
        expectCommerce($operationXpath->query('//*[@aria-label="Documentos relacionados"]//a')->length === 1
            && $operationXpath->query('//*[@aria-label="Documentos relacionados"]//*[@data-related-source]')->length === 1
            && $operationXpath->query('//*[@aria-label="Documentos relacionados"]//*[@data-related-operation or @data-related-invoice or @data-quotation-invoice]')->length === 0,
            'La entrega/recepción debe mostrar solo el botón a su orden de origen.');
        [$status, $orderForm] = commerceRequest($module . '/index.php?id=' . $id, $cookie);
        expectCommerce(str_contains($orderForm, 'Factura · 1') && str_contains($orderForm, 'data-related-operation') && str_contains($orderForm, 'data-related-invoice'), 'La orden perdió sus botones a la factura y operación.');
        [$status, $invoiceList] = commerceRequest('contabilidad/index.php?type=' . $invoiceType . '&status=posted&q=' . rawurlencode($invoice['name']), $cookie);
        expectCommerce($status === 200 && str_contains($invoiceList, $invoice['name']) && ! str_contains($invoiceList, 'value="confirm_invoice"'), 'La lista de facturas no está separada del formulario.');
        // Direct invoice mode skips inventory, preserving the same source lines.
        $post['order_type'] = 'invoice'; $post['lines'][0]['quantity'] = '1.000'; unset($post['destination_location_id']);
        $directId = commerceCreated(commerceRequest($module . '/index.php?new=1', $cookie, $post));
        if ($kind === 'purchase') $purchases[] = $directId; else $sales[] = $directId;
        commerceCreated(commerceRequest($module . '/index.php?id=' . $directId, $cookie, ['csrf' => $token, 'version' => '1', 'action' => $kind === 'purchase' ? 'confirm_purchase' : 'confirm_sale']));
        $directInvoice = commerceCreated(commerceRequest($module . '/index.php?id=' . $directId, $cookie, ['csrf' => $token, 'action' => 'create_invoice']));
        expectCommerce(commerceLinks($db, $kind, $directId)['operation'] === null && $directInvoice !== $invoiceId, 'Factura directa generó movimientos o reutilizó otro documento.');
        [$status, $directInvoiceForm] = commerceRequest('contabilidad/index.php?type=' . $invoiceType . '&id=' . $directInvoice, $cookie);
        expectCommerce($status === 200 && str_contains($directInvoiceForm, 'data-related-source') && ! str_contains($directInvoiceForm, 'data-related-invoice'), 'La factura directa debe volver a su orden cuando no existe operación.');
        $blocked = commerceRequest($module . '/index.php?id=' . $directId, $cookie, ['csrf' => $token, 'action' => $operationAction]); expectCommerce($blocked[0] === 200 && str_contains($blocked[1], 'tipo Factura'), 'Se generó inventario desde una orden de tipo Factura.');
    }
    expectCommerce(loadInventoryProductStock($db, $fixtures['product'])['total'] === '0.000', 'Facturar alteró existencias o el circuito de inventario es incorrecto.');
    foreach (['compras/index.php?new=1', 'contabilidad/index.php?type=customer'] as $path) { [$status] = commerceRequest($path, $cookie, ['action' => 'create_invoice']); expectCommerce($status === 403, 'Se aceptó un POST sin CSRF.'); }
    echo "PASS: compra → recepción → factura, venta → entrega → factura, navegación bidireccional, copia de líneas, factura directa, duplicados y CSRF.\n";
} finally {
    foreach (['sale' => $sales, 'purchase' => $purchases] as $kind => $ids) foreach ($ids as $id) {
        $invoices = $db->prepare('SELECT id FROM accounting_invoices WHERE ' . ($kind === 'sale' ? 'sale_id' : 'purchase_id') . ' = ?'); $invoices->execute([$id]);
        foreach ($invoices->fetchAll(PDO::FETCH_COLUMN) as $invoiceId) { $db->prepare('DELETE FROM accounting_invoice_lines WHERE invoice_id = ?')->execute([$invoiceId]); $db->prepare('DELETE FROM accounting_invoices WHERE id = ?')->execute([$invoiceId]); }
        $operations = $db->prepare('SELECT id FROM inventory_operations WHERE ' . ($kind === 'sale' ? 'quotation_id' : 'purchase_order_id') . ' = ?'); $operations->execute([$id]);
        foreach ($operations->fetchAll(PDO::FETCH_COLUMN) as $operationId) { $db->prepare('DELETE FROM inventory_stock_moves WHERE operation_id = ?')->execute([$operationId]); $db->prepare('DELETE FROM inventory_operation_lines WHERE operation_id = ?')->execute([$operationId]); $db->prepare('DELETE FROM inventory_operations WHERE id = ?')->execute([$operationId]); }
        $db->prepare('DELETE FROM ' . ($kind === 'sale' ? 'sales_quotation_lines WHERE quotation_id' : 'purchase_order_lines WHERE order_id') . ' = ?')->execute([$id]);
        $db->prepare('DELETE FROM ' . ($kind === 'sale' ? 'sales_quotations' : 'purchase_orders') . ' WHERE id = ?')->execute([$id]);
    }
    if (isset($fixtures['product'])) { $db->prepare('DELETE FROM inventory_stock WHERE product_id = ?')->execute([$fixtures['product']]); $db->prepare('DELETE FROM inventory_products WHERE id = ? AND name = ?')->execute([$fixtures['product'], $prefix]); }
    foreach (['client', 'supplier', 'salesperson'] as $role) if (isset($fixtures[$role])) { $db->prepare('DELETE FROM contacts WHERE id = ? AND name = ?')->execute([$fixtures[$role], $prefix . $role]); }
    foreach (['compras_tax', 'ventas_tax'] as $tax) if (isset($fixtures[$tax])) $db->prepare('DELETE FROM business_taxes WHERE id = ? AND name = ?')->execute([$fixtures[$tax], $prefix]);
    foreach (['term' => 'accounting_payment_terms', 'location' => 'inventory_locations', 'warehouse' => 'inventory_warehouses'] as $key => $table) if (isset($fixtures[$key])) $db->prepare('DELETE FROM ' . $table . ' WHERE id = ? AND name = ?')->execute([$fixtures[$key], $prefix]);
    if ($cookie && is_file($cookie)) unlink($cookie);
}
