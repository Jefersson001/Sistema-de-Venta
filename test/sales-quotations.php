<?php
declare(strict_types=1);
require_once __DIR__.'/http-session.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/connection.php';
require_once dirname(__DIR__) . '/php/sales-repository.php';
require_once dirname(__DIR__) . '/php/sales-delivery-repository.php';
require_once dirname(__DIR__) . '/php/inventory-stock-repository.php';

$db = createDatabaseConnection();
$base = $argv[1] ?? 'http://localhost/project/modules/';
$cookie = tempnam(sys_get_temp_dir(), 'sales-test-');
$prefix = 'Test ventas ' . bin2hex(random_bytes(8));
$fixtures = [];

function expectSales(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

function salesRequest(string $base, string $path, string $cookie, ?array $post = null): array
{
    $curl = testHttpCurl($base . $path, $cookie ?? $cookieJar ?? $cookieFile ?? null);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    if ($body === false) { throw new RuntimeException(curl_error($curl)); }
    curl_close($curl);
    expectSales(! preg_match('/Fatal error|Warning:|Notice:/', $body), 'La respuesta contiene un error PHP.');
    return [$status, $body, $redirect];
}

try {
    $roles = array_flip($db->query('SELECT id, system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    $db->prepare('INSERT INTO contact_countries (name) VALUES (?)')->execute([$prefix]); $fixtures['country'] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO contact_states (name, country_id) VALUES (?, ?)')->execute([$prefix, $fixtures['country']]); $fixtures['state'] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO contact_cities (name, state_id) VALUES (?, ?)')->execute([$prefix, $fixtures['state']]); $fixtures['city'] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO inventory_warehouses (name) VALUES (?)')->execute([$prefix . ' almacén']); $fixtures['warehouse'] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO inventory_locations (name, warehouse_id) VALUES (?, ?)')->execute([$prefix . ' ubicación', $fixtures['warehouse']]); $fixtures['location'] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO contacts (name, type_id, warehouse_id, location_id) VALUES (?, ?, ?, ?)')->execute([$prefix . ' vendedor', $roles['salesperson'], $fixtures['warehouse'], $fixtures['location']]); $fixtures['seller'] = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO contacts (name, type_id, address, postal_code, country_id, state_id, city_id, salesperson_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$prefix . ' cliente', $roles['client'], 'Calle de prueba', '12345', $fixtures['country'], $fixtures['state'], $fixtures['city'], $fixtures['seller']]);
    $fixtures['customer'] = (int) $db->lastInsertId();
    $db->prepare('UPDATE contacts SET active = 0 WHERE id = ?')->execute([$fixtures['seller']]);
    expectSales(salesRecord(loadSalesCatalogs($db, null)['salespeople'], $fixtures['seller']) !== null, 'No se incluyó el vendedor archivado relacionado al cliente.');
    $db->prepare('UPDATE contacts SET active = 1 WHERE id = ?')->execute([$fixtures['seller']]);
    foreach (['ventas', 'compras'] as $module) {
        $db->prepare('INSERT INTO business_taxes (name, module_key) VALUES (?, ?)')->execute([$prefix, $module]); $fixtures['tax_' . $module] = (int) $db->lastInsertId();
    }
    foreach (['main' => ['19.99', 1], 'small' => ['0.10', 1], 'disabled' => ['1.00', 0]] as $key => [$price, $enabled]) {
        $db->prepare('INSERT INTO inventory_products (name, sale_price, sale_enabled, sale_tax_id) VALUES (?, ?, ?, ?)')->execute([$prefix . ' ' . $key, $price, $enabled, $fixtures['tax_ventas']]);
        $fixtures['product_' . $key] = (int) $db->lastInsertId();
    }
    [$status, $form] = salesRequest($base, 'contabilidad/configuracion.php?section=payment_terms&new=1', $cookie);
    expectSales($status === 200 && ! str_contains($form, '<table') && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'No se abrió el formulario de términos de pago.');
    $termPost = ['csrf' => $matches[1], 'action' => 'save_payment_term', 'name' => $prefix];
    [$status] = salesRequest($base, 'contabilidad/configuracion.php?section=payment_terms&new=1', $cookie, $termPost);
    expectSales($status === 303, 'No se creó el término de pago.');
    $lookup = $db->prepare('SELECT id FROM accounting_payment_terms WHERE name = ?'); $lookup->execute([$prefix]); $fixtures['term'] = (int) $lookup->fetchColumn();
    expectSales($fixtures['term'] > 0, 'El término de pago no persistió.');
    [$status, $body] = salesRequest($base, 'contabilidad/configuracion.php?section=payment_terms', $cookie);
    expectSales($status === 200 && str_contains($body, '<table') && ! str_contains($body, 'value="save_payment_term"'), 'La vista tree de términos de pago mezcla el formulario.');
    [$status, $body] = salesRequest($base, 'contabilidad/configuracion.php?section=payment_terms&new=1', $cookie, $termPost);
    expectSales($status === 200 && str_contains($body, 'Ya existe un término'), 'Se permitió un término duplicado.');
    [$status, $form] = salesRequest($base, 'ventas/index.php?new=1', $cookie);
    expectSales($status === 200 && ! str_contains($form, 'aria-label="Listado de cotizaciones"') && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'No se abrió el formulario de cotizaciones.');
    expectSales(str_contains($form, 'data-address="Calle de prueba, ' . $prefix . ', ' . $prefix . ', 12345, ' . $prefix . '"'), 'No se concatenaron todas las partes de la dirección.');
    expectSales(str_contains($form, 'name="order_type"') && str_contains($form, 'value="delivery" selected') && ! str_contains($form, 'data-quotation-delivery'), 'La cotización nueva no tiene el tipo por defecto o muestra Entrega antes de confirmar.');
    $post = ['csrf' => $matches[1], 'action' => 'save_quotation', 'customer_id' => $fixtures['customer'], 'order_date' => '2026-10-10',
        'payment_term_id' => $fixtures['term'], 'salesperson_id' => '', 'name' => 'CORRELATIVO-FALSO', 'status' => 'sale',
        'invoice_address' => 'Dirección falsa', 'base_amount' => '999999', 'total_amount' => '999999',
        'lines' => [
            ['product_id' => $fixtures['product_main'], 'quantity' => '3.12', 'unit_price' => '19.99', 'tax_id' => $fixtures['tax_ventas']],
            ['product_id' => $fixtures['product_small'], 'quantity' => '0.05', 'unit_price' => '0.10', 'tax_id' => ''],
        ]];
    foreach ([['order_type' => 'invalid'], ['order_type' => ['invoice']], ['customer_id' => $fixtures['seller']], ['order_date' => '2026-02-31'], ['payment_term_id' => 999999999],
        ['salesperson_id' => $fixtures['customer']], ['lines' => [['product_id' => $fixtures['product_disabled'], 'quantity' => '1', 'unit_price' => '1']]],
        ['lines' => [['product_id' => $fixtures['product_main'], 'quantity' => '0', 'unit_price' => '1']]],
        ['lines' => [['product_id' => $fixtures['product_main'], 'quantity' => '1', 'unit_price' => '1', 'tax_id' => $fixtures['tax_compras']]]] ] as $invalid) {
        [$status, $body] = salesRequest($base, 'ventas/index.php?new=1', $cookie, array_replace($post, $invalid));
        expectSales($status === 200 && str_contains($body, 'notice-error'), 'Se aceptaron datos de cotización inválidos.');
    }
    [$status, $body, $redirect] = salesRequest($base, 'ventas/index.php?new=1', $cookie, $post);
    expectSales($status === 303 && preg_match('/id=(\d+)/', $redirect, $matches) === 1, 'No se guardó la cotización.');
    $id = (int) $matches[1]; $quotation = loadSalesQuotation($db, $id);
    [$status, $body] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie, ['csrf' => $post['csrf'], 'action' => 'create_delivery']);
    expectSales($status === 200 && str_contains($body, 'Confirma la orden') && loadSalesDelivery($db, $id) === null, 'Se generó una entrega sin confirmar la venta.');
    expectSales($quotation['order_type'] === 'delivery', 'No se guardó Orden de entrega por defecto.');
    expectSales($quotation['name'] === 'S' . str_pad((string) $id, 5, '0', STR_PAD_LEFT) && $quotation['status'] === 'draft', 'El correlativo o estado no se asignó automáticamente.');
    expectSales($quotation['base_amount'] === '62.38' && $quotation['total_amount'] === '62.38' && count($quotation['lines']) === 2, 'El cálculo decimal o las líneas son incorrectos.');
    expectSales($quotation['invoice_address'] === $quotation['delivery_address'] && str_starts_with($quotation['invoice_address'], 'Calle de prueba'), 'Se aceptaron direcciones falsas.');
    expectSales((int) $quotation['payment_term_id'] === $fixtures['term'] && (int) $quotation['salesperson_id'] === $fixtures['seller'], 'No se guardaron las relaciones.');
    expectSales((int) $quotation['warehouse_id'] === $fixtures['warehouse'] && (int) $quotation['location_id'] === $fixtures['location']
        && $quotation['storage_label'] === $prefix . ' ubicación / ' . $prefix . ' almacén', 'No se trajo el almacén del vendedor relacionado al cliente.');
    $post['version'] = (string) $quotation['version']; $post['lines'][0]['unit_price'] = '20.00'; $post['order_type'] = 'delivery';
    [$status] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie, $post); expectSales($status === 303, 'No se editó la cotización.');
    $edited = loadSalesQuotation($db, $id);
    expectSales($edited['order_type'] === 'delivery', 'No se guardó el tipo Orden de entrega.');
    expectSales($edited['name'] === $quotation['name'] && $edited['total_amount'] === '62.41' && count($edited['lines']) === 2, 'La edición duplicó líneas o alteró el correlativo.');
    [$status, $body] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie, $post);
    expectSales($status === 200 && str_contains($body, 'otra ventana'), 'Se permitió sobrescribir una versión más reciente.');
    foreach (['mark_sent' => 'sent', 'confirm_sale' => 'sale'] as $action => $target) {
        $current = loadSalesQuotation($db, $id);
        [$status] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie,
            ['csrf' => $post['csrf'], 'version' => (string) $current['version'], 'action' => $action]);
        expectSales($status === 303 && loadSalesQuotation($db, $id)['status'] === $target, 'La transición de estado falló.');
        [$status, $stateForm] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie);
        expectSales($status === 200 && str_contains($stateForm, 'data-quotation-delivery') === ($target === 'sale'), 'Entrega debe aparecer únicamente en la orden de venta.');
    }
    expectSales(loadSalesQuotation($db, $id)['order_type'] === 'delivery', 'La confirmación cambió el tipo de orden.');
    expectSales(loadInventoryProductIndicators($db, $fixtures['product_main'])['sold'] === '3.12', 'Vendido no incluye la venta confirmada.');
    [$status, $soldReport] = salesRequest($base, 'inventario/informes.php?view=sold&product_id=' . $fixtures['product_main'], $cookie);
    expectSales($status === 200 && str_contains($soldReport, '3.12') && str_contains($soldReport, '../ventas/index.php?id=' . $id), 'Vendido no abre el detalle filtrado del producto.');
    expectSales(str_contains($soldReport, 'data-report-group-toggle'), 'El análisis de ventas no agrupa por fecha.');
    foreach (['pivot', 'graph'] as $display) {
        [$status, $analysis] = salesRequest($base, 'inventario/informes.php?view=sold&product_id=' . $fixtures['product_main'] . '&display=' . $display, $cookie);
        expectSales($status === 200 && str_contains($analysis, '3.12') && str_contains($analysis, $display === 'pivot' ? 'Importe sin impuestos' : 'report-graph'), 'No se abrió la vista de análisis solicitada.');
    }
    [$status, , $deliveryRedirect] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie, ['csrf' => $post['csrf'], 'action' => 'create_delivery']);
    expectSales($status === 303 && str_contains($deliveryRedirect, 'inventario/operaciones.php?type=delivery&id='), 'El botón Entrega no abrió Inventario.');
    $delivery = loadSalesDelivery($db, $id); expectSales($delivery !== null, 'No se vinculó la entrega a la orden.');
    $lookupDelivery = $db->prepare('SELECT * FROM inventory_operations WHERE id = ?'); $lookupDelivery->execute([$delivery['id']]); $deliveryRecord = $lookupDelivery->fetch();
    expectSales((int) $deliveryRecord['contact_id'] === $fixtures['customer'] && (int) $deliveryRecord['source_location_id'] === $fixtures['location']
        && $deliveryRecord['scheduled_date'] === '2026-10-10' && $deliveryRecord['status'] === 'draft', 'No se copiaron cliente, origen o fecha.');
    $deliveryLines = $db->prepare('SELECT product_id, demand, quantity FROM inventory_operation_lines WHERE operation_id = ? ORDER BY position'); $deliveryLines->execute([$delivery['id']]); $copiedLines = $deliveryLines->fetchAll();
    expectSales(count($copiedLines) === 2 && (int) $copiedLines[0]['product_id'] === $fixtures['product_main'] && $copiedLines[0]['demand'] === '3.12'
        && $copiedLines[1]['demand'] === '0.05' && $copiedLines[0]['quantity'] === '0.00', 'No se copiaron las líneas y sus demandas.');
    [$status, , $repeatRedirect] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie, ['csrf' => $post['csrf'], 'action' => 'create_delivery']);
    expectSales($status === 303 && $repeatRedirect === $deliveryRedirect && (int) createSalesDelivery($db, $id) === (int) $delivery['id'], 'Se duplicó la entrega al pulsar otra vez.');
    [$status, $body] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie);
    expectSales($status === 200 && str_contains($body, 'Entrega · 1') && ! str_contains($body, 'value="create_delivery"'), 'No aparece el contador y enlace a la entrega existente.');
    expectSales(! str_contains($body, 'data-quotation-invoice'), 'Factura aparece antes de validar la entrega.');
    [$status, $body] = salesRequest($base, 'inventario/operaciones.php?type=delivery&id=' . $delivery['id'], $cookie);
    expectSales($status === 200 && str_contains($body, $quotation['name']) && str_contains($body, $prefix . ' cliente') && ! str_contains($body, 'Calle de prueba') && str_contains($body, $prefix . ' main'), 'La entrega no muestra el documento de origen, cliente o productos.');
    $deliveryDom = new DOMDocument(); libxml_use_internal_errors(true); $deliveryDom->loadHTML('<?xml encoding="UTF-8">' . $body);
    $deliveryXpath = new DOMXPath($deliveryDom);
    expectSales($deliveryXpath->query('//*[@id="operation-other"]//label[contains(., "Vendedor relacionado")]/input')->length === 1
        && $deliveryXpath->query('//label[contains(., "Términos de pago")]')->length === 0
        && str_contains($body, 'Otra información'), 'El vendedor no está en Otra información o la entrega conserva términos de pago.');
    // Validate a fixture delivery through the actual form and check the linked sales order.
    $validatePost = [];
    foreach ($deliveryXpath->query('//form[@data-operation-validate]//input[@type="hidden"]') as $input) {
        $validatePost[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    foreach ($copiedLines as $line) {
        $db->prepare('INSERT INTO inventory_stock (product_id, location_id, quantity) VALUES (?, ?, ?)')->execute([$line['product_id'], $fixtures['location'], $line['demand']]);
    }
    $db->prepare('UPDATE inventory_operation_lines SET quantity = demand WHERE operation_id = ?')->execute([$delivery['id']]);
    $validatePost['action'] = 'confirm_operation';
    [$status] = salesRequest($base, 'inventario/operaciones.php?type=delivery&id=' . $delivery['id'], $cookie, $validatePost);
    expectSales($status === 303, 'No se confirmó la entrega.');
    $validatePost['action'] = 'check_ready'; $validatePost['version'] = '2';
    [$status] = salesRequest($base, 'inventario/operaciones.php?type=delivery&id=' . $delivery['id'], $cookie, $validatePost);
    expectSales($status === 303, 'La entrega no quedó Lista.');
    $validatePost['action'] = 'validate_operation'; $validatePost['version'] = '3';
    [$status] = salesRequest($base, 'inventario/operaciones.php?type=delivery&id=' . $delivery['id'], $cookie, $validatePost);
    expectSales($status === 303 && loadSalesDelivery($db, $id)['status'] === 'done', 'No se validó la entrega relacionada.');
    [$status, $body] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie);
    expectSales($status === 200 && str_contains($body, 'data-quotation-invoice') && str_contains($body, '>Crear factura</button>'), 'Factura no aparece en la orden después de validar su entrega.');
    $post['version'] = (string) loadSalesQuotation($db, $id)['version'];
    [$status, $body] = salesRequest($base, 'ventas/index.php?id=' . $id, $cookie, $post);
    expectSales($status === 200 && str_contains($body, 'ya está confirmada'), 'Se editó una orden confirmada.');
    [$status, $body] = salesRequest($base, 'ventas/index.php?status=sale&q=' . rawurlencode($quotation['name']), $cookie);
    expectSales($status === 200 && str_contains($body, $quotation['name']) && ! str_contains($body, 'data-quotation-form'), 'La vista tree no muestra la orden o mezcla el formulario.');
    [$status, , $redirect] = salesRequest($base, 'ventas/index.php?new=1', $cookie, array_replace($post, ['lines' => []]));
    expectSales($status === 303 && preg_match('/id=(\d+)/', $redirect, $matches) === 1, 'No se puede guardar un borrador sin líneas.');
    $emptyId = (int) $matches[1]; $empty = loadSalesQuotation($db, $emptyId);
    expectSales($empty['name'] !== $quotation['name'], 'Se repitió el correlativo.');
    [$status, $body] = salesRequest($base, 'ventas/index.php?id=' . $emptyId, $cookie,
        ['csrf' => $post['csrf'], 'version' => (string) $empty['version'], 'action' => 'confirm_sale']);
    expectSales($status === 200 && str_contains($body, 'Agrega al menos una línea') && loadSalesQuotation($db, $emptyId)['status'] === 'draft', 'Se confirmó una orden sin líneas.');
    [$status] = salesRequest($base, 'ventas/index.php?new=1', $cookie, ['action' => 'save_quotation']); expectSales($status === 403, 'Se aceptó un formulario sin token.');
    [$status] = salesRequest($base, 'ventas/index.php?id=999999999', $cookie); expectSales($status === 404, 'Un registro inexistente no devuelve 404.');
    echo "PASS: términos de pago, cotizaciones tree/form, correlativo, direcciones, relaciones, cálculos decimales, edición, estados y bloqueo de órdenes confirmadas.\n";
} finally {
    if (isset($fixtures['customer'])) {
        $headers = $db->prepare('SELECT id FROM sales_quotations WHERE customer_id = ?'); $headers->execute([$fixtures['customer']]);
        foreach ($headers->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $linked = $db->prepare('SELECT id FROM inventory_operations WHERE quotation_id = ?'); $linked->execute([$id]);
            foreach ($linked->fetchAll(PDO::FETCH_COLUMN) as $deliveryId) {
                $db->prepare('DELETE FROM inventory_stock_moves WHERE operation_id = ?')->execute([$deliveryId]);
                $db->prepare('DELETE FROM inventory_operation_lines WHERE operation_id = ?')->execute([$deliveryId]);
                $db->prepare('DELETE FROM inventory_operations WHERE id = ? AND quotation_id = ?')->execute([$deliveryId, $id]);
            }
            $db->prepare('DELETE FROM sales_quotation_lines WHERE quotation_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM sales_quotations WHERE id = ? AND customer_id = ?')->execute([$id, $fixtures['customer']]);
        }
    }
    foreach (['product_main', 'product_small', 'product_disabled'] as $productKey) {
        if (isset($fixtures[$productKey])) { $db->prepare('DELETE FROM inventory_stock WHERE product_id = ?')->execute([$fixtures[$productKey]]); }
    }
    foreach (['product_main' => 'inventory_products', 'product_small' => 'inventory_products', 'product_disabled' => 'inventory_products',
        'tax_ventas' => 'business_taxes', 'tax_compras' => 'business_taxes', 'customer' => 'contacts', 'seller' => 'contacts',
        'city' => 'contact_cities', 'state' => 'contact_states', 'country' => 'contact_countries', 'term' => 'accounting_payment_terms',
        'location' => 'inventory_locations', 'warehouse' => 'inventory_warehouses'] as $key => $table) {
        if (isset($fixtures[$key])) { $db->prepare('DELETE FROM ' . $table . ' WHERE id = ? AND name LIKE ?')->execute([$fixtures[$key], $prefix . '%']); }
    }
    if ($cookie !== false && is_file($cookie)) { unlink($cookie); }
}
