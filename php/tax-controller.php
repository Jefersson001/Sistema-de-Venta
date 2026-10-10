<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/tax-repository.php';
require_once __DIR__ . '/module-page.php';

function taxEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderTaxConfiguration(string $moduleKey): void
{
    if (! in_array($moduleKey, ['compras', 'ventas'], true)) {
        http_response_code(404);
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['taxes_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $page = ['available' => false, 'errors' => [], 'record' => [], 'view' => 'home',
        'csrf' => $_SESSION['taxes_csrf'], 'flash' => $_SESSION['taxes_flash'][$moduleKey] ?? null,
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['taxes_flash'][$moduleKey]);
    try {
        $db = createDatabaseConnection();
        $page['databaseStatus']['connected'] = true;
        $page['available'] = true;
        $page['taxes'] = loadBusinessTaxes($db, $moduleKey);
        $section = $_GET['section'] ?? null;
        $id = businessTaxId($_GET['id'] ?? null);
        if (($section !== null && $section !== 'taxes')
            || ($section === null && (isset($_GET['new']) || isset($_GET['id'])))
            || (isset($_GET['id']) && ! $id)) {
            http_response_code(404);
            $page['available'] = false;
            $page['errors'][] = 'La página solicitada no existe.';
        }
        $page['view'] = $section === null ? 'home' : ($id || isset($_GET['new']) ? 'form' : 'list');
        if ($id && $page['available']) {
            $statement = $db->prepare('SELECT id, name, rate FROM business_taxes WHERE id = ? AND module_key = ?');
            $statement->execute([$id, $moduleKey]);
            $page['record'] = $statement->fetch() ?: [];
            if (! $page['record']) {
                http_response_code(404);
                $page['available'] = false;
                $page['errors'][] = 'El impuesto solicitado no existe en este módulo.';
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403);
                $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view'] !== 'form' || ($_POST['action'] ?? '') !== 'save_tax') {
                http_response_code(400);
                $page['errors'][] = 'La operación solicitada no es válida.';
            } else {
                [$data, $page['errors']] = validateBusinessTax($_POST);
                $page['record'] = $data + ($id ? ['id' => $id] : []);
                if (! $page['errors']) {
                    try {
                        saveBusinessTax($db, $moduleKey, $data, $id);
                        $_SESSION['taxes_flash'][$moduleKey] = 'Impuesto guardado correctamente.';
                        header('Location: configuracion.php?section=taxes', true, 303);
                        exit;
                    } catch (PDOException $exception) {
                        if (($exception->errorInfo[1] ?? null) !== 1062) {
                            throw $exception;
                        }
                        $page['errors'][] = 'Ya existe un impuesto con ese nombre en este módulo.';
                    }
                }
            }
        }
        $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120, 'UTF-8') : '';
        $term = mb_strtolower($page['search'], 'UTF-8');
        $filtered = array_values(array_filter($page['taxes'], static fn ($tax) => $term === '' || str_contains(mb_strtolower($tax['name'], 'UTF-8'), $term)));
        $page['total'] = count($filtered);
        $page['pages'] = max(1, (int) ceil($page['total'] / 20));
        $page['currentPage'] = min(businessTaxId($_GET['page'] ?? null) ?? 1, $page['pages']);
        $page['rows'] = array_slice($filtered, ($page['currentPage'] - 1) * 20, 20);
    } catch (PDOException $exception) {
        error_log('Impuestos: ' . $exception->getMessage());
        http_response_code(503);
        $page['available'] = false;
        $page['errors'][] = 'No se pudieron cargar los impuestos. Verifica la conexión y la instalación del catálogo.';
    }
    renderModulePage($moduleKey, true, $page);
}
