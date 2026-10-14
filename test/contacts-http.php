<?php

declare(strict_types=1);
require_once __DIR__.'/http-session.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/php/connection.php';

$baseUrl = $argv[1] ?? 'http://localhost/project/modules/contactos/';
$cookieFile = tempnam(sys_get_temp_dir(), 'contacts-test-');
$db = createDatabaseConnection();
$suffix = bin2hex(random_bytes(8));
$prefix = 'Test HTTP ' . $suffix;
$created = [];

function contactHttp(string $baseUrl, string $path, string $cookieFile, ?array $post = null): array
{
    $curl = testHttpCurl($baseUrl . $path, $cookie ?? $cookieJar ?? $cookieFile ?? null);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        throw new RuntimeException(curl_error($curl));
    }
    curl_close($curl);
    return [$status, $body];
}

function checkContactHttp(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    [$status, $body] = contactHttp($baseUrl, 'index.php?new=1', $cookieFile);
    checkContactHttp($status === 200 && preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $matches) === 1, 'No se pudo abrir el formulario.');
    $token = $matches[1];
    $usedCodes = $db->query('SELECT code FROM contact_document_types')->fetchAll(PDO::FETCH_COLUMN);
    $freeCodes = array_values(array_diff(range('A', 'Z'), $usedCodes));
    checkContactHttp($freeCodes !== [], 'No hay una letra disponible para probar la creación de tipos de documento.');
    foreach ([
        'countries' => ['table' => 'contact_countries', 'parent' => []],
        'states' => ['table' => 'contact_states', 'parent' => ['country_id' => 'countries']],
        'cities' => ['table' => 'contact_cities', 'parent' => ['state_id' => 'states']],
        'types' => ['table' => 'contact_types', 'parent' => []],
        'document_types' => ['table' => 'contact_document_types', 'parent' => []],
    ] as $section => $definition) {
        $post = ['csrf' => $token, 'action' => 'save_catalog', 'name' => $prefix . ' ' . $section];
        if ($section === 'document_types') {
            $post['code'] = strtolower($freeCodes[0]);
        }
        foreach ($definition['parent'] as $field => $parent) {
            $post[$field] = $created[$parent];
        }
        [$status] = contactHttp($baseUrl, 'configuracion.php?section=' . $section . '&new=1', $cookieFile, $post);
        checkContactHttp($status === 303, 'No se pudo crear el catálogo: ' . $section);
        $query = $db->prepare('SELECT id FROM ' . $definition['table'] . ' WHERE name = ?');
        $query->execute([$post['name']]);
        $created[$section] = (int) $query->fetchColumn();
        checkContactHttp($created[$section] > 0, 'El catálogo no se guardó.');
        [$listStatus, $listBody] = contactHttp($baseUrl, 'configuracion.php?section=' . $section, $cookieFile);
        checkContactHttp($listStatus === 200 && str_contains($listBody, '<table') && ! str_contains($listBody, 'value="save_catalog"'), 'El listado mezcla el formulario.');
        [$formStatus, $formBody] = contactHttp($baseUrl, 'configuracion.php?section=' . $section . '&id=' . $created[$section], $cookieFile);
        checkContactHttp($formStatus === 200 && str_contains($formBody, 'value="save_catalog"') && ! str_contains($formBody, '<table'), 'El formulario mezcla el listado.');
        if ($section === 'document_types') {
            $post['name'] = $prefix . ' documento editado';
            [$editStatus] = contactHttp($baseUrl, 'configuracion.php?section=document_types&id=' . $created[$section], $cookieFile, $post);
            checkContactHttp($editStatus === 303, 'No se pudo editar la descripción del tipo de documento.');
            [$duplicateStatus, $duplicateBody] = contactHttp($baseUrl, 'configuracion.php?section=document_types&new=1', $cookieFile, $post);
            checkContactHttp($duplicateStatus === 200 && str_contains($duplicateBody, 'Ya existe un registro'), 'Se permitió duplicar una letra de documento.');
        }
    }
    $documentType = (int) $db->query("SELECT id FROM contact_document_types WHERE code = 'V'")->fetchColumn();
    $roles = $db->query('SELECT id, system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $roles = array_flip($roles);
    [$status] = contactHttp($baseUrl, 'index.php?new=1', $cookieFile,
        ['csrf' => $token, 'action' => 'save_contact', 'name' => $prefix . ' vendedor', 'entity_kind' => 'person', 'type_id' => $roles['salesperson']]);
    checkContactHttp($status === 303, 'No se pudo crear el vendedor de prueba.');
    $query = $db->prepare('SELECT id FROM contacts WHERE name = ?');
    $query->execute([$prefix . ' vendedor']);
    $created['salesperson'] = (int) $query->fetchColumn();
    $post = ['csrf' => $token, 'action' => 'save_contact', 'name' => $prefix . ' <cliente>',
        'entity_kind' => 'company', 'type_id' => $roles['client'], 'email' => 'test@example.test',
        'country_id' => $created['countries'], 'state_id' => $created['states'], 'city_id' => $created['cities'],
        'document_type_id' => $documentType, 'tax_id' => 'V-12345678',
        'salesperson_id' => $created['salesperson'], 'notes' => 'Notas conservadas en la pestaña'];
    [$status] = contactHttp($baseUrl, 'index.php?new=1', $cookieFile, $post);
    checkContactHttp($status === 303, 'El formulario no creó el contacto.');
    $query = $db->prepare('SELECT id FROM contacts WHERE name = ?');
    $query->execute([$post['name']]);
    $created['contact'] = (int) $query->fetchColumn();
    checkContactHttp($created['contact'] > 0, 'No se guardó el contacto en MySQL.');
    [$status, $body] = contactHttp($baseUrl, 'index.php?id=' . $created['contact'], $cookieFile);
    checkContactHttp($status === 200 && str_contains($body, '&lt;cliente&gt;') && ! str_contains($body, '<cliente>'), 'El formulario no escapó el nombre.');
    checkContactHttp(str_contains($body, 'data-notebook') && str_contains($body, $post['notes']) && ! str_contains($body, 'data-salesperson-field hidden'), 'El notebook o la asignación para clientes no se renderizaron correctamente.');
    $assignment = $db->prepare('SELECT salesperson_id, notes FROM contacts WHERE id = ?');
    $assignment->execute([$created['contact']]);
    $saved = $assignment->fetch();
    checkContactHttp((int) $saved['salesperson_id'] === $created['salesperson'] && $saved['notes'] === $post['notes'], 'La asignación o las notas no persistieron.');
    $post['name'] = $prefix . ' editado';
    [$status] = contactHttp($baseUrl, 'index.php?id=' . $created['contact'], $cookieFile, $post);
    checkContactHttp($status === 303, 'No se pudo editar el contacto.');
    $query = $db->prepare('SELECT name, active FROM contacts WHERE id = ?');
    $query->execute([$created['contact']]);
    checkContactHttp($query->fetch()['name'] === $post['name'], 'La edición no persistió.');
    foreach ([0, 1] as $active) {
        [$status] = contactHttp($baseUrl, 'index.php?id=' . $created['contact'], $cookieFile,
            ['csrf' => $token, 'action' => 'toggle_active']);
        $query->execute([$created['contact']]);
        checkContactHttp($status === 303 && (int) $query->fetch()['active'] === $active, 'Archivo/restauración incorrectos.');
    }
    [$status, $body] = contactHttp($baseUrl, 'index.php?q=' . rawurlencode($prefix) . '&type=' . $post['type_id'], $cookieFile);
    checkContactHttp($status === 200 && str_contains($body, $post['name']), 'La búsqueda o el filtro no encontraron el contacto.');
    checkContactHttp(str_contains($body, 'V-12345678') && ! str_contains($body, 'V-V-12345678'), 'El documento no se mostró correctamente.');
    [$status, $body] = contactHttp($baseUrl, 'index.php?q=V-12345678&type=' . $post['type_id'], $cookieFile);
    checkContactHttp($status === 200 && str_contains($body, $post['name']), 'La búsqueda por documento completo falló.');
    echo "PASS: formularios HTTP, catálogos, creación, edición, archivo, restauración, búsqueda y escape HTML.\n";
} finally {
    foreach (['contact' => 'contacts', 'salesperson' => 'contacts', 'cities' => 'contact_cities', 'states' => 'contact_states',
        'countries' => 'contact_countries', 'types' => 'contact_types', 'document_types' => 'contact_document_types'] as $key => $table) {
        if (isset($created[$key])) {
            $db->prepare('DELETE FROM ' . $table . ' WHERE id = ? AND name LIKE ?')->execute([$created[$key], $prefix . '%']);
        }
    }
    if ($cookieFile !== false && is_file($cookieFile)) {
        unlink($cookieFile);
    }
}
