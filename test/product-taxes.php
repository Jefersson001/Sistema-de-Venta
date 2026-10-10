<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/php/connection.php';

$db = createDatabaseConnection();
$base = $argv[1] ?? 'http://localhost/project/modules/';
$cookie = tempnam(sys_get_temp_dir(), 'tax-test-');
$prefix = 'Test impuestos ' . bin2hex(random_bytes(8));
$taxes = [];
$productId = null;
$categoryId = null;

function taxTestRequest(string $base, string $path, string $cookie, ?array $post = null): array
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

function expectTaxTest(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    foreach (['compras', 'ventas'] as $module) {
        [$status, $form] = taxTestRequest($base, $module . '/configuracion.php?section=taxes&new=1', $cookie);
        expectTaxTest($status === 200 && ! str_contains($form, '<table')
            && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'Formulario de impuestos inválido.');
        $token = $matches[1];
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($form);
        $xpath = new DOMXPath($dom);
        $visibleFields = $xpath->query('//form[contains(@class,"contact-sheet")]//input[not(@type="hidden")]');
        expectTaxTest($visibleFields->length === 1 && $visibleFields->item(0)->getAttribute('name') === 'name', 'El impuesto tiene campos adicionales al nombre.');
        $post = ['csrf' => $token, 'action' => 'save_tax', 'name' => $prefix];
        [$status] = taxTestRequest($base, $module . '/configuracion.php?section=taxes&new=1', $cookie, $post);
        expectTaxTest($status === 303, 'No se creó el impuesto de ' . $module);
        $lookup = $db->prepare('SELECT id FROM business_taxes WHERE module_key = ? AND name = ?');
        $lookup->execute([$module, $prefix]);
        $taxes[$module] = (int) $lookup->fetchColumn();
        expectTaxTest($taxes[$module] > 0, 'El impuesto no se guardó.');
        [$status, $list] = taxTestRequest($base, $module . '/configuracion.php?section=taxes', $cookie);
        expectTaxTest($status === 200 && str_contains($list, '<table') && ! str_contains($list, 'value="save_tax"'), 'La lista y el formulario no están separados.');
        [$status, $body] = taxTestRequest($base, $module . '/configuracion.php?section=taxes&new=1', $cookie, $post);
        expectTaxTest($status === 200 && str_contains($body, 'Ya existe un impuesto'), 'Se permitió un impuesto duplicado.');
        $post['name'] = $prefix . ' editado';
        [$status] = taxTestRequest($base, $module . '/configuracion.php?section=taxes&id=' . $taxes[$module], $cookie, $post);
        expectTaxTest($status === 303, 'No se editó el impuesto.');
        [$status] = taxTestRequest($base, $module . '/configuracion.php?section=taxes&new=1', $cookie, ['action' => 'save_tax', 'name' => $prefix]);
        expectTaxTest($status === 403, 'Se aceptó un impuesto sin token.');
    }
    [$status] = taxTestRequest($base, 'ventas/configuracion.php?section=taxes&id=' . $taxes['compras'], $cookie);
    expectTaxTest($status === 404, 'Se accede a un impuesto de otro módulo.');
    $db->prepare('INSERT INTO inventory_categories (name) VALUES (?)')->execute([$prefix]);
    $categoryId = (int) $db->lastInsertId();
    [$status, $form] = taxTestRequest($base, 'inventario/index.php?new=1', $cookie);
    expectTaxTest($status === 200 && preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $matches) === 1, 'No se abrió Productos.');
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($form);
    $xpath = new DOMXPath($dom);
    expectTaxTest($xpath->query('//select[@name="purchase_tax_id"]/option[@value="' . $taxes['compras'] . '"]')->length === 1
        && $xpath->query('//select[@name="purchase_tax_id"]/option[@value="' . $taxes['ventas'] . '"]')->length === 0, 'El selector mezcla impuestos de compra y venta.');
    expectTaxTest($xpath->query('//select[@name="sale_tax_id"]/option[@value="' . $taxes['ventas'] . '"]')->length === 1
        && $xpath->query('//select[@name="sale_tax_id"]/option[@value="' . $taxes['compras'] . '"]')->length === 0, 'El selector de venta mezcla impuestos de compra.');
    $post = ['csrf' => $matches[1], 'action' => 'save_product', 'name' => $prefix,
        'sale_price' => '125,50', 'category_id' => $categoryId, 'purchase_tax_id' => $taxes['ventas'], 'sale_tax_id' => $taxes['ventas']];
    [$status, $body] = taxTestRequest($base, 'inventario/index.php?new=1', $cookie, $post);
    expectTaxTest($status === 200 && str_contains($body, 'Selecciona un impuesto del módulo Compras'), 'Se asignó un impuesto de venta como impuesto de compra.');
    $post['purchase_tax_id'] = $taxes['compras'];
    [$status] = taxTestRequest($base, 'inventario/index.php?new=1', $cookie, $post);
    expectTaxTest($status === 303, 'No se guardó la información general.');
    $lookup = $db->prepare('SELECT id, sale_price, category_id, purchase_tax_id, sale_tax_id FROM inventory_products WHERE name = ?');
    $lookup->execute([$prefix]);
    $record = $lookup->fetch();
    $productId = (int) $record['id'];
    expectTaxTest($record['sale_price'] === '125.50' && (int) $record['category_id'] === $categoryId
        && (int) $record['purchase_tax_id'] === $taxes['compras'] && (int) $record['sale_tax_id'] === $taxes['ventas'], 'No se guardaron el precio o las relaciones.');
    [$status, $form] = taxTestRequest($base, 'inventario/index.php?id=' . $productId, $cookie);
    expectTaxTest($status === 200 && str_contains($form, 'value="125.50"'), 'El precio no se conserva al editar.');
    $dom->loadHTML($form);
    $xpath = new DOMXPath($dom);
    expectTaxTest($xpath->query('//select[@name="sale_tax_id"]/option[@selected and @value="' . $taxes['ventas'] . '"]')->length === 1, 'El impuesto de venta no se conserva al editar.');
    foreach ([['sale_price' => '-1'], ['sale_price' => '1.234'], ['sale_price' => '1e3'], ['sale_price' => '10000000000'],
        ['category_id' => 999999999], ['purchase_tax_id' => 999999999], ['sale_tax_id' => 999999999], ['sale_tax_id' => $taxes['compras']]] as $invalid) {
        [$status, $body] = taxTestRequest($base, 'inventario/index.php?id=' . $productId, $cookie, array_replace($post, $invalid));
        expectTaxTest($status === 200 && str_contains($body, 'notice-error'), 'Se aceptó un importe o relación inválidos.');
    }
    $post['sale_price'] = '9999999999.99';
    [$status] = taxTestRequest($base, 'inventario/index.php?id=' . $productId, $cookie, $post);
    $lookup->execute([$prefix]);
    expectTaxTest($status === 303 && $lookup->fetch()['sale_price'] === '9999999999.99', 'El importe perdió precisión decimal.');
    echo "PASS: impuestos con solo nombre, tree/form, separación entre módulos, precio decimal y relaciones de producto.\n";
} finally {
    if ($productId) {
        $db->prepare('DELETE FROM inventory_products WHERE id = ? AND name LIKE ?')->execute([$productId, $prefix . '%']);
    }
    if ($categoryId) {
        $db->prepare('DELETE FROM inventory_categories WHERE id = ? AND name LIKE ?')->execute([$categoryId, $prefix . '%']);
    }
    foreach ($taxes as $module => $id) {
        $db->prepare('DELETE FROM business_taxes WHERE id = ? AND module_key = ? AND name LIKE ?')->execute([$id, $module, $prefix . '%']);
    }
    if ($cookie !== false && is_file($cookie)) {
        unlink($cookie);
    }
}
