<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/connection.php';
require_once dirname(__DIR__) . '/php/inventory-stock-repository.php';
$db = createDatabaseConnection(); $prefix = 'PI-' . bin2hex(random_bytes(8));
$product = null; $supplier = null; $orders = [];
function expectPurchaseIndicator(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
function purchaseIndicatorPage(string $path): string {
    $curl = curl_init('http://localhost/project/modules/' . $path); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    expectPurchaseIndicator($status === 200 && is_string($body) && ! preg_match('/Fatal error|Warning:|Notice:/', $body), 'La consulta falló.'); return $body;
}
try {
    $db->prepare('INSERT INTO inventory_products (name) VALUES (?)')->execute([$prefix]); $product = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO contacts (name, type_id) SELECT ?, id FROM contact_types WHERE system_key = 'supplier'")->execute([$prefix]); $supplier = (int) $db->lastInsertId();
    $today = (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->format('Y-m-d');
    foreach (['purchase' => [$today, ['3.125', '1.875']], 'old' => ['2020-01-01', ['7.000']], 'draft' => [$today, ['99.000']]] as $key => [$date, $quantities]) {
        $db->prepare('INSERT INTO purchase_orders (name, supplier_id, order_date, approved_date, status) VALUES (?, ?, ?, ?, ?)')->execute([$prefix . $key, $supplier, $date, $key === 'draft' ? null : $date, $key === 'draft' ? 'draft' : 'purchase']);
        $orders[$key] = (int) $db->lastInsertId();
        foreach ($quantities as $quantity) { $db->prepare('INSERT INTO purchase_order_lines (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)')->execute([$orders[$key], $product, $quantity, '2.00']); }
    }
    expectPurchaseIndicator(loadInventoryProductIndicators($db, $product)['purchased'] === '5.000', 'Comprado incluye borradores, órdenes antiguas o tiene cantidades incorrectas.');
    $list = purchaseIndicatorPage('inventario/informes.php?view=purchased&product_id=' . $product);
    expectPurchaseIndicator(str_contains($list, '../compras/orden.php?id=' . $orders['purchase']) && str_contains($list, $prefix . 'old') && ! str_contains($list, $prefix . 'draft'), 'El historial no contiene las compras confirmadas o incluye borradores.');
    foreach (['pivot', 'graph'] as $display) {
        $report = purchaseIndicatorPage('inventario/informes.php?view=purchased&product_id=' . $product . '&display=' . $display . '&group=contact');
        expectPurchaseIndicator(str_contains($report, '12.000'), 'La agrupación no incluye todas las líneas confirmadas.');
    }
    $order = purchaseIndicatorPage('compras/orden.php?id=' . $orders['purchase']);
    expectPurchaseIndicator(str_contains($order, '3.125') && str_contains($order, '1.875'), 'No se puede abrir el documento de compra.');
    $form = purchaseIndicatorPage('inventario/index.php?id=' . $product);
    expectPurchaseIndicator(str_contains($form, 'view=purchased&amp;product_id=' . $product) && str_contains($form, '5.000'), 'El indicador no abre su historial.');
    echo "PASS: Comprado, período, historial de órdenes confirmadas, agrupaciones, gráfico, tabla dinámica y documento de origen.\n";
} finally {
    foreach ($orders as $id) { $db->prepare('DELETE FROM purchase_order_lines WHERE order_id = ?')->execute([$id]); $db->prepare('DELETE FROM purchase_orders WHERE id = ? AND name LIKE ?')->execute([$id, $prefix . '%']); }
    if ($supplier) { $db->prepare('DELETE FROM contacts WHERE id = ? AND name = ?')->execute([$supplier, $prefix]); }
    if ($product) { $db->prepare('DELETE FROM inventory_products WHERE id = ? AND name = ?')->execute([$product, $prefix]); }
}
