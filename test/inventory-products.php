<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/php/connection.php';

$db = createDatabaseConnection();
$base = $argv[1] ?? 'http://localhost/project/modules/inventario/';
$cookie = tempnam(sys_get_temp_dir(), 'product-test-');
$prefix = 'Test producto ' . bin2hex(random_bytes(8));
$id = null;

function productRequest(string $base, string $path, string $cookie, ?array $post = null): array
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

function expectProduct(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    [$status, $form] = productRequest($base, 'index.php?new=1', $cookie);
    expectProduct($status === 200 && ! str_contains($form, 'aria-label="Listado de productos"') && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'El formulario no está separado.');
    $token = $matches[1];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $form);
    $xpath = new DOMXPath($dom);
    $tabs = [];
    foreach ($xpath->query('//*[@data-notebook-tabs]/a') as $tab) {
        $tabs[] = trim($tab->textContent);
    }
    expectProduct($tabs === ['Información general', 'Variantes', 'Ventas', 'Compras', 'Inventario', 'Contabilidad'], 'El orden de las pestañas no es correcto.');
    $pages = $xpath->query('//*[@data-notebook-page]');
    expectProduct($pages->length === 6, 'Faltan páginas del notebook.');
    foreach ($pages as $page) {
        if (! in_array($page->getAttribute('id'), ['product-page-general', 'product-page-inventory'], true)) {
            expectProduct(trim($page->textContent) === '' && $page->childNodes->length === 0, 'Una pestaña pendiente no está vacía.');
        }
    }
    expectProduct($xpath->query('//*[@id="product-page-inventory"]//table')->length === 1 && $xpath->query('//*[@data-product-stock-open and not(@disabled)]')->length === 1, 'No se muestra el detalle de existencias ni se habilita Cantidad a la mano.');
    expectProduct($xpath->query('//*[@id="product-page-general"]//input[@name="sale_price"]')->length === 1
        && $xpath->query('//*[@id="product-page-general"]//select[@name="purchase_tax_id"]')->length === 1
        && $xpath->query('//*[@id="product-page-general"]//select[@name="category_id"]')->length === 1, 'Faltan campos de información general.');
    $name = $prefix . ' <ejemplo>';
    [$status] = productRequest($base, 'index.php?new=1', $cookie,
        ['csrf' => $token, 'action' => 'save_product', 'name' => $name, 'sale_enabled' => '1']);
    expectProduct($status === 303, 'No se creó el producto.');
    $lookup = $db->prepare('SELECT id FROM inventory_products WHERE name = ?');
    $lookup->execute([$name]);
    $id = (int) $lookup->fetchColumn();
    expectProduct($id > 0, 'El producto no se guardó en MySQL.');
    $lookup = $db->prepare('SELECT name, sale_enabled, purchase_enabled FROM inventory_products WHERE id = ?');
    $lookup->execute([$id]);
    $record = $lookup->fetch();
    expectProduct((int) $record['sale_enabled'] === 1 && (int) $record['purchase_enabled'] === 0, 'Las casillas no se guardan independientemente.');
    foreach ([[0, 1], [1, 1], [0, 0]] as [$sale, $purchase]) {
        $post = ['csrf' => $token, 'action' => 'save_product', 'name' => $prefix . ' editado'];
        if ($sale) $post['sale_enabled'] = '1';
        if ($purchase) $post['purchase_enabled'] = '1';
        [$status] = productRequest($base, 'index.php?id=' . $id, $cookie, $post);
        $lookup->execute([$id]);
        $record = $lookup->fetch();
        expectProduct($status === 303 && (int) $record['sale_enabled'] === $sale && (int) $record['purchase_enabled'] === $purchase, 'No se actualizaron las casillas.');
    }
    [$status, $list] = productRequest($base, 'index.php?q=' . rawurlencode($prefix), $cookie);
    expectProduct($status === 200 && str_contains($list, $record['name']) && str_contains($list, '<table') && ! str_contains($list, 'value="save_product"'), 'El listado mezcla el formulario o no encuentra el producto.');
    foreach ([['name' => ''], ['sale_enabled' => ['1']], ['purchase_enabled' => 'true']] as $invalid) {
        [$status, $body] = productRequest($base, 'index.php?id=' . $id, $cookie,
            array_replace(['csrf' => $token, 'action' => 'save_product', 'name' => $record['name']], $invalid));
        expectProduct($status === 200 && str_contains($body, 'notice-error'), 'Se aceptaron datos inválidos.');
    }
    [$status] = productRequest($base, 'index.php?new=1', $cookie, ['action' => 'save_product', 'name' => $prefix]);
    expectProduct($status === 403, 'Se aceptó un formulario sin token.');
    [$status] = productRequest($base, 'index.php?id=999999999', $cookie);
    expectProduct($status === 404, 'Un producto inexistente no devuelve 404.');
    echo "PASS: tree y form separados, creación, edición, casillas independientes, notebook, información general, búsqueda y validación.\n";
} finally {
    if ($id) {
        $db->prepare('DELETE FROM inventory_products WHERE id = ? AND name LIKE ?')->execute([$id, $prefix . '%']);
    }
    if ($cookie !== false && is_file($cookie)) {
        unlink($cookie);
    }
}
