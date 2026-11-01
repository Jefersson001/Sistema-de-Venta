<?php

declare(strict_types=1);

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/contacts-repository.php';
require_once __DIR__ . '/contact-attachments.php';
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
    $salesWizard = !$isConfiguration && ($_GET['sales_wizard'] ?? '') === '1';
    if ($salesWizard && (!is_string($_GET['wizard_scope'] ?? null) || !hash_equals(companyScopeToken(), $_GET['wizard_scope']))) {
        authStop(409, 'La selección de empresas cambió. Cierra esta ventana y vuelve a abrir Crear cliente.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['contacts_csrf'] ??= bin2hex(random_bytes(32));
    $page = [
        'csrf' => $_SESSION['contacts_csrf'], 'errors' => [], 'available' => false,
        'flash' => $_SESSION['contacts_flash'] ?? null,
        'section' => null, 'view' => 'list', 'record' => [], 'salesWizard' => $salesWizard,
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
            $page['attachments'] = $id ? loadContactAttachments($db, $id) : [];
            $page['addresses']=$id?loadContactAddresses($db,$id):[];
            $page['storage'] = loadStorageRelations($db);
            $page['salespeople'] = loadContactSalespeople($db, contactId($page['record']['salesperson_id'] ?? null), $id);
            if(permissionScope('contactos.contacts',$id?'edit':'create')==='own'){$page['salespeople']=array_values(array_filter($page['salespeople'],static fn($r)=>(int)$r['id']===permissionContact()));if(!$id)$page['record']['salesperson_id']=permissionContact();}
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
                $contactInput=$_POST;if($id&&!array_key_exists('municipality_id',$contactInput))$contactInput['municipality_id']=$page['record']['municipality_id']??'';
                if($id&&!array_key_exists('latitude',$contactInput)&&!array_key_exists('longitude',$contactInput)){ $contactInput['latitude']=$page['record']['latitude']??'';$contactInput['longitude']=$page['record']['longitude']??''; }
                [$data, $page['errors']] = validateContact($contactInput, $page['catalogs'], $page['salespeople'], $id, $page['storage']);
                [$addressChanges,$addressErrors]=validateContactAddressLines($_POST,$page['catalogs'],$page['addresses']);
                $page['errors']=array_merge($page['errors'],$addressErrors);$page['addressInput']=$addressChanges;
                [$uploads, $removeFiles, $fileErrors] = validateContactAttachments($_POST, $_FILES);
                $page['errors'] = array_merge($page['errors'], $fileErrors);
                $page['record'] = $data + ($id ? ['id' => $id] : []) + ['active' => $page['record']['active'] ?? 1];
                if (! $page['errors']) {
                    try {
                        if ($salesWizard) {
                            $type = contactCatalogRecord($page['catalogs']['types'], (int)$data['type_id']);
                            if (($type['system_key'] ?? '') !== 'client' || $id) throw new InvalidArgumentException('Esta ventana permite crear un cliente nuevo.');
                        }
                        $savedId = saveContactWithAttachments($db, $data, $id, $uploads, $removeFiles, $addressChanges, $addressChanges!==null?($_POST['addresses_revision']??''):null);
                        if ($salesWizard) contactRedirect('index.php?' . http_build_query(['id' => $savedId, 'sales_wizard' => '1', 'created' => '1', 'wizard_scope' => companyScopeToken()]));
                        $_SESSION['contacts_flash'] = 'Contacto guardado correctamente.';
                        contactRedirect('index.php?id=' . $savedId);
                    } catch (InvalidArgumentException $exception) {
                        $page['errors'][] = $exception->getMessage();
                    } catch (RuntimeException $exception) {
                        error_log('Archivos de contacto: '.$exception->getMessage());
                        $page['errors'][] = 'No se pudo guardar el contacto con sus archivos. Inténtalo nuevamente.';
                    }
                }
                if ($page['errors'] && !empty($_FILES['contact_files']['name'])) $page['errors'][] = 'Si seleccionaste archivos nuevos, vuelve a seleccionarlos después de corregir los datos.';
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
            $where = ['c.active = :active',permissionWhere('contactos.contacts','c')];
            $params = ['active' => $page['statusFilter'] === 'active' ? 1 : 0];
            if ($page['search'] !== '') {
                $where[] = '(c.name LIKE :name OR c.tax_id LIKE :tax OR c.email LIKE :email OR c.phone LIKE :phone OR c.mobile LIKE :mobile OR CONCAT(d.code, \'-\', c.tax_id) LIKE :document)';
                foreach (['name', 'tax', 'email', 'phone', 'mobile', 'document'] as $key) {
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
    if ($salesWizard && $page['available'] && isset($page['record']['id']) && ($_GET['created'] ?? '') === '1') {
        $record = $page['record'];
        $type = contactCatalogRecord($page['catalogs']['types'], (int)$record['type_id']);
        if (($type['system_key'] ?? '') === 'client' && $record['active'] && permissionFilterContacts([$record])) {
            $page['wizardCustomer'] = ['id' => (int)$record['id'], 'name' => $record['name'], 'address' => contactAddressText($record,$page['catalogs']), 'addresses'=>loadContactAddresses($db,(int)$record['id']), 'salesperson_id' => (int)($record['salesperson_id'] ?? 0), 'scope' => companyScopeToken()];
        }
    }
    renderModulePage('contactos', $isConfiguration, $page);
}
