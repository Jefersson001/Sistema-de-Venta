<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory-controller.php';
require_once __DIR__ . '/inventory-operations-repository.php';

function renderInventoryOperations(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $_SESSION['inventory_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $type = $_GET['type'] ?? 'delivery';
    $validType = is_string($type) && isset(inventoryOperationTypes()[$type]);
    if (! $validType) { $type = 'delivery'; http_response_code(404); }
    $page = ['area' => 'operations', 'type' => $type, 'definition' => inventoryOperationTypes()[$type],
        'available' => false, 'errors' => [], 'view' => 'list', 'record' => [], 'csrf' => $_SESSION['inventory_csrf'],
        'flash' => $_SESSION['inventory_flash'] ?? null,
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['inventory_flash']);
    try {
        $db = createDatabaseConnection(); $page['databaseStatus']['connected'] = true;
        $page['available'] = $validType;
        if (! $validType) { $page['errors'][] = 'El tipo de operación no existe.'; }
        $id = inventoryId($_GET['id'] ?? null);
        $page['view'] = $id || isset($_GET['new']) ? 'form' : 'list';
        $existing = null;
        if ($id) {
            $query = $db->prepare('SELECT * FROM inventory_operations WHERE id = ? AND operation_type = ?');
            $query->execute([$id, $type]); $existing = $query->fetch() ?: null;
            if ($existing) { $existing['lines'] = loadInventoryOperationLines($db, $id); }
        }
        if (isset($_GET['id']) && (! $id || ! $existing)) {
            http_response_code(404); $page['available'] = false; $page['errors'][] = 'La operación solicitada no existe.';
        }
        $page['record'] = $existing ?? ['name' => 'Nuevo', 'version' => 0, 'status' => 'draft', 'lines' => [], 'scheduled_date' => (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->format('Y-m-d')];
        $page['storage'] = loadStorageRelations($db);
        if ($page['view'] === 'form' && $page['available']) {
            $page['sourceOrder'] = null;
            if (! empty($existing['quotation_id'])) {
                $source = $db->prepare('SELECT q.*, seller.name AS seller_name FROM sales_quotations q
                    LEFT JOIN contacts seller ON seller.id = q.salesperson_id WHERE q.id = ?');
                $source->execute([$existing['quotation_id']]); $page['sourceOrder'] = $source->fetch() ?: null;
            }
            $query = $db->prepare('SELECT c.id, c.name, c.active FROM contacts c JOIN contact_types t ON t.id = c.type_id
                WHERE t.system_key = ? AND (c.active = 1 OR c.id = ?) ORDER BY c.name, c.id');
            $query->execute([$page['definition']['role'], $existing['contact_id'] ?? null]); $page['contacts'] = $query->fetchAll();
            $page['products'] = $db->query('SELECT id, name FROM inventory_products ORDER BY name, id')->fetchAll();
            $page['stock'] = $db->query('SELECT product_id, location_id, quantity FROM inventory_stock')->fetchAll();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403); $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view'] !== 'form' || ! in_array($_POST['action'] ?? '', ['save_operation', 'validate_operation'], true)) {
                http_response_code(400); $page['errors'][] = 'La operación solicitada no es válida.';
            } elseif (($existing['status'] ?? '') === 'done') {
                $page['errors'][] = 'La operación realizada no admite cambios.';
            } elseif (($_POST['action'] ?? '') === 'validate_operation') {
                if (! $id) { $page['errors'][] = 'Guarda la operación antes de validar.'; }
                else {
                    try {
                        validateInventoryStockOperation($db, $id, inventoryId($_POST['version'] ?? null));
                        $_SESSION['inventory_flash'] = 'Operación realizada. Existencias actualizadas.';
                        header('Location: operaciones.php?type=' . $type . '&id=' . $id, true, 303); exit;
                    } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
                }
            } else {
                [$data, $page['errors']] = validateInventoryOperation($_POST, $type, $page['storage'], $page['contacts']);
                if ($page['sourceOrder'] && $data['contact_id'] !== (int) $page['sourceOrder']['customer_id']) {
                    $page['errors'][] = 'La dirección de entrega debe corresponder al cliente de la orden de venta.';
                }
                [$lines, $lineErrors] = validateInventoryOperationLines($_POST['lines'] ?? [], $page['products']);
                $page['errors'] = array_merge($page['errors'], $lineErrors);
                $page['record'] = $data + ['lines' => $lines] + $page['record'];
                if (! $page['errors']) {
                    try {
                        $saved = saveInventoryOperation($db, $data, $id, inventoryId($_POST['version'] ?? null), $lines);
                        $_SESSION['inventory_flash'] = 'Operación guardada como borrador.';
                        header('Location: operaciones.php?type=' . $type . '&id=' . $saved, true, 303); exit;
                    } catch (InvalidArgumentException $exception) { $page['errors'][] = $exception->getMessage(); }
                }
            }
        }
        if ($page['view'] === 'list' && $page['available']) {
            $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160) : '';
            $params = ['type' => $type]; $where = ' WHERE o.operation_type = :type';
            if ($page['search'] !== '') { $where .= ' AND (o.name LIKE :reference OR c.name LIKE :contact)'; $params['reference'] = $params['contact'] = '%' . $page['search'] . '%'; }
            $from = ' FROM inventory_operations o LEFT JOIN contacts c ON c.id = o.contact_id';
            $count = $db->prepare('SELECT COUNT(*)' . $from . $where); $count->execute($params); $page['total'] = (int) $count->fetchColumn();
            $page['pages'] = max(1, (int) ceil($page['total'] / 20));
            $page['currentPage'] = min(inventoryId($_GET['page'] ?? null) ?? 1, $page['pages']);
            $query = $db->prepare('SELECT o.*, c.name AS contact_name' . $from . $where . ' ORDER BY o.id DESC LIMIT 20 OFFSET ' . (($page['currentPage'] - 1) * 20));
            $query->execute($params); $page['operations'] = $query->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Operaciones: ' . $exception->getMessage()); http_response_code(503); $page['available'] = false;
        $page['errors'][] = 'No se pudieron cargar las operaciones. Verifica la instalación de Inventario.';
    }
    renderModulePage('inventario', false, $page);
}
