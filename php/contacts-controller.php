<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/contacts-repository.php';
require_once __DIR__ . '/module-page.php';

function contactEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function contactRedirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function renderContactsPage(bool $isConfiguration = false): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['contacts_csrf'] ??= bin2hex(random_bytes(32));
    $page = [
        'csrf' => $_SESSION['contacts_csrf'], 'errors' => [], 'available' => false,
        'flash' => $_SESSION['contacts_flash'] ?? null,
        'section' => null, 'view' => 'list', 'record' => [],
    ];
    unset($_SESSION['contacts_flash']);
    $config = require __DIR__ . '/config/database.php';
    $page['databaseStatus'] = ['connected' => false, 'database' => $config['database'], 'error' => null];
    try {
        $db = createDatabaseConnection();
        $page['databaseStatus']['connected'] = true;
        $page['catalogs'] = loadContactCatalogs($db);
        $page['available'] = true;
        $section = $_GET['section'] ?? null;
        $page['section'] = is_string($section) && isset(contactCatalogDefinitions()[$section]) ? $section : null;
        if ($isConfiguration && (($section !== null && $page['section'] === null)
            || ($page['section'] === null && (isset($_GET['id']) || isset($_GET['new']))))) {
            http_response_code(404);
            $page['errors'][] = 'La sección solicitada no existe.';
            $page['available'] = false;
        }
        $id = contactId($_GET['id'] ?? null);
        if (isset($_GET['id']) && ! $id) {
            http_response_code(404);
            $page['errors'][] = 'El registro solicitado no existe.';
            $page['available'] = false;
        }
        $page['view'] = $isConfiguration && $page['section'] === null
            ? 'configuration-home' : (isset($_GET['new']) || $id ? 'form' : 'list');
        if ($id && $page['available']) {
            if ($isConfiguration) {
                $record = contactCatalogRecord($page['catalogs'][$page['section']], $id);
            } else {
                $statement = $db->prepare('SELECT * FROM contacts WHERE id = ?');
                $statement->execute([$id]);
                $record = $statement->fetch();
            }
            if (! $record) {
                http_response_code(404);
                $page['errors'][] = 'El registro solicitado no existe.';
                $page['available'] = false;
            } else {
                $page['record'] = $record;
            }
        }
        if (! $id && ! $isConfiguration) {
            $clientType = array_values(array_filter($page['catalogs']['types'], static fn ($type) => ($type['system_key'] ?? null) === 'client'));
            $page['record'] = ['entity_kind' => 'person', 'type_id' => $clientType[0]['id'] ?? '', 'active' => 1];
        }
        if (! $isConfiguration && $page['view'] === 'form' && $page['available']) {
            $page['salespeople'] = loadContactSalespeople($db, contactId($page['record']['salesperson_id'] ?? null), $id);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page['available']) {
            $token = $_POST['csrf'] ?? null;
            if (! is_string($token) || ! hash_equals($page['csrf'], $token)) {
                http_response_code(403);
                $page['errors'][] = 'La sesión del formulario no es válida. Recarga la página e inténtalo nuevamente.';
            } elseif ($isConfiguration && $page['view'] === 'form' && ($_POST['action'] ?? '') === 'save_catalog') {
                [$data, $page['errors']] = validateContactCatalog($page['section'], $_POST, $page['catalogs'], $id);
                $page['record'] = $data + ($id ? ['id' => $id] : []);
                if (! $page['errors']) {
                    try {
                        saveContactCatalog($db, $page['section'], $data, $id);
                        $_SESSION['contacts_flash'] = 'Registro guardado correctamente.';
                        contactRedirect('configuracion.php?section=' . $page['section']);
                    } catch (PDOException $exception) {
                        if (($exception->errorInfo[1] ?? null) !== 1062) {
                            throw $exception;
                        }
                        $page['errors'][] = 'Ya existe un registro con ese nombre o código en esta localización.';
                    }
                }
            } elseif (! $isConfiguration && ($_POST['action'] ?? '') === 'save_contact' && $page['view'] === 'form') {
                [$data, $page['errors']] = validateContact($_POST, $page['catalogs'], $page['salespeople'], $id);
                $page['record'] = $data + ($id ? ['id' => $id] : []) + ['active' => $page['record']['active'] ?? 1];
                if (! $page['errors']) {
                    try {
                        $savedId = saveContact($db, $data, $id);
                        $_SESSION['contacts_flash'] = 'Contacto guardado correctamente.';
                        contactRedirect('index.php?id=' . $savedId);
                    } catch (InvalidArgumentException $exception) {
                        $page['errors'][] = $exception->getMessage();
                    }
                }
            } elseif (! $isConfiguration && ($_POST['action'] ?? '') === 'toggle_active' && $id) {
                $db->prepare('UPDATE contacts SET active = NOT active WHERE id = ?')->execute([$id]);
                $_SESSION['contacts_flash'] = $page['record']['active'] ? 'Contacto archivado.' : 'Contacto restaurado.';
                contactRedirect('index.php?id=' . $id);
            } else {
                http_response_code(400);
                $page['errors'][] = 'La operación solicitada no es válida.';
            }
        }
        if (! $isConfiguration && $page['view'] === 'list' && $page['available']) {
            $page['search'] = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160, 'UTF-8') : '';
            $page['typeFilter'] = contactId($_GET['type'] ?? null);
            $page['statusFilter'] = ($_GET['status'] ?? '') === 'archived' ? 'archived' : 'active';
            $where = ['c.active = :active'];
            $params = ['active' => $page['statusFilter'] === 'active' ? 1 : 0];
            if ($page['search'] !== '') {
                $where[] = '(c.name LIKE :name OR c.tax_id LIKE :tax OR c.email LIKE :email OR CONCAT(d.code, \'-\', c.tax_id) LIKE :document)';
                foreach (['name', 'tax', 'email', 'document'] as $key) {
                    $params[$key] = '%' . $page['search'] . '%';
                }
            }
            if ($page['typeFilter']) {
                $where[] = 'c.type_id = :type';
                $params['type'] = $page['typeFilter'];
            }
            $filter = implode(' AND ', $where);
            $count = $db->prepare('SELECT COUNT(*) FROM contacts c LEFT JOIN contact_document_types d ON d.id = c.document_type_id WHERE ' . $filter);
            $count->execute($params);
            $page['total'] = (int) $count->fetchColumn();
            $page['pages'] = max(1, (int) ceil($page['total'] / 20));
            $page['currentPage'] = min(contactId($_GET['page'] ?? null) ?? 1, $page['pages']);
            $offset = ($page['currentPage'] - 1) * 20;
            $list = $db->prepare('SELECT c.*, t.name AS type_name, d.code AS document_code, city.name AS city_name, country.name AS country_name
                FROM contacts c JOIN contact_types t ON t.id = c.type_id
                LEFT JOIN contact_document_types d ON d.id = c.document_type_id
                LEFT JOIN contact_cities city ON city.id = c.city_id
                LEFT JOIN contact_countries country ON country.id = c.country_id
                WHERE ' . $filter . ' ORDER BY c.name, c.id LIMIT 20 OFFSET ' . $offset);
            $list->execute($params);
            $page['contacts'] = $list->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Contactos: ' . $exception->getMessage());
        http_response_code(503);
        $page['available'] = false;
        $page['errors'][] = 'No se pudieron cargar los contactos. Verifica la conexión y la instalación del módulo.';
    }
    renderModulePage('contactos', $isConfiguration, $page);
}
