<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/connection.php';

$db = createDatabaseConnection();
$base = $argv[1] ?? 'http://localhost/project/modules/';
$cookie = tempnam(sys_get_temp_dir(), 'contact-storage-');
$prefix = 'Test relación ' . bin2hex(random_bytes(8));
$warehouses = []; $location = null; $contacts = [];

function expectRelation(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

function relationRequest(string $base, string $path, string $cookie, ?array $post = null): array
{
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false) { throw new RuntimeException(curl_error($curl)); }
    curl_close($curl);
    expectRelation(! preg_match('/Fatal error|Warning:|Notice:/', $body), 'La respuesta contiene un error PHP.');
    return [$status, $body];
}

try {
    $roles = array_flip($db->query('SELECT id, system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach (['principal', 'secundario'] as $suffix) {
        $db->prepare('INSERT INTO inventory_warehouses (name) VALUES (?)')->execute([$prefix . ' ' . $suffix]); $warehouses[] = (int) $db->lastInsertId();
    }
    $db->prepare('INSERT INTO inventory_locations (name, warehouse_id) VALUES (?, ?)')->execute([$prefix . ' estante', $warehouses[0]]); $location = (int) $db->lastInsertId();
    [$status, $form] = relationRequest($base, 'contactos/index.php?new=1', $cookie);
    expectRelation($status === 200 && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'No se abrió Contactos.');
    $token = $matches[1];
    $sellerPost = ['csrf' => $token, 'action' => 'save_contact', 'name' => $prefix . ' vendedor', 'type_id' => $roles['salesperson'],
        'entity_kind' => 'person', 'warehouse_id' => $warehouses[0], 'location_id' => $location];
    [$status] = relationRequest($base, 'contactos/index.php?new=1', $cookie, $sellerPost); expectRelation($status === 303, 'No se guardó el almacén del vendedor.');
    $lookup = $db->prepare('SELECT id, warehouse_id, location_id FROM contacts WHERE name = ?'); $lookup->execute([$sellerPost['name']]); $seller = $lookup->fetch();
    $contacts['seller'] = (int) $seller['id'];
    expectRelation((int) $seller['warehouse_id'] === $warehouses[0] && (int) $seller['location_id'] === $location, 'No se guardó la pareja ubicación/almacén.');
    $customerPost = ['csrf' => $token, 'action' => 'save_contact', 'name' => $prefix . ' cliente', 'type_id' => $roles['client'],
        'entity_kind' => 'person', 'salesperson_id' => $contacts['seller'], 'warehouse_id' => $warehouses[1], 'location_id' => ''];
    [$status] = relationRequest($base, 'contactos/index.php?new=1', $cookie, $customerPost); expectRelation($status === 303, 'No se guardó el cliente relacionado.');
    $lookup->execute([$customerPost['name']]); $customer = $lookup->fetch(); $contacts['customer'] = (int) $customer['id'];
    expectRelation($customer['warehouse_id'] === null && $customer['location_id'] === null, 'El cliente guardó un almacén independiente de su vendedor.');
    [$status, $form] = relationRequest($base, 'contactos/index.php?id=' . $contacts['customer'], $cookie);
    expectRelation($status === 200 && ! str_contains($form, 'data-related-storage') && ! str_contains($form, 'data-storage-client-field'), 'El cliente sigue mostrando un campo de almacén.');
    [$status, $sellerForm] = relationRequest($base, 'contactos/index.php?id=' . $contacts['seller'], $cookie);
    expectRelation($status === 200 && ! str_contains($sellerForm, '<optgroup') && str_contains($sellerForm, 'data-related-warehouse') && ! str_contains($sellerForm, 'data-related-location'), 'La ficha del vendedor debe mostrar solo el selector de almacén.');
    [$status, $stockForm] = relationRequest($base, 'inventario/configuracion.php?section=locations&id=' . $location, $cookie);
    expectRelation($status === 200 && preg_match('/name="csrf" value="([a-f0-9]+)"/', $stockForm, $stockMatches) === 1, 'No se abrió la ubicación.');
    [$status, $body] = relationRequest($base, 'inventario/configuracion.php?section=locations&id=' . $location, $cookie,
        ['csrf' => $stockMatches[1], 'action' => 'save_location', 'name' => $prefix . ' estante', 'warehouse_id' => $warehouses[1], 'parent_id' => '']);
    expectRelation($status === 200 && str_contains($body, 'está relacionada con contactos'), 'Se rompió una ubicación usada por un vendedor.');
    $sellerPost['warehouse_id'] = $warehouses[1]; unset($sellerPost['location_id']);
    [$status] = relationRequest($base, 'contactos/index.php?id=' . $contacts['seller'], $cookie, $sellerPost); expectRelation($status === 303, 'No se guardó un almacén sin ubicación.');
    $lookup->execute([$sellerPost['name']]); $seller = $lookup->fetch();
    expectRelation((int) $seller['warehouse_id'] === $warehouses[1] && $seller['location_id'] === null, 'No se eliminó la ubicación anterior.');
    [$status, $form] = relationRequest($base, 'contactos/index.php?id=' . $contacts['customer'], $cookie);
    expectRelation($status === 200 && ! str_contains($form, 'data-related-storage'), 'El almacén volvió a aparecer en el cliente.');
    $sellerPost['location_id'] = $location;
    [$status, $body] = relationRequest($base, 'contactos/index.php?id=' . $contacts['seller'], $cookie, $sellerPost);
    expectRelation($status === 200 && str_contains($body, 'Selecciona un almacén o una ubicación'), 'Se asignó una ubicación de otro almacén.');
    $sellerPost['location_id'] = 999999999;
    [$status, $body] = relationRequest($base, 'contactos/index.php?id=' . $contacts['seller'], $cookie, $sellerPost);
    expectRelation($status === 200 && str_contains($body, 'Selecciona un almacén o una ubicación'), 'Se aceptó una ubicación inexistente.');
    echo "PASS: vendedor con selector de almacén, cliente sin campo de almacén, guardado y validación de relaciones.\n";
} finally {
    foreach (['customer', 'seller'] as $key) { if (isset($contacts[$key])) { $db->prepare('DELETE FROM contacts WHERE id = ? AND name LIKE ?')->execute([$contacts[$key], $prefix . '%']); } }
    if ($location) { $db->prepare('DELETE FROM inventory_locations WHERE id = ? AND name LIKE ?')->execute([$location, $prefix . '%']); }
    foreach ($warehouses as $id) { $db->prepare('DELETE FROM inventory_warehouses WHERE id = ? AND name LIKE ?')->execute([$id, $prefix . '%']); }
    if ($cookie !== false && is_file($cookie)) { unlink($cookie); }
}
