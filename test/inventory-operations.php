<?php
declare(strict_types=1);
require_once __DIR__.'/http-session.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/connection.php';
require_once dirname(__DIR__) . '/php/inventory-operations-repository.php';
$db = createDatabaseConnection();
$prefix = 'Test operaciones ' . bin2hex(random_bytes(8));
$cookie = tempnam(sys_get_temp_dir(), 'operations-');
$warehouses = []; $contacts = []; $location = null; $destinationLocation = null; $product = null;
function checkOperation(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
function operationRequest(string $query, string $cookie, ?array $post = null): array {
    $curl = testHttpCurl('http://localhost/project/modules/inventario/operaciones.php?' . $query, $cookie ?? $cookieJar ?? $cookieFile ?? null);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    if ($body === false) { throw new RuntimeException(curl_error($curl)); }
    curl_close($curl);
    checkOperation(! preg_match('/Fatal error|Warning:|Notice:/', $body), 'Error PHP en la respuesta.');
    return [$status, $body, $redirect];
}
try {
    foreach (['A', 'B'] as $suffix) { $db->prepare('INSERT INTO inventory_warehouses (name) VALUES (?)')->execute([$prefix . $suffix]); $warehouses[] = (int) $db->lastInsertId(); }
    $db->prepare('INSERT INTO inventory_locations (name, warehouse_id) VALUES (?, ?)')->execute([$prefix, $warehouses[0]]); $location = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO inventory_locations (name, warehouse_id) VALUES (?, ?)')->execute([$prefix . ' destino', $warehouses[1]]); $destinationLocation = (int) $db->lastInsertId();
    foreach (['client', 'supplier'] as $role) {
        $db->prepare('INSERT INTO contacts (name, type_id) SELECT ?, id FROM contact_types WHERE system_key = ?')->execute([$prefix . $role, $role]);
        $contacts[$role] = (int) $db->lastInsertId();
    }
    $db->prepare('UPDATE contacts SET address = ?, postal_code = ? WHERE id = ?')->execute(['Calle de prueba', '1234', $contacts['client']]);
    $externalLocations = [];
    foreach (['client' => 'customer', 'supplier' => 'supplier'] as $role => $locationType) {
        $db->prepare('INSERT INTO inventory_locations (name, location_type) VALUES (?, ?)')->execute([$prefix . $locationType, $locationType]);
        $externalLocations[$role] = (int) $db->lastInsertId();
        $db->prepare('UPDATE contacts SET partner_location_id = ? WHERE id = ?')->execute([$externalLocations[$role], $contacts[$role]]);
    }
    $db->prepare('INSERT INTO inventory_products (name) VALUES (?)')->execute([$prefix]); $product = (int) $db->lastInsertId();
    foreach (inventoryOperationTypes() as $type => $definition) {
        [$status, $form] = operationRequest('type=' . $type . '&new=1', $cookie);
        checkOperation($status === 200 && str_contains($form, 'data-operation-form') && ! str_contains($form, 'aria-label="Listado de operaciones"') && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches), 'No se abrió el formulario independiente.');
        checkOperation(! str_contains($form, '<legend>Origen</legend>') && ! str_contains($form, '<legend>Destino</legend>') && ! str_contains($form, 'Proveedor seleccionado')
            && ! str_contains($form, 'name="source_warehouse_id"') && ! str_contains($form, 'name="destination_warehouse_id"'), 'El formulario conserva bloques o selectores separados de almacén.');
        if ($type === 'delivery') {
            checkOperation(! str_contains($form, 'data-operation-available') && ! str_contains($form, 'A la mano en'), 'La entrega conserva la columna de existencias.');
            checkOperation(str_contains($form, 'Dirección de entrega') && str_contains($form, $prefix . 'client') && ! str_contains($form, 'Calle de prueba, 1234')
                && str_contains($form, 'Ubicación de origen') && ! str_contains($form, 'name="source_warehouse_id"')
                && str_contains($form, $prefix . ' / ' . $prefix . 'A'), 'La entrega no muestra dirección y ubicación/almacén en un solo selector.');
        }
        $post = ['csrf' => $matches[1], 'action' => 'save_operation', 'scheduled_date' => '2026-10-10', 'notes' => $prefix,
            'contact_id' => $contacts[$definition['role']] ?? '', 'source_warehouse_id' => $warehouses[0], 'source_location_id' => $location,
            'destination_warehouse_id' => $warehouses[1], 'destination_location_id' => $destinationLocation];
        if ($type === 'delivery') { $post['source_warehouse_id'] = $warehouses[1]; }
        [$status, , $redirect] = operationRequest('type=' . $type . '&new=1', $cookie, $post);
        checkOperation($status === 303 && preg_match('/id=(\d+)/', $redirect, $matches), 'No se guardó la operación.');
        $id = (int) $matches[1];
        $query = $db->prepare('SELECT * FROM inventory_operations WHERE id = ?'); $query->execute([$id]); $record = $query->fetch();
        checkOperation($record['operation_type'] === $type && str_starts_with($record['name'], $definition['prefix'] . '/') && $record['notes'] === $prefix, 'No persistieron el tipo, referencia o notas.');
        checkOperation($type !== 'receipt' || $record['source_warehouse_id'] === null, 'La recepción guardó un origen interno.');
        checkOperation($type !== 'delivery' || $record['destination_warehouse_id'] === null, 'La entrega guardó un destino interno.');
        if ($type !== 'internal') {
            checkOperation((int) $record[$type === 'receipt' ? 'source_location_id' : 'destination_location_id'] === $externalLocations[$type === 'receipt' ? 'supplier' : 'client'], 'No se tomó la ubicación externa relacionada al contacto.');
        }
        checkOperation($type !== 'delivery' || ((int) $record['source_location_id'] === $location && (int) $record['source_warehouse_id'] === $warehouses[0]), 'La entrega debe derivar el almacén de la ubicación e ignorar el almacén enviado.');
        checkOperation($type === 'delivery' || ((int) $record['destination_location_id'] === $destinationLocation && (int) $record['destination_warehouse_id'] === $warehouses[1]), 'No se guardó la ubicación de destino y su almacén.');
        [$status, $list] = operationRequest('type=' . $type . '&q=' . rawurlencode($record['name']), $cookie);
        checkOperation($status === 200 && str_contains($list, '<table') && str_contains($list, $record['name']) && ! str_contains($list, 'value="save_operation"'), 'El listado mezcla el formulario o no encuentra el registro.');
        $post['version'] = $record['version']; $post['scheduled_date'] = '2026-10-11';
        [$status] = operationRequest('type=' . $type . '&id=' . $id, $cookie, $post); checkOperation($status === 303, 'No se editó el borrador.');
        [$status, $body] = operationRequest('type=' . $type . '&id=' . $id, $cookie, $post); checkOperation($status === 200 && str_contains($body, 'otra ventana'), 'Se sobrescribió una versión reciente.');
        $invalid = $post; $invalid[$type === 'delivery' ? 'source_location_id' : 'destination_location_id'] = 999999999;
        [$status, $body] = operationRequest('type=' . $type . '&new=1', $cookie, $invalid); checkOperation($status === 200 && str_contains($body, 'notice-error'), 'Se aceptó una ubicación inexistente.');
        if ($type === 'delivery') {
            $invalid['source_location_id'] = '';
            [$status, $body] = operationRequest('type=delivery&new=1', $cookie, $invalid);
            checkOperation($status === 200 && str_contains($body, 'ubicación de origen válida'), 'La entrega aceptó un origen sin ubicación.');
        }
        if ($type === 'internal') {
            $invalid = $post; $invalid['destination_warehouse_id'] = $warehouses[0]; $invalid['destination_location_id'] = $location;
            [$status, $body] = operationRequest('type=internal&new=1', $cookie, $invalid); checkOperation($status === 200 && str_contains($body, 'deben ser diferentes'), 'Se permitió un traslado al mismo destino.');
        }
    }
    $createStockOperation = function (string $type, string $quantity, string $demand = '12.000', ?array $lines = null) use ($db, $cookie, $prefix, $contacts, $location, $destinationLocation, $product): array {
        [$status, $form] = operationRequest('type=' . $type . '&new=1', $cookie);
        checkOperation(preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'No se encontró CSRF.');
        $data = ['csrf' => $matches[1], 'action' => 'save_operation', 'scheduled_date' => '2026-10-10', 'notes' => $prefix,
            'contact_id' => $contacts[$type === 'receipt' ? 'supplier' : 'client'],
            'source_location_id' => $type === 'delivery' ? $destinationLocation : $location,
            'destination_location_id' => $type === 'receipt' ? $location : $destinationLocation,
            'lines' => $lines ?? [['product_id' => $product, 'demand' => $demand, 'quantity' => $quantity]]];
        [$status, , $redirect] = operationRequest('type=' . $type . '&new=1', $cookie, $data);
        checkOperation($status === 303 && preg_match('/id=(\d+)/', $redirect, $matches), 'No se guardaron las líneas.');
        return [(int) $matches[1], $data];
    };
    $validateStock = function (string $type, int $id, string $csrf) use ($db, $cookie): array {
        $query = $db->prepare('SELECT version, status FROM inventory_operations WHERE id = ?'); $query->execute([$id]); $current = $query->fetch();
        if ($current['status'] === 'draft') {
            $response = operationRequest('type=' . $type . '&id=' . $id, $cookie, ['action' => 'confirm_operation', 'csrf' => $csrf, 'version' => $current['version']]);
            if ($response[0] !== 303) return $response;
            $query->execute([$id]); $current = $query->fetch();
        }
        if ($current['status'] === 'waiting') {
            $response = operationRequest('type=' . $type . '&id=' . $id, $cookie, ['action' => 'check_ready', 'csrf' => $csrf, 'version' => $current['version']]);
            if ($response[0] !== 303) return $response;
            $query->execute([$id]); $current = $query->fetch();
        }
        $post = ['action' => 'validate_operation', 'csrf' => $csrf, 'version' => $current['version']];
        [$status, $form] = operationRequest('type=' . $type . '&id=' . $id, $cookie);
        if (str_contains($form, 'data-operation-validate')) {
            $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">' . $form);
            $xpath = new DOMXPath($dom); $post = [];
            // A button disabled during submit is omitted by the browser. Serialize only hidden inputs.
            foreach ($xpath->query('//form[@data-operation-validate]//input[@type="hidden" and not(@disabled)]') as $input) {
                $post[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            checkOperation(($post['action'] ?? null) === 'validate_operation', 'Validar pierde la acción al deshabilitar el botón.');
        }
        return operationRequest('type=' . $type . '&id=' . $id, $cookie, $post);
    };
    [$receiptId, $receiptPost] = $createStockOperation('receipt', '10.125');
    checkOperation(loadInventoryProductIndicators($db, $product)['incoming'] === '0', 'Entrada cuenta un borrador como movimiento realizado.');
    checkOperation(loadInventoryProductStock($db, $product)['total'] === '0.000', 'Guardar un borrador modificó el stock.');
    $receiptLines = loadInventoryOperationLines($db, $receiptId);
    checkOperation($receiptLines[0]['demand'] === '12.000' && $receiptLines[0]['quantity'] === '10.125', 'Se confundieron demanda y cantidad.');
    [$status, $body] = operationRequest('type=receipt&id=' . $receiptId, $cookie, ['action' => 'validate_operation', 'csrf' => $receiptPost['csrf'], 'version' => '1']);
    checkOperation($status === 200 && str_contains($body, 'Listo') && loadInventoryProductStock($db, $product)['total'] === '0.000', 'Se validó directamente un borrador.');
    [$status] = operationRequest('type=receipt&id=' . $receiptId, $cookie, ['action' => 'confirm_operation', 'csrf' => $receiptPost['csrf'], 'version' => '1']);
    checkOperation($status === 303, 'No pasó de Borrador a En espera.');
    [$status] = operationRequest('type=receipt&id=' . $receiptId, $cookie, ['action' => 'check_ready', 'csrf' => $receiptPost['csrf'], 'version' => '2']);
    checkOperation($status === 303 && loadInventoryProductStock($db, $product)['total'] === '0.000', 'Comprobar disponibilidad movió existencias.');
    $changedReceipt = $receiptPost + ['version' => '3'];
    [$status] = operationRequest('type=receipt&id=' . $receiptId, $cookie, $changedReceipt);
    $stateQuery = $db->prepare('SELECT status FROM inventory_operations WHERE id = ?'); $stateQuery->execute([$receiptId]);
    checkOperation($status === 303 && $stateQuery->fetchColumn() === 'waiting', 'Editar una operación lista no obliga a comprobar de nuevo.');
    [$status] = $validateStock('receipt', $receiptId, $receiptPost['csrf']); checkOperation($status === 303, 'No se validó la recepción.');
    $stateQuery->execute([$receiptId]); checkOperation($stateQuery->fetchColumn() === 'done', 'Validar no dejó Hecho.');
    checkOperation(loadInventoryProductStock($db, $product)['total'] === '10.125', 'La recepción no sumó la cantidad real.');
    checkOperation(loadInventoryProductIndicators($db, $product)['incoming'] === '1', 'Entrada no cuenta la recepción realizada.');
    [$status, $body] = $validateStock('receipt', $receiptId, $receiptPost['csrf']);
    checkOperation($status === 200 && str_contains($body, 'no admite cambios') && loadInventoryProductStock($db, $product)['total'] === '10.125', 'Una segunda validación duplicó existencias.');
    [$status, $body] = operationRequest('type=receipt&id=' . $receiptId, $cookie, $receiptPost);
    checkOperation($status === 200 && str_contains($body, 'no admite cambios'), 'Se editó una operación realizada.');
    [$internalId, $internalPost] = $createStockOperation('internal', '3.125');
    [$status] = $validateStock('internal', $internalId, $internalPost['csrf']); checkOperation($status === 303, 'No se validó el traslado.');
    $balances = loadInventoryProductStock($db, $product);
    checkOperation($balances['total'] === '10.125' && $balances['locations'][0]['quantity'] === '7.000' && $balances['locations'][1]['quantity'] === '3.125', 'El interno no conservó el total o no movió entre ubicaciones.');
    [$deliveryId, $deliveryPost] = $createStockOperation('delivery', '2.000');
    checkOperation(loadInventoryProductIndicators($db, $product)['outgoing'] === '0', 'Salida cuenta un borrador como movimiento realizado.');
    [$status] = $validateStock('delivery', $deliveryId, $deliveryPost['csrf']); checkOperation($status === 303, 'No se validó la entrega.');
    checkOperation(loadInventoryProductStock($db, $product)['total'] === '8.125', 'La entrega no descontó existencias.');
    $externalBalance = $db->prepare('SELECT quantity FROM inventory_stock WHERE product_id = ? AND location_id = ?');
    $externalBalance->execute([$product, $externalLocations['client']]); checkOperation($externalBalance->fetchColumn() === '2.000', 'No se registraron las unidades que salieron al cliente.');
    $externalBalance->execute([$product, $externalLocations['supplier']]); checkOperation($externalBalance->fetchColumn() === '-10.125', 'No se registró el origen proveedor de la recepción.');
    $indicators = loadInventoryProductIndicators($db, $product);
    checkOperation($indicators['outgoing'] === '1' && $indicators['sold'] === '0.000' && $indicators['purchased'] === '0.000', 'No se cuenta la salida realizada o una entrega manual alteró ventas o compras.');
    [$failedId, $failedPost] = $createStockOperation('internal', '5.000', '5.000', [
        ['product_id' => $product, 'demand' => '5.000', 'quantity' => '5.000'],
        ['product_id' => $product, 'demand' => '5.000', 'quantity' => '5.000']]);
    [$status, $body] = $validateStock('internal', $failedId, $failedPost['csrf']);
    checkOperation($status === 200 && str_contains($body, 'suficiente') && loadInventoryProductStock($db, $product)['total'] === '8.125', 'Se permitió stock negativo con líneas duplicadas o hubo movimientos parciales.');
    $stateQuery->execute([$failedId]); checkOperation($stateQuery->fetchColumn() === 'waiting', 'Una operación sin disponibilidad debe seguir En espera.');
    $query = $db->prepare('SELECT COUNT(*) FROM inventory_stock_moves WHERE operation_id = ?'); $query->execute([$failedId]);
    checkOperation((int) $query->fetchColumn() === 0, 'Una validación fallida dejó movimientos.');
    $curl = testHttpCurl('http://localhost/project/modules/inventario/index.php?id=' . $product, $cookie ?? $cookieJar ?? $cookieFile ?? null);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $productForm = curl_exec($curl); curl_close($curl);
    checkOperation(is_string($productForm) && str_contains($productForm, '8.125') && str_contains($productForm, 'view=stock&amp;product_id=' . $product) && ! str_contains($productForm, '<h2 class="notebook-page-title">Existencias por ubicación'), 'Cantidad a la mano no abre directamente su informe o la pestaña mantiene el detalle.');
    foreach (['movements', 'stock', 'outgoing', 'incoming'] as $reportView) {
        $curl = testHttpCurl('http://localhost/project/modules/inventario/informes.php?view=' . $reportView . '&product_id=' . $product, $cookie ?? $cookieJar ?? $cookieFile ?? null);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $report = curl_exec($curl); $reportStatus = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        checkOperation($reportStatus === 200 && is_string($report) && str_contains($report, $prefix) && ! preg_match('/Fatal error|Warning:|Notice:/', $report), 'No se cargó el informe de inventario.');
        $reportDom = new DOMDocument(); libxml_use_internal_errors(true); $reportDom->loadHTML('<?xml encoding="UTF-8">' . $report);
        $reportXpath = new DOMXPath($reportDom);
        checkOperation($reportXpath->query('//table/tbody/tr')->length === ($reportView === 'movements' ? 3 : ($reportView === 'stock' ? 2 : 1)), 'El informe duplica movimientos o mezcla estados.');
        checkOperation(str_contains($report, match ($reportView) { 'movements', 'incoming' => '10.125', 'outgoing' => '2.000', default => '1.125' }), 'El informe no muestra cantidades reales del movimiento.');
    }
    [$secondReceiptId, $secondReceiptPost] = $createStockOperation('receipt', '1.000');
    [$status] = $validateStock('receipt', $secondReceiptId, $secondReceiptPost['csrf']); checkOperation($status === 303, 'No se validó la segunda recepción.');
    checkOperation(loadInventoryProductIndicators($db, $product)['incoming'] === '2', 'Las recepciones individuales se agruparon en un solo movimiento.');
    $curl = testHttpCurl('http://localhost/project/modules/inventario/informes.php?view=incoming&product_id=' . $product, $cookie ?? $cookieJar ?? $cookieFile ?? null);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $twoReceipts = curl_exec($curl); curl_close($curl);
    $historyDom = new DOMDocument(); $historyDom->loadHTML('<?xml encoding="UTF-8">' . $twoReceipts); $historyXpath = new DOMXPath($historyDom);
    checkOperation($historyXpath->query('//table/tbody/tr')->length === 2, 'El historial no muestra una fila por recepción.');
    $db->prepare('UPDATE inventory_stock_moves SET created_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 YEAR) WHERE operation_id = ?')->execute([$secondReceiptId]);
    checkOperation(loadInventoryProductIndicators($db, $product)['incoming'] === '1', 'Entrada incluye movimientos anteriores a 12 meses.');
    [$invalidLines, $errors] = validateInventoryOperationLines([['product_id' => $product, 'demand' => '1', 'quantity' => '-1']], [['id' => $product]]);
    checkOperation(count($errors) > 0, 'Se aceptó una cantidad negativa.');
    [$status] = operationRequest('type=invalid', $cookie); checkOperation($status === 404, 'Tipo inexistente sin 404.');
    [$status] = operationRequest('type=delivery&new=1', $cookie, ['action' => 'save_operation']); checkOperation($status === 403, 'Se aceptó un POST sin CSRF.');
    echo "PASS: operaciones tree/form, líneas, cantidades, stock por ubicación, recepción, traslado, entrega, doble validación, rollback y ficha del producto.\n";
} finally {
    $db->prepare('DELETE m FROM inventory_stock_moves m JOIN inventory_operations o ON o.id = m.operation_id WHERE o.notes = ?')->execute([$prefix]);
    $db->prepare('DELETE l FROM inventory_operation_lines l JOIN inventory_operations o ON o.id = l.operation_id WHERE o.notes = ?')->execute([$prefix]);
    $db->prepare('DELETE FROM inventory_operations WHERE notes = ?')->execute([$prefix]);
    if ($product) {
        $db->prepare('DELETE FROM inventory_stock WHERE product_id = ?')->execute([$product]);
        $db->prepare('DELETE FROM inventory_products WHERE id = ? AND name = ?')->execute([$product, $prefix]);
    }
    foreach ($contacts as $id) { $db->prepare('DELETE FROM contacts WHERE id = ? AND name LIKE ?')->execute([$id, $prefix . '%']); }
    foreach ($externalLocations ?? [] as $externalId) { $db->prepare('DELETE FROM inventory_locations WHERE id = ? AND name LIKE ?')->execute([$externalId, $prefix . '%']); }
    if ($location) { $db->prepare('DELETE FROM inventory_locations WHERE id = ? AND name = ?')->execute([$location, $prefix]); }
    if ($destinationLocation) { $db->prepare('DELETE FROM inventory_locations WHERE id = ? AND name = ?')->execute([$destinationLocation, $prefix . ' destino']); }
    foreach ($warehouses as $id) { $db->prepare('DELETE FROM inventory_warehouses WHERE id = ? AND name LIKE ?')->execute([$id, $prefix . '%']); }
    if ($cookie && is_file($cookie)) { unlink($cookie); }
}
