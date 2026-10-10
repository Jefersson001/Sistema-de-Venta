<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/connection.php';

$db = createDatabaseConnection();
$base = $argv[1] ?? 'http://localhost/project/modules/inventario/';
$cookie = tempnam(sys_get_temp_dir(), 'storage-test-');
$prefix = 'Test almacenes ' . bin2hex(random_bytes(8));
$warehouses = []; $locations = [];

function expectStorage(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

function storageRequest(string $base, string $path, string $cookie, ?array $post = null): array
{
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false) { throw new RuntimeException(curl_error($curl)); }
    curl_close($curl);
    expectStorage(! preg_match('/Fatal error|Warning:|Notice:/', $body), 'La respuesta contiene un error PHP.');
    return [$status, $body];
}

try {
    [$status, $home] = storageRequest($base, 'configuracion.php', $cookie);
    expectStorage($status === 200 && str_contains($home, 'Gestión de almacenes') && str_contains($home, 'section=warehouses')
        && str_contains($home, 'section=locations') && ! str_contains($home, '<table'), 'Falta el menú de Gestión de almacenes.');
    [$status, $form] = storageRequest($base, 'configuracion.php?section=warehouses&new=1', $cookie);
    expectStorage($status === 200 && ! str_contains($form, '<table') && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'El formulario de almacén no es independiente.');
    $token = $matches[1];
    foreach (['principal', 'secundario'] as $suffix) {
        $name = $prefix . ' ' . $suffix;
        [$status] = storageRequest($base, 'configuracion.php?section=warehouses&new=1', $cookie, ['csrf' => $token, 'action' => 'save_warehouse', 'name' => $name]);
        expectStorage($status === 303, 'No se guardó un almacén.');
        $lookup = $db->prepare('SELECT id FROM inventory_warehouses WHERE name = ?'); $lookup->execute([$name]); $warehouses[] = (int) $lookup->fetchColumn();
        expectStorage(end($warehouses) > 0, 'El almacén no persistió.');
    }
    [$status, $body] = storageRequest($base, 'configuracion.php?section=warehouses&new=1', $cookie,
        ['csrf' => $token, 'action' => 'save_warehouse', 'name' => $prefix . ' principal']);
    expectStorage($status === 200 && str_contains($body, 'Ya existe un almacén'), 'Se permitió duplicar un almacén.');
    foreach ([[$warehouses[0], null, 'raíz'], [$warehouses[1], null, 'raíz'], [$warehouses[0], 0, 'estante']] as [$warehouse, $parentIndex, $suffix]) {
        $name = $prefix . ' ' . $suffix;
        $parent = $parentIndex === null ? null : $locations[$parentIndex];
        [$status] = storageRequest($base, 'configuracion.php?section=locations&new=1', $cookie,
            ['csrf' => $token, 'action' => 'save_location', 'name' => $name, 'warehouse_id' => $warehouse, 'parent_id' => $parent ?? '']);
        expectStorage($status === 303, 'No se guardó una ubicación.');
        $lookup = $db->prepare('SELECT id FROM inventory_locations WHERE name = ? AND warehouse_id = ? AND parent_id <=> ?');
        $lookup->execute([$name, $warehouse, $parent]); $locations[] = (int) $lookup->fetchColumn();
        expectStorage(end($locations) > 0, 'La ubicación no persistió.');
    }
    foreach (['warehouses' => 'save_warehouse', 'locations' => 'save_location'] as $section => $action) {
        [$status, $list] = storageRequest($base, 'configuracion.php?section=' . $section . '&q=' . rawurlencode($prefix), $cookie);
        expectStorage($status === 200 && str_contains($list, '<table') && ! str_contains($list, 'value="' . $action . '"'), 'El listado mezcla el formulario.');
    }
    [$status, $form] = storageRequest($base, 'configuracion.php?section=locations&id=' . $locations[2], $cookie);
    expectStorage($status === 200 && ! str_contains($form, '<table') && str_contains($form, 'data-location-warehouse'), 'El formulario de ubicación no es independiente.');
    $rootPost = ['csrf' => $token, 'action' => 'save_location', 'name' => $prefix . ' raíz', 'warehouse_id' => $warehouses[0], 'parent_id' => ''];
    foreach ([['parent_id' => $locations[0]], ['parent_id' => $locations[2]], ['parent_id' => $locations[1]], ['warehouse_id' => $warehouses[1]], ['warehouse_id' => '']] as $invalid) {
        [$status, $body] = storageRequest($base, 'configuracion.php?section=locations&id=' . $locations[0], $cookie, array_replace($rootPost, $invalid));
        expectStorage($status === 200 && str_contains($body, 'notice-error'), 'Se aceptó una relación circular, un almacén inválido o una jerarquía incoherente.');
    }
    [$status, $body] = storageRequest($base, 'configuracion.php?section=locations&new=1', $cookie, $rootPost);
    expectStorage($status === 200 && str_contains($body, 'Ya existe una ubicación'), 'Se permitió duplicar una ubicación en el mismo almacén.');
    [$status] = storageRequest($base, 'configuracion.php?section=locations&id=' . $locations[2], $cookie,
        ['csrf' => $token, 'action' => 'save_location', 'name' => $prefix . ' estante editado', 'warehouse_id' => $warehouses[1], 'parent_id' => $locations[1]]);
    expectStorage($status === 303, 'No se pudo reorganizar una ubicación sin sububicaciones.');
    $lookup = $db->prepare('SELECT name, warehouse_id, parent_id FROM inventory_locations WHERE id = ?'); $lookup->execute([$locations[2]]); $saved = $lookup->fetch();
    expectStorage($saved['name'] === $prefix . ' estante editado' && (int) $saved['warehouse_id'] === $warehouses[1] && (int) $saved['parent_id'] === $locations[1], 'La edición no persistió.');
    [$status] = storageRequest($base, 'configuracion.php?section=warehouses&id=' . $warehouses[0], $cookie,
        ['csrf' => $token, 'action' => 'save_warehouse', 'name' => $prefix . ' principal editado']); expectStorage($status === 303, 'No se editó el almacén.');
    [$status] = storageRequest($base, 'configuracion.php?section=warehouses&new=1', $cookie, ['action' => 'save_warehouse', 'name' => $prefix]); expectStorage($status === 403, 'Se aceptó un formulario sin token.');
    [$status] = storageRequest($base, 'configuracion.php?section=locations&id=999999999', $cookie); expectStorage($status === 404, 'Un registro inexistente no devuelve 404.');
    echo "PASS: Gestión de almacenes, vistas tree/form, creación, edición, relación almacén/ubicación, sububicaciones y validación.\n";
} finally {
    foreach (array_reverse($locations) as $id) { $db->prepare('DELETE FROM inventory_locations WHERE id = ? AND name LIKE ?')->execute([$id, $prefix . '%']); }
    foreach ($warehouses as $id) { $db->prepare('DELETE FROM inventory_warehouses WHERE id = ? AND name LIKE ?')->execute([$id, $prefix . '%']); }
    if ($cookie !== false && is_file($cookie)) { unlink($cookie); }
}
