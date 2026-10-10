<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/php/connection.php';

$db = createDatabaseConnection();
$base = $argv[1] ?? 'http://localhost/project/modules/inventario/';
$cookie = tempnam(sys_get_temp_dir(), 'inventory-test-');
$prefix = 'Test categorías ' . bin2hex(random_bytes(8));
$created = [];

function inventoryTestRequest(string $base, string $path, string $cookie, ?array $post = null): array
{
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        throw new RuntimeException(curl_error($curl));
    }
    curl_close($curl);
    if (preg_match('/Fatal error|Warning:|Notice:/', $body)) {
        throw new RuntimeException('La respuesta contiene un error PHP.');
    }
    return [$status, $body];
}

function expectInventory(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    [$status, $home] = inventoryTestRequest($base, 'configuracion.php', $cookie);
    expectInventory($status === 200 && str_contains($home, 'Productos') && ! str_contains($home, '<table'), 'El menú de configuración no es independiente.');
    [$status, $form] = inventoryTestRequest($base, 'configuracion.php?section=categories&new=1', $cookie);
    expectInventory($status === 200 && ! str_contains($form, '<table') && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'El formulario no es independiente.');
    $token = $matches[1];
    foreach (['<raíz>', 'Hija', 'Nieta'] as $index => $suffix) {
        $name = $prefix . ' ' . $suffix;
        $parent = $index === 0 ? null : $created[$index - 1];
        [$status] = inventoryTestRequest($base, 'configuracion.php?section=categories&new=1', $cookie,
            ['csrf' => $token, 'action' => 'save_category', 'name' => $name, 'parent_id' => $parent ?? '']);
        expectInventory($status === 303, 'No se guardó una categoría.');
        $lookup = $db->prepare('SELECT id FROM inventory_categories WHERE name = ? AND parent_id <=> ?');
        $lookup->execute([$name, $parent]);
        $created[] = (int) $lookup->fetchColumn();
        expectInventory(end($created) > 0, 'La categoría no se guardó en MySQL.');
    }
    [$status, $list] = inventoryTestRequest($base, 'configuracion.php?section=categories&q=' . rawurlencode($prefix), $cookie);
    expectInventory($status === 200 && str_contains($list, '<table') && ! str_contains($list, 'value="save_category"'), 'El listado mezcla el formulario.');
    expectInventory(str_contains($list, '&lt;raíz&gt;') && ! str_contains($list, '<raíz>') && str_contains($list, 'Nieta'), 'La jerarquía o el escape HTML no funcionan.');
    foreach ([$created[0], $created[2], 999999999, 'inválido'] as $parent) {
        [$status, $body] = inventoryTestRequest($base, 'configuracion.php?section=categories&id=' . $created[0], $cookie,
            ['csrf' => $token, 'action' => 'save_category', 'name' => $prefix . ' <raíz>', 'parent_id' => $parent]);
        expectInventory($status === 200 && str_contains($body, 'notice-error'), 'Se aceptó una relación circular o una categoría superior inválida.');
    }
    [$status, $body] = inventoryTestRequest($base, 'configuracion.php?section=categories&new=1', $cookie,
        ['csrf' => $token, 'action' => 'save_category', 'name' => $prefix . ' <raíz>', 'parent_id' => '']);
    expectInventory($status === 200 && str_contains($body, 'Ya existe una categoría'), 'Se permitió duplicar una categoría raíz.');
    [$status] = inventoryTestRequest($base, 'configuracion.php?section=categories&id=' . $created[0], $cookie,
        ['csrf' => $token, 'action' => 'save_category', 'name' => $prefix . ' editada', 'parent_id' => '']);
    expectInventory($status === 303, 'No se pudo editar la categoría.');
    $lookup = $db->prepare('SELECT name FROM inventory_categories WHERE id = ?');
    $lookup->execute([$created[0]]);
    expectInventory($lookup->fetchColumn() === $prefix . ' editada', 'La edición no persistió.');
    [$status] = inventoryTestRequest($base, 'configuracion.php?section=categories&new=1', $cookie,
        ['action' => 'save_category', 'name' => $prefix . ' sin token']);
    expectInventory($status === 403, 'Se aceptó un formulario sin token de sesión.');
    [$status] = inventoryTestRequest($base, 'configuracion.php?section=categories&id=999999999', $cookie);
    expectInventory($status === 404, 'Un registro inexistente no devuelve 404.');
    echo "PASS: menú, listado y formulario separados, creación, edición, jerarquía, duplicados, escape HTML y CSRF.\n";
} finally {
    foreach (array_reverse($created) as $id) {
        $db->prepare('DELETE FROM inventory_categories WHERE id = ? AND name LIKE ?')->execute([$id, $prefix . '%']);
    }
    if ($cookie !== false && is_file($cookie)) {
        unlink($cookie);
    }
}
