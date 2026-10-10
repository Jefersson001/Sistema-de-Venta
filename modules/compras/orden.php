<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/php/inventory-controller.php';
$config = require dirname(__DIR__, 2) . '/php/config/database.php';
$page = ['area' => 'purchase_order', 'available' => false, 'errors' => [],
    'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
try {
    $db = createDatabaseConnection(); $page['databaseStatus']['connected'] = true;
    $id = inventoryId($_GET['id'] ?? null);
    $query = $db->prepare('SELECT o.*, c.name AS supplier_name FROM purchase_orders o JOIN contacts c ON c.id = o.supplier_id WHERE o.id = ?');
    $query->execute([$id]); $page['record'] = $query->fetch();
    if (! $page['record']) { http_response_code(404); $page['errors'][] = 'La orden de compra no existe.'; }
    else {
        $page['available'] = true;
        $lines = $db->prepare('SELECT l.*, p.name AS product_name FROM purchase_order_lines l JOIN inventory_products p ON p.id = l.product_id WHERE l.order_id = ? ORDER BY l.id');
        $lines->execute([$id]); $page['lines'] = $lines->fetchAll();
    }
} catch (PDOException $exception) {
    error_log('Orden de compra: ' . $exception->getMessage()); http_response_code(503); $page['errors'][] = 'No se pudo consultar la orden de compra.';
}
renderModulePage('compras', false, $page);
