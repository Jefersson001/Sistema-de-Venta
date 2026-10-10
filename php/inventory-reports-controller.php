<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory-controller.php';

function renderInventoryReports(): void
{
    $view = $_GET['view'] ?? 'movements';
    $titles = ['movements' => 'Historial de movimientos', 'stock' => 'Existencias por ubicación', 'incoming' => 'Historial de entradas', 'outgoing' => 'Historial de salidas', 'sold' => 'Análisis de ventas', 'purchased' => 'Historial de compras'];
    $valid = is_string($view) && isset($titles[$view]);
    if (! $valid) { $view = 'movements'; http_response_code(404); }
    $config = require __DIR__ . '/config/database.php';
    $page = ['area' => 'reports', 'view' => $view, 'available' => false, 'errors' => [],
        'title' => $titles[$view],
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    try {
        $db = createDatabaseConnection(); $page['databaseStatus']['connected'] = true;
        $page['available'] = $valid;
        if (! $valid) { $page['errors'][] = 'El informe solicitado no existe.'; }
        $page['storage'] = loadStorageRelations($db);
        $page['products'] = $db->query('SELECT id, name FROM inventory_products ORDER BY name, id')->fetchAll();
        $page['productId'] = inventoryId($_GET['product_id'] ?? null);
        $page['locationId'] = inventoryId($_GET['location_id'] ?? null);
        $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160) : '';
        $page['display'] = is_string($_GET['display'] ?? null) && in_array($_GET['display'], ['list', 'pivot', 'graph'], true) ? $_GET['display'] : 'list';
        $page['group'] = is_string($_GET['group'] ?? null) && in_array($_GET['group'], ['month', 'contact', 'none'], true) ? $_GET['group'] : ($view === 'sold' ? 'month' : 'none');
        $page['summary'] = [];
        $params = []; $where = [];
        if (in_array($view, ['movements', 'incoming', 'outgoing'], true)) {
            // One row per moved product line, including both sides of internal transfers.
            $from = ' FROM inventory_operation_lines l JOIN inventory_operations o ON o.id = l.operation_id
                JOIN inventory_products p ON p.id = l.product_id LEFT JOIN contacts c ON c.id = o.contact_id';
            $from .= ' JOIN (SELECT line_id, MIN(created_at) AS moved_at FROM inventory_stock_moves GROUP BY line_id) m ON m.line_id = l.id';
            if ($view !== 'movements') {
                $where[] = "o.status = 'done' AND o.operation_type = :operation_type AND m.moved_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 YEAR)";
                $params['operation_type'] = $view === 'incoming' ? 'receipt' : 'delivery';
            }
            if ($page['productId']) { $where[] = 'l.product_id = :product'; $params['product'] = $page['productId']; }
            if ($page['locationId']) {
                $where[] = '(o.source_location_id = :source OR o.destination_location_id = :destination)';
                $params['source'] = $params['destination'] = $page['locationId'];
            }
            if ($page['search'] !== '') { $where[] = '(p.name LIKE :product_name OR o.name LIKE :reference OR c.name LIKE :contact_name)'; $params['product_name'] = $params['reference'] = $params['contact_name'] = '%' . $page['search'] . '%'; }
            $select = 'SELECT l.product_id, l.quantity, p.name AS product_name, o.id AS operation_id, o.name AS reference,
                o.operation_type, o.source_location_id, o.source_warehouse_id, o.destination_location_id, o.destination_warehouse_id,
                c.name AS contact_name, m.moved_at';
            $order = ' ORDER BY m.moved_at DESC, l.id DESC';
        } elseif (in_array($view, ['sold', 'purchased'], true)) {
            $isPurchase = $view === 'purchased';
            $from = $isPurchase
                ? ' FROM purchase_order_lines l JOIN purchase_orders o ON o.id = l.order_id JOIN inventory_products p ON p.id = l.product_id JOIN contacts c ON c.id = o.supplier_id'
                : ' FROM sales_quotation_lines l JOIN sales_quotations o ON o.id = l.quotation_id JOIN inventory_products p ON p.id = l.product_id JOIN contacts c ON c.id = o.customer_id';
            $where[] = $isPurchase ? "o.status = 'purchase'" : "o.status = 'sale'";
            if (! $isPurchase) { $where[] = 'o.order_date >= :since'; $params['since'] = inventorySalesHistorySince(); }
            if ($page['productId']) { $where[] = 'l.product_id = :product'; $params['product'] = $page['productId']; }
            if ($page['search'] !== '') { $where[] = '(p.name LIKE :product_name OR o.name LIKE :reference OR c.name LIKE :contact_name)'; $params['product_name'] = $params['reference'] = $params['contact_name'] = '%' . $page['search'] . '%'; }
            $select = 'SELECT l.product_id, l.quantity, p.name AS product_name, o.id AS operation_id, o.name AS reference, o.order_date AS moved_at, c.name AS contact_name,
                c.id AS contact_id, ' . ($isPurchase ? 'l.quantity * l.unit_price' : 'l.base_amount') . ' AS subtotal';
            $order = $page['group'] === 'contact' ? ' ORDER BY c.name, c.id, o.order_date DESC, l.id DESC' : ' ORDER BY o.order_date DESC, l.id DESC';
        } else {
            $from = ' FROM inventory_stock s JOIN inventory_products p ON p.id = s.product_id JOIN inventory_locations loc ON loc.id = s.location_id';
            $where[] = 's.quantity <> 0';
            $where[] = "loc.location_type = 'internal'";
            if ($page['productId']) { $where[] = 's.product_id = :product'; $params['product'] = $page['productId']; }
            if ($page['locationId']) { $where[] = 's.location_id = :location'; $params['location'] = $page['locationId']; }
            if ($page['search'] !== '') { $where[] = 'p.name LIKE :search'; $params['search'] = '%' . $page['search'] . '%'; }
            $select = 'SELECT s.*, p.name AS product_name'; $order = ' ORDER BY p.name, s.product_id, s.location_id';
        }
        $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $db->prepare('SELECT COUNT(*)' . $from . $filter); $count->execute($params); $page['total'] = (int) $count->fetchColumn();
        $page['pages'] = max(1, (int) ceil($page['total'] / 30)); $page['currentPage'] = min(inventoryId($_GET['page'] ?? null) ?? 1, $page['pages']);
        $query = $db->prepare($select . $from . $filter . $order . ' LIMIT 30 OFFSET ' . (($page['currentPage'] - 1) * 30));
        $query->execute($params); $page['rows'] = $query->fetchAll();
        if (in_array($view, ['sold', 'purchased'], true)) {
            $groupField = $page['group'] === 'contact' ? 'c.id, c.name' : "DATE_FORMAT(o.order_date, '%Y-%m')";
            $groupLabel = $page['group'] === 'contact' ? 'c.name' : $groupField;
            $groupKey = $page['group'] === 'contact' ? 'CAST(c.id AS CHAR)' : $groupField;
            $summary = $db->prepare('SELECT ' . $groupKey . ' AS group_key, ' . $groupLabel . ' AS label, SUM(l.quantity) AS quantity, SUM(' . ($view === 'purchased' ? 'l.quantity * l.unit_price' : 'l.base_amount') . ') AS subtotal'
                . $from . $filter . ' GROUP BY ' . $groupField . ' ORDER BY label');
            $summary->execute($params); $page['summary'] = $summary->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Informes de inventario: ' . $exception->getMessage()); http_response_code(503); $page['available'] = false;
        $page['errors'][] = 'No se pudieron cargar los informes. Verifica la instalación de Inventario.';
    }
    renderModulePage('inventario', false, $page);
}
