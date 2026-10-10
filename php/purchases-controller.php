<?php
declare(strict_types=1);
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/commerce-repository.php';
require_once __DIR__ . '/module-page.php';
require_once __DIR__.'/dashboard-repository.php';

function renderPurchaseOrders(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['purchase_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $page = ['area' => 'purchase_order', 'available' => false, 'errors' => [], 'view' => 'list', 'csrf' => $_SESSION['purchase_csrf'],
        'flash' => $_SESSION['purchase_flash'] ?? null, 'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['purchase_flash']);
    try {
        $db = createDatabaseConnection(); $page['databaseStatus']['connected'] = true; $page['available'] = true;
        $id = salesId($_GET['id'] ?? null); $page['view'] = $id || isset($_GET['new']) ? 'form' : 'list';
        $existing = $id ? loadPurchaseOrder($db, $id) : null;
        if (isset($_GET['id']) && (! $id || ! $existing)) { http_response_code(404); $page['available'] = false; $page['errors'][] = 'La orden de compra no existe.'; }
        $page['record'] = $existing ?? ['name' => 'Nuevo', 'status' => 'draft', 'order_type' => 'receipt', 'version' => 0, 'order_date' => commerceToday(), 'lines' => [], 'base_amount' => '0.00', 'total_amount' => '0.00'];
        if ($page['view'] === 'form' && $page['available']) {
            $page['catalogs'] = loadPurchaseCatalogs($db, $existing);
            if(!$existing)$page['record']['currency_code']=$page['catalogs']['settings']['primary_code'];
            $page['documentKind'] = 'purchase'; $page['links'] = $id ? commerceLinks($db, 'purchase', $id) : null;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            if (! is_string($_POST['csrf'] ?? null) || ! hash_equals($page['csrf'], $_POST['csrf'])) { http_response_code(403); $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.'; }
            elseif ($page['view'] !== 'form') { http_response_code(400); $page['errors'][] = 'La operación no es válida.'; }
            else {
                $action = $_POST['action'] ?? '';
                try {
                    if ($action === 'save_purchase') {
                        [$data, $lines, $page['errors']] = validatePurchaseOrder($_POST, $page['catalogs'], $existing);
                        $page['record'] = $data + ['lines' => $lines] + $page['record'];
                        if (! $page['errors']) { $id = savePurchaseOrder($db, $data, $lines, $id, salesId($_POST['version'] ?? null)); $_SESSION['purchase_flash'] = 'Solicitud guardada correctamente.'; header('Location: index.php?id=' . $id, true, 303); exit; }
                    } elseif ($id && in_array($action, ['mark_sent', 'confirm_purchase'], true)) {
                        transitionPurchaseOrder($db, $id, salesId($_POST['version'] ?? null), $action === 'mark_sent' ? 'sent' : 'purchase');
                        $_SESSION['purchase_flash'] = $action === 'mark_sent' ? 'Solicitud marcada como enviada.' : 'Orden de compra confirmada.';
                        header('Location: index.php?id=' . $id, true, 303); exit;
                    } elseif ($id && $action === 'create_receipt') {
                        $operationId = createPurchaseReceipt($db, $id); header('Location: ../inventario/operaciones.php?type=receipt&id=' . $operationId, true, 303); exit;
                    } elseif ($id && $action === 'create_invoice') {
                        $invoiceId = createCommerceInvoice($db, 'purchase', $id); header('Location: ../contabilidad/index.php?type=supplier&id=' . $invoiceId, true, 303); exit;
                    } else { http_response_code(400); $page['errors'][] = 'La operación solicitada no es válida.'; }
                } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
            }
        }
        if ($page['view'] === 'list' && $page['available']) {
            $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160) : '';
            $page['statusFilter'] = is_string($_GET['status'] ?? null) && isset(purchaseStatuses()[$_GET['status']]) ? $_GET['status'] : 'all';
            $where = []; $params = [];
            if ($page['search'] !== '') { $where[] = '(o.name LIKE :reference OR c.name LIKE :supplier)'; $params['reference'] = $params['supplier'] = '%' . $page['search'] . '%'; }
            if ($page['statusFilter'] !== 'all') { $where[] = 'o.status = :status'; $params['status'] = $page['statusFilter']; }
            if(($_GET['pending']??'')==='1')$where[]=dashboardPendingOrderCondition('purchase','o');
            $from = ' FROM purchase_orders o JOIN contacts c ON c.id = o.supplier_id'; $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $count = $db->prepare('SELECT COUNT(*)' . $from . $filter); $count->execute($params); $page['total'] = (int) $count->fetchColumn();
            $page['pages'] = max(1, (int) ceil($page['total'] / 20)); $page['currentPage'] = min(salesId($_GET['page'] ?? null) ?? 1, $page['pages']);
            $query = $db->prepare('SELECT o.*, c.name AS supplier_name' . $from . $filter . ' ORDER BY o.id DESC LIMIT 20 OFFSET ' . (($page['currentPage'] - 1) * 20)); $query->execute($params); $page['rows'] = $query->fetchAll();
        }
    } catch (PDOException $exception) { error_log('Compras: ' . $exception->getMessage()); http_response_code(503); $page['available'] = false; $page['errors'][] = 'No se pudieron cargar las órdenes. Verifica la instalación de Compras.'; }
    renderModulePage('compras', false, $page);
}
