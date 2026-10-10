<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/inventory-repository.php';
require_once __DIR__ . '/tax-repository.php';
require_once __DIR__ . '/module-page.php';
require_once __DIR__ . '/inventory-stock-repository.php';
require_once __DIR__ . '/storage-repository.php';

function inventoryEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderInventoryProducts(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['inventory_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $page = ['available' => false, 'errors' => [], 'record' => [], 'view' => 'list',
        'csrf' => $_SESSION['inventory_csrf'], 'flash' => $_SESSION['inventory_flash'] ?? null,
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['inventory_flash']);
    try {
        $db = createDatabaseConnection();
        $page['databaseStatus']['connected'] = true;
        $page['available'] = true;
        $id = inventoryId($_GET['id'] ?? null);
        $page['view'] = $id || isset($_GET['new']) ? 'form' : 'list';
        if (isset($_GET['id']) && ! $id) {
            http_response_code(404);
            $page['available'] = false;
            $page['errors'][] = 'El producto solicitado no existe.';
        }
        if ($id) {
            $statement = $db->prepare('SELECT id, name, sale_enabled, purchase_enabled, sale_price, category_id, purchase_tax_id, sale_tax_id FROM inventory_products WHERE id = ?');
            $statement->execute([$id]);
            $page['record'] = $statement->fetch() ?: [];
            if (! $page['record']) {
                http_response_code(404);
                $page['available'] = false;
                $page['errors'][] = 'El producto solicitado no existe.';
            }
        }
        if ($page['view'] === 'form' && $page['available']) {
            $page['categories'] = loadInventoryCategories($db);
            $page['purchaseTaxes'] = loadBusinessTaxes($db, 'compras');
            $page['saleTaxes'] = loadBusinessTaxes($db, 'ventas');
            $page['productStock'] = $id ? loadInventoryProductStock($db, $id) : ['total' => '0.000', 'locations' => []];
            $page['storage'] = loadStorageRelations($db);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403);
                $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view'] !== 'form' || ($_POST['action'] ?? '') !== 'save_product') {
                http_response_code(400);
                $page['errors'][] = 'La operación solicitada no es válida.';
            } else {
                [$data, $page['errors']] = validateInventoryProduct($_POST, $page['categories'], $page['purchaseTaxes'], $page['saleTaxes']);
                $page['record'] = $data + ($id ? ['id' => $id] : []);
                if (! $page['errors']) {
                    saveInventoryProduct($db, $data, $id);
                    $_SESSION['inventory_flash'] = 'Producto guardado correctamente.';
                    header('Location: index.php', true, 303);
                    exit;
                }
            }
        }
        if ($page['view'] === 'list' && $page['available']) {
            $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160, 'UTF-8') : '';
            $where = $page['search'] !== '' ? ' WHERE name LIKE :search' : '';
            $params = $where !== '' ? ['search' => '%' . $page['search'] . '%'] : [];
            $count = $db->prepare('SELECT COUNT(*) FROM inventory_products' . $where);
            $count->execute($params);
            $page['total'] = (int) $count->fetchColumn();
            $page['pages'] = max(1, (int) ceil($page['total'] / 20));
            $page['currentPage'] = min(inventoryId($_GET['page'] ?? null) ?? 1, $page['pages']);
            $offset = ($page['currentPage'] - 1) * 20;
            $list = $db->prepare('SELECT id, name, sale_enabled, purchase_enabled FROM inventory_products'
                . $where . ' ORDER BY name, id LIMIT 20 OFFSET ' . $offset);
            $list->execute($params);
            $page['products'] = $list->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Productos: ' . $exception->getMessage());
        http_response_code(503);
        $page['available'] = false;
        $page['errors'][] = 'No se pudieron cargar los productos. Verifica la conexión y la instalación de Inventario.';
    }
    renderModulePage('inventario', false, $page);
}

function renderInventoryConfiguration(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['inventory_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $page = ['available' => false, 'errors' => [], 'record' => [], 'view' => 'home',
        'csrf' => $_SESSION['inventory_csrf'], 'flash' => $_SESSION['inventory_flash'] ?? null,
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['inventory_flash']);
    try {
        $db = createDatabaseConnection();
        $page['databaseStatus']['connected'] = true;
        $page['categories'] = loadInventoryCategories($db);
        $page['warehouses'] = loadInventoryWarehouses($db);
        $page['locations'] = loadInventoryLocations($db);
        $page['available'] = true;
        $section = $_GET['section'] ?? null;
        $definitions = inventoryConfigurationDefinitions();
        $page['section'] = is_string($section) && isset($definitions[$section]) ? $section : null;
        $id = inventoryId($_GET['id'] ?? null);
        if (($section !== null && $page['section'] === null)
            || ($section === null && (isset($_GET['id']) || isset($_GET['new'])))
            || (isset($_GET['id']) && ! $id)) {
            http_response_code(404);
            $page['available'] = false;
            $page['errors'][] = 'La página solicitada no existe.';
        }
        $page['view'] = $section === null ? 'home' : ($id || isset($_GET['new']) ? 'form' : 'list');
        if ($id && $page['available']) {
            $page['record'] = inventoryCategoryRecord($page[$page['section']], $id) ?? [];
            if (! $page['record']) {
                http_response_code(404);
                $page['available'] = false;
                $page['errors'][] = 'El registro solicitado no existe.';
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403);
                $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view'] !== 'form' || ($_POST['action'] ?? '') !== $definitions[$page['section']]['action']) {
                http_response_code(400);
                $page['errors'][] = 'La operación solicitada no es válida.';
            } else {
                [$data, $page['errors']] = $section === 'categories'
                    ? validateInventoryCategory($_POST, $page['categories'], $id)
                    : validateInventoryStorage($section, $_POST, $page['warehouses'], $page['locations'], $id);
                $page['record'] = $data + ($id ? ['id' => $id] : []);
                if (! $page['errors']) {
                    try {
                        if ($section === 'categories') { saveInventoryCategory($db, $data, $id); }
                        else { saveInventoryStorage($db, $section, $data, $id); }
                        $_SESSION['inventory_flash'] = 'Registro guardado correctamente.';
                        header('Location: configuracion.php?section=' . $section, true, 303);
                        exit;
                    } catch (InvalidArgumentException $exception) {
                        $page['errors'][] = $exception->getMessage();
                    } catch (PDOException $exception) {
                        if (($exception->errorInfo[1] ?? null) !== 1062) {
                            throw $exception;
                        }
                        $page['errors'][] = match ($section) {
                            'categories' => 'Ya existe una categoría con ese nombre dentro de la misma categoría superior.',
                            'warehouses' => 'Ya existe un almacén con ese nombre.',
                            'locations' => 'Ya existe una ubicación con ese nombre dentro del mismo almacén y ubicación superior.',
                        };
                    }
                }
            }
        }
        $records = $page[$page['section'] ?? 'categories'];
        $page['excludedParents'] = $id && $section !== 'warehouses' ? inventoryCategoryDescendants($records, $id) : [];
        $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160, 'UTF-8') : '';
        $term = mb_strtolower($page['search'], 'UTF-8');
        $filtered = array_values(array_filter($records, static function ($record) use ($term, $section, $records, $page): bool {
            $name = $section === 'warehouses' ? $record['name'] : inventoryCategoryPath($records, $record);
            if ($section === 'locations') { $name .= ' ' . (inventoryCategoryRecord($page['warehouses'], (int) $record['warehouse_id'])['name'] ?? ''); }
            return $term === '' || str_contains(mb_strtolower($name, 'UTF-8'), $term);
        }));
        $page['total'] = count($filtered);
        $page['pages'] = max(1, (int) ceil($page['total'] / 20));
        $page['currentPage'] = min(inventoryId($_GET['page'] ?? null) ?? 1, $page['pages']);
        $page['rows'] = array_slice($filtered, ($page['currentPage'] - 1) * 20, 20);
    } catch (PDOException $exception) {
        error_log('Inventario: ' . $exception->getMessage());
        http_response_code(503);
        $page['available'] = false;
        $page['errors'][] = 'No se pudo cargar la configuración. Verifica la conexión y la instalación de Inventario.';
    }
    renderModulePage('inventario', true, $page);
}
