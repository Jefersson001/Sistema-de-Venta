<?php
declare(strict_types=1);
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/sales-repository.php';
require_once __DIR__ . '/sales-delivery-repository.php';
require_once __DIR__ . '/commerce-repository.php';
require_once __DIR__ . '/module-page.php';
require_once __DIR__.'/dashboard-repository.php';

function renderSalesQuotations(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $_SESSION['sales_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $page = ['available' => false, 'errors' => [], 'view' => 'list', 'record' => [],
        'csrf' => $_SESSION['sales_csrf'], 'flash' => $_SESSION['sales_flash'] ?? null,
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['sales_flash']);
    try {
        $db = createDatabaseConnection(); $page['databaseStatus']['connected'] = true; $page['available'] = true;
        $id = salesId($_GET['id'] ?? null);
        $page['view'] = $id || isset($_GET['new']) ? 'form' : 'list';
        if (isset($_GET['id']) && ! $id) { http_response_code(404); $page['available'] = false; $page['errors'][] = 'La cotización solicitada no existe.'; }
        $existing = $id ? loadSalesQuotation($db, $id) : null;
        if ($id && ! $existing) { http_response_code(404); $page['available'] = false; $page['errors'][] = 'La cotización solicitada no existe.'; }
        $page['record'] = $existing ?? ['name' => 'Nuevo', 'status' => 'draft', 'order_type' => 'delivery', 'lines' => [], 'base_amount' => '0.00', 'total_amount' => '0.00',
            'order_date' => (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->format('Y-m-d')];
        if ($page['view'] === 'form' && $page['available']) {
            $page['catalogs'] = loadSalesCatalogs($db, $existing);
            if(!$existing&&permissionScope('ventas.orders','create')==='own')$page['record']['salesperson_id']=permissionContact();
            if(!$existing)$page['record']['currency_code']=$page['catalogs']['settings']['primary_code'];
            $page['delivery'] = $id ? loadSalesDelivery($db, $id) : null;
            $page['documentKind'] = 'sale'; $page['links'] = $id ? commerceLinks($db, 'sale', $id) : null;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            $action = $_POST['action'] ?? '';
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403); $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view'] !== 'form') {
                http_response_code(400); $page['errors'][] = 'La operación solicitada no es válida.';
            } elseif ($action === 'create_invoice' && $id) {
                try {
                    $invoiceId = createCommerceInvoice($db, 'sale', $id);
                    permissionRedirectDocument(authBasePath().'/modules/contabilidad/index.php?type=customer&id='.$invoiceId,'index.php?id='.$id,'sales_flash');
                } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
            } elseif ($action === 'create_delivery' && $id) {
                try {
                    $deliveryId = createSalesDelivery($db, $id);
                    permissionRedirectDocument(authBasePath().'/modules/inventario/operaciones.php?type=delivery&id='.$deliveryId,'index.php?id='.$id,'sales_flash');
                } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
            } elseif ($action === 'save_quotation') {
                [$data, $lines, $page['errors']] = validateSalesQuotation($_POST, $page['catalogs'], $existing);
                $page['record'] = $data + ['lines' => $lines] + $page['record'];
                if (! $page['errors']) {
                    try {
                        $savedId = saveSalesQuotation($db, $data, $lines, $id, salesId($_POST['version'] ?? null));
                        $_SESSION['sales_flash'] = 'Cotización guardada correctamente.';
                        header('Location: index.php?id=' . $savedId, true, 303); exit;
                    } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
                }
            } elseif ($id && in_array($action, ['mark_sent', 'confirm_sale', 'cancel_quotation'], true)) {
                try {
                    transitionSalesQuotation($db, $id, salesId($_POST['version'] ?? null), match ($action) { 'mark_sent' => 'sent', 'cancel_quotation' => 'cancel', default => 'sale' });
                    $_SESSION['sales_flash'] = match ($action) { 'mark_sent' => 'Cotización marcada como enviada.', 'cancel_quotation' => 'Cotización cancelada.', default => 'Orden de venta confirmada.' };
                    header('Location: index.php?id=' . $id, true, 303); exit;
                } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
            } else { http_response_code(400); $page['errors'][] = 'La operación solicitada no es válida.'; }
        }
        if ($page['view'] === 'list' && $page['available']) {
            $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160, 'UTF-8') : '';
            $status = $_GET['status'] ?? 'all';
            $page['statusFilter'] = is_string($status) && in_array($status, ['quotations', 'all', 'draft', 'sent', 'sale', 'cancel'], true) ? $status : 'all';
            $where = [permissionWhere('ventas.orders','q')]; $params = [];
            if ($page['statusFilter'] === 'quotations') { $where[] = "q.status IN ('draft', 'sent')"; }
            elseif ($page['statusFilter'] !== 'all') { $where[] = 'q.status = :status'; $params['status'] = $page['statusFilter']; }
            if ($page['search'] !== '') { $where[] = '(q.name LIKE :reference OR c.name LIKE :customer)'; $params['reference'] = '%' . $page['search'] . '%'; $params['customer'] = $params['reference']; }
            if(($_GET['pending']??'')==='1')$where[]=dashboardPendingOrderCondition('sale','q');
            $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $count = $db->prepare('SELECT COUNT(*) FROM sales_quotations q JOIN contacts c ON c.id = q.customer_id' . $filter);
            $count->execute($params); $page['total'] = (int) $count->fetchColumn();
            $page['pages'] = max(1, (int) ceil($page['total'] / 20));
            $page['currentPage'] = min(salesId($_GET['page'] ?? null) ?? 1, $page['pages']);
            $list = $db->prepare('SELECT q.*, c.name AS customer_name FROM sales_quotations q JOIN contacts c ON c.id = q.customer_id'
                . $filter . ' ORDER BY q.id DESC LIMIT 20 OFFSET ' . (($page['currentPage'] - 1) * 20));
            $list->execute($params); $page['quotations'] = $list->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Cotizaciones: ' . $exception->getMessage()); http_response_code(503); $page['available'] = false;
        $page['errors'][] = 'No se pudieron cargar las cotizaciones. Verifica la conexión y la instalación de Ventas.';
    }
    renderModulePage('ventas', false, $page);
}
