<?php
declare(strict_types=1);
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/module-page.php';

function accountingEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function accountingId(mixed $value): ?int
{
    if (! is_scalar($value)) { return null; }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function renderAccountingConfiguration(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $_SESSION['accounting_csrf'] ??= bin2hex(random_bytes(32));
    $config = require __DIR__ . '/config/database.php';
    $page = ['available' => false, 'errors' => [], 'record' => [], 'view' => 'home',
        'csrf' => $_SESSION['accounting_csrf'], 'flash' => $_SESSION['accounting_flash'] ?? null,
        'databaseStatus' => ['connected' => false, 'database' => $config['database'], 'error' => null]];
    unset($_SESSION['accounting_flash']);
    try {
        $db = createDatabaseConnection();
        $page['databaseStatus']['connected'] = true;
        $page['available'] = true;
        $section = $_GET['section'] ?? null;
        $id = accountingId($_GET['id'] ?? null);
        if (($section !== null && $section !== 'payment_terms')
            || ($section === null && (isset($_GET['id']) || isset($_GET['new'])))
            || (isset($_GET['id']) && ! $id)) {
            http_response_code(404); $page['available'] = false; $page['errors'][] = 'La página solicitada no existe.';
        }
        $page['view'] = $section === null ? 'home' : ($id || isset($_GET['new']) ? 'form' : 'list');
        if ($id && $page['available']) {
            $statement = $db->prepare('SELECT id, name, days FROM accounting_payment_terms WHERE id = ?');
            $statement->execute([$id]); $page['record'] = $statement->fetch() ?: [];
            if (! $page['record']) { http_response_code(404); $page['available'] = false; $page['errors'][] = 'El término de pago no existe.'; }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403); $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página.';
            } elseif ($page['view'] !== 'form' || ($_POST['action'] ?? '') !== 'save_payment_term') {
                http_response_code(400); $page['errors'][] = 'La operación solicitada no es válida.';
            } else {
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                $days=filter_var($_POST['days']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>36500]]);
                $page['record'] = ['name' => $name, 'days' => $days===false?0:$days] + ($id ? ['id' => $id] : []);
                if ($days===false || $name === '' || mb_strlen($name, 'UTF-8') > 120) {
                    $page['errors'][] = 'Escribe un nombre de entre 1 y 120 caracteres.';
                } else {
                    try {
                        $sql = $id ? 'UPDATE accounting_payment_terms SET name = :name, days = :days WHERE id = :id'
                            : 'INSERT INTO accounting_payment_terms (name,days) VALUES (:name,:days)';
                        $db->prepare($sql)->execute(['name' => $name, 'days' => $days] + ($id ? ['id' => $id] : []));
                        $_SESSION['accounting_flash'] = 'Término de pago guardado correctamente.';
                        header('Location: configuracion.php?section=payment_terms', true, 303); exit;
                    } catch (PDOException $exception) {
                        if (($exception->errorInfo[1] ?? null) !== 1062) { throw $exception; }
                        $page['errors'][] = 'Ya existe un término de pago con ese nombre.';
                    }
                }
            }
        }
        $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120, 'UTF-8') : '';
        $where = $page['search'] === '' ? '' : ' WHERE name LIKE :search';
        $params = $where === '' ? [] : ['search' => '%' . $page['search'] . '%'];
        $count = $db->prepare('SELECT COUNT(*) FROM accounting_payment_terms' . $where);
        $count->execute($params); $page['total'] = (int) $count->fetchColumn();
        $page['pages'] = max(1, (int) ceil($page['total'] / 20));
        $page['currentPage'] = min(accountingId($_GET['page'] ?? null) ?? 1, $page['pages']);
        $list = $db->prepare('SELECT id, name FROM accounting_payment_terms' . $where . ' ORDER BY name, id LIMIT 20 OFFSET ' . (($page['currentPage'] - 1) * 20));
        $list->execute($params); $page['terms'] = $list->fetchAll();
    } catch (PDOException $exception) {
        error_log('Contabilidad: ' . $exception->getMessage()); http_response_code(503); $page['available'] = false;
        $page['errors'][] = 'No se pudieron cargar los términos de pago. Verifica la conexión y la instalación de Contabilidad.';
    }
    renderModulePage('contabilidad', true, $page);
}
