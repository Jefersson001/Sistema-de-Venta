<?php
declare(strict_types=1);
require_once __DIR__.'/http-session.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/connection.php';
require_once dirname(__DIR__) . '/php/invoice-status-repository.php';
$db = createDatabaseConnection();
$prefix = 'Test estados ' . bin2hex(random_bytes(8));
$cookie = tempnam(sys_get_temp_dir(), 'statuses-'); $fixtures = []; $orders = []; $invoiceIds = [];
function assertStatus(bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); }
function statusRequest(string $path, string $cookie, ?array $post = null): array {
    $curl = testHttpCurl('http://localhost/project/modules/' . $path, $cookie ?? $cookieJar ?? $cookieFile ?? null);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = curl_exec($curl); $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    if ($body === false) throw new RuntimeException(curl_error($curl)); curl_close($curl);
    assertStatus(! preg_match('/Fatal error|Warning:|Notice:/', $body), 'Error PHP: ' . $path);
    return [$code, $body, $redirect];
}
function statusToken(string $body): string { assertStatus(preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m) === 1, 'Falta CSRF.'); return $m[1]; }
function statusCreated(array $response): int { assertStatus($response[0] === 303 && preg_match('/id=(\d+)/', $response[2], $m) === 1, 'No se creó el documento: ' . $response[1]); return (int) $m[1]; }
try {
    require_once __DIR__.'/treasury-cleanup.php';
    $roles = array_flip($db->query('SELECT id, system_key FROM contact_types WHERE system_key IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
    $db->prepare("INSERT INTO accounting_payment_accounts(name,account_type) VALUES (?,'cash')")->execute([$prefix]);$fixtures['account']=(int)$db->lastInsertId();
    $fixtures['method']=(int)$db->query("SELECT id FROM accounting_payment_methods WHERE system_key='manual'")->fetchColumn();
    foreach (['client', 'supplier'] as $role) {
        $db->prepare('INSERT INTO contacts (name, type_id) VALUES (?, ?)')->execute([$prefix . $role, $roles[$role]]); $fixtures[$role] = (int) $db->lastInsertId();
    }
    $db->prepare("INSERT INTO inventory_products (name, sale_enabled, purchase_enabled, sale_price,product_type) VALUES (?, 1, 1, 100.00,'service')")->execute([$prefix]); $fixtures['product'] = (int) $db->lastInsertId();
    foreach (['sale', 'purchase'] as $kind) {
        $module = $kind === 'sale' ? 'ventas' : 'compras'; $type = $kind === 'sale' ? 'customer' : 'supplier';
        [, $form] = statusRequest($module . '/index.php?new=1', $cookie); $orderToken = statusToken($form);
        $orderPost = ['csrf' => $orderToken, 'action' => $kind === 'sale' ? 'save_quotation' : 'save_purchase', 'order_type' => 'invoice', 'order_date' => commerceToday(),
            $kind === 'sale' ? 'customer_id' : 'supplier_id' => $fixtures[$kind === 'sale' ? 'client' : 'supplier'],
            'lines' => [['product_id' => $fixtures['product'], 'quantity' => '1.000', 'unit_price' => '100.00']]];
        $orderId = statusCreated(statusRequest($module . '/index.php?new=1', $cookie, $orderPost)); $orders[] = [$kind, $orderId];
        statusCreated(statusRequest($module . '/index.php?id=' . $orderId, $cookie, ['csrf' => $orderToken, 'version' => '1', 'action' => $kind === 'sale' ? 'confirm_sale' : 'confirm_purchase']));
        $id = statusCreated(statusRequest($module . '/index.php?id=' . $orderId, $cookie, ['csrf' => $orderToken, 'action' => 'create_invoice'])); $invoiceIds[] = $id;
        $path = 'contabilidad/index.php?type=' . $type . '&id=' . $id;
        [, $body] = statusRequest($path, $cookie); $token = statusToken($body);
        $current = function () use ($db, $id): array { $q = $db->prepare('SELECT * FROM accounting_invoices WHERE id = ?'); $q->execute([$id]); return $q->fetch(); };
        $act = function (string $action, array $extra = [], bool $accepted = true) use ($path, $cookie, $token, $current, $fixtures): array {
            if($action==='register_payment')$extra+=['account_id'=>$fixtures['account'],'method_id'=>$fixtures['method']];
            $response = statusRequest($path, $cookie, ['csrf' => $token, 'version' => $current()['version'], 'action' => $action] + $extra);
            assertStatus($response[0] === ($accepted ? 303 : 200), 'Acción inesperada: ' . $action . ' ' . $response[1]); return $response;
        };
        $filter = function (string $state) use ($type, $cookie, $current): void {
            $name = $current()['name']; [, $list] = statusRequest('contabilidad/index.php?type=' . $type . '&status=' . $state . '&q=' . rawurlencode($name), $cookie);
            $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="UTF-8">' . $list);
            assertStatus((new DOMXPath($dom))->query('//table/tbody/tr/td/a')->length > 0 && str_contains($list, $name), 'El filtro no encontró la factura: ' . $state);
        };
        $filter('draft'); $act('register_payment', ['amount' => '1', 'payment_date' => commerceToday()], false);
        $act('confirm_invoice', ['invoice_date' => commerceToday(), 'due_date' => '']);
        assertStatus($current()['status'] === 'posted' && $current()['payment_state'] === 'not_paid', 'Registro debe quedar Sin pagar.'); $filter('not_paid');
        $act('mark_invoice_sent'); assertStatus($current()['sent_at'] !== null, 'No se marcó el envío.'); $filter('sent');
        $act('register_payment', ['amount' => '40.00', 'payment_date' => '2026-02-31'], false);
        $oldVersion = $current()['version'];
        $act('register_payment', ['amount' => '40.00', 'payment_date' => commerceToday(), 'reference' => $prefix]);
        assertStatus($current()['payment_state'] === 'in_payment', 'Pago pendiente no está En proceso.'); $filter('in_payment');
        $first = invoicePaymentDetails($db, $id)['payments'][0]['id'];
        $stale = statusRequest($path, $cookie, ['csrf' => $token, 'version' => $oldVersion, 'action' => 'register_payment', 'amount' => '40', 'payment_date' => commerceToday()]);
        assertStatus($stale[0] === 200 && count(invoicePaymentDetails($db, $id)['payments']) === 1, 'Un reenvío duplicó el pago.');
        $act('settle_payment', ['payment_id' => $first]); $act('settle_payment', ['payment_id' => $first], false);
        assertStatus($current()['payment_state'] === 'partial', 'Pago parcial no actualizó el estado.'); $filter('partial');
        $act('cancel_invoice', [], false);
        $act('register_payment', ['amount' => '0.00', 'payment_date' => commerceToday()], false);
        $act('register_payment', ['amount' => '60.00', 'payment_date' => commerceToday()]);
        assertStatus($current()['payment_state'] === 'in_payment', 'Los pagos cubren todo el importe pero la factura sigue parcialmente pagada.'); $filter('in_payment');
        $second = invoicePaymentDetails($db, $id)['payments'][1]['id']; $act('settle_payment', ['payment_id' => $second]);
        assertStatus($current()['payment_state'] === 'paid' && invoicePaymentDetails($db, $id)['settled'] === '100.00', 'Pago completo no dejó Pagado.'); $filter('paid');
        $act('cancel_payment', ['payment_id' => $first]); $act('cancel_payment', ['payment_id' => $second]);
        assertStatus($current()['payment_state'] === 'not_paid', 'Anular pagos no recalculó el saldo.');
        $act('reverse_invoice', ['reason' => ''], false); $creditId=statusCreated($act('reverse_invoice', ['reason' => $prefix])); $invoiceIds[]=$creditId;
        $creditPath='contabilidad/index.php?type='.$type.'&id='.$creditId;
        statusCreated(statusRequest($creditPath,$cookie,['csrf'=>$token,'action'=>'confirm_invoice','version'=>'1','invoice_date'=>commerceToday()]));
        assertStatus($current()['payment_state'] === 'reversed' && invoicePaymentDetails($db, $id)['credited'] === '100.00', 'Reversión incompleta.'); $filter('reversed');
        $act('reverse_invoice', ['reason' => $prefix], false); $act('cancel_invoice', [], false);
        [, $body] = statusRequest($path, $cookie); $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $body); $xpath = new DOMXPath($dom);
        assertStatus($xpath->query('//form//form')->length === 0 && $xpath->query('//*[@id="invoice-lines"]//*[@id="invoice-payments"]')->length === 1 && $xpath->query('//button[@data-invoice-wizard-open="invoice-payment-wizard"]')->length === 0 && str_contains($body, 'Revertido'), 'Resumen de pagos inválido o una factura revertida permite registrar pagos.');
        if ($kind === 'sale') {
            $blocked = statusRequest('ventas/index.php?id=' . $orderId, $cookie, ['csrf' => $orderToken, 'version' => '2', 'action' => 'cancel_quotation']);
            assertStatus($blocked[0] === 200 && loadSalesQuotation($db, $orderId)['status'] === 'sale', 'Se canceló una venta con factura relacionada.');
        }
        // Another order verifies cancellation without a reversal or active payments.
        $cancelOrder = statusCreated(statusRequest($module . '/index.php?new=1', $cookie, $orderPost)); $orders[] = [$kind, $cancelOrder];
        statusCreated(statusRequest($module . '/index.php?id=' . $cancelOrder, $cookie, ['csrf' => $orderToken, 'version' => '1', 'action' => $kind === 'sale' ? 'confirm_sale' : 'confirm_purchase']));
        $cancelInvoice = statusCreated(statusRequest($module . '/index.php?id=' . $cancelOrder, $cookie, ['csrf' => $orderToken, 'action' => 'create_invoice'])); $invoiceIds[] = $cancelInvoice;
        $cancelPath = 'contabilidad/index.php?type=' . $type . '&id=' . $cancelInvoice;
        $cancelVersion = 1;
        if ($kind === 'purchase') {
            statusCreated(statusRequest($cancelPath, $cookie, ['csrf' => $token, 'version' => '1', 'action' => 'confirm_invoice', 'invoice_date' => commerceToday()]));
            $cancelVersion = 2;
        }
        statusCreated(statusRequest($cancelPath, $cookie, ['csrf' => $token, 'version' => $cancelVersion, 'action' => 'cancel_invoice']));
        $blocked = statusRequest($cancelPath, $cookie, ['csrf' => $token, 'version' => $cancelVersion + 1, 'action' => 'confirm_invoice', 'invoice_date' => commerceToday()]);
        assertStatus($blocked[0] === 200 && str_contains($blocked[1], 'no admite cambios'), 'Se registró una factura cancelada.');
        [, $cancelList] = statusRequest('contabilidad/index.php?type=' . $type . '&status=cancel&q=' . rawurlencode($prefix), $cookie);
        assertStatus(str_contains($cancelList, '&amp;id=' . $cancelInvoice . '"'), 'El filtro Cancelado no muestra la factura.');
    }
    // Empty quotation cancellation also locks the form and prevents confirmation.
    $orderPost['action'] = 'save_quotation'; $orderPost['customer_id'] = $fixtures['client']; $orderPost['lines'] = []; unset($orderPost['supplier_id']);
    [, $body] = statusRequest('ventas/index.php?new=1', $cookie); $orderPost['csrf'] = statusToken($body);
    $cancelQuote = statusCreated(statusRequest('ventas/index.php?new=1', $cookie, $orderPost)); $orders[] = ['sale', $cancelQuote];
    statusCreated(statusRequest('ventas/index.php?id=' . $cancelQuote, $cookie, ['csrf' => $orderPost['csrf'], 'version' => '1', 'action' => 'cancel_quotation']));
    $blocked = statusRequest('ventas/index.php?id=' . $cancelQuote, $cookie, ['csrf' => $orderPost['csrf'], 'version' => '2', 'action' => 'confirm_sale']);
    assertStatus($blocked[0] === 200 && loadSalesQuotation($db, $cancelQuote)['status'] === 'cancel', 'Se confirmó una cotización cancelada.');
    [, $body] = statusRequest('ventas/index.php?id=' . $cancelQuote, $cookie);
    $dom = new DOMDocument(); $dom->loadHTML('<?xml encoding="UTF-8">' . $body); $xpath = new DOMXPath($dom);
    assertStatus($xpath->query('//form[@data-quotation-form]/fieldset[@disabled]')->length === 1
        && $xpath->query('//form[@data-quotation-form]//button[@type="submit"]')->length === 0
        && ! str_contains($body, 'value="confirm_sale"'), 'La cotización cancelada permite editar.');
    $q = $db->prepare('SELECT COUNT(*) FROM inventory_stock_moves WHERE product_id = ?'); $q->execute([$fixtures['product']]);
    assertStatus((int) $q->fetchColumn() === 0, 'Un cambio de factura modificó inventario.');
    echo "PASS: estados de facturas de clientes y proveedores, envío local, pagos parciales/completos, filtros, reversión, cancelación, duplicados y cotizaciones canceladas.\n";
} finally {
    cleanupFixtureTreasury($db,array_values(array_intersect_key($fixtures,array_flip(['client','supplier']))),$prefix);
    rsort($invoiceIds,SORT_NUMERIC); foreach ($invoiceIds as $id) {
        foreach (['accounting_invoice_payments', 'accounting_invoice_reversals', 'accounting_invoice_lines'] as $table) $db->prepare('DELETE FROM ' . $table . ' WHERE invoice_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM accounting_invoices WHERE id = ?')->execute([$id]);
    }
    foreach(['client','supplier'] as $party)if(isset($fixtures[$party]))$db->prepare('DELETE FROM accounting_payments WHERE contact_id=?')->execute([$fixtures[$party]]);
    foreach ($orders as [$kind, $id]) {
        $db->prepare('DELETE FROM ' . ($kind === 'sale' ? 'sales_quotation_lines WHERE quotation_id' : 'purchase_order_lines WHERE order_id') . ' = ?')->execute([$id]);
        $db->prepare('DELETE FROM ' . ($kind === 'sale' ? 'sales_quotations' : 'purchase_orders') . ' WHERE id = ?')->execute([$id]);
    }
    if (isset($fixtures['product'])) $db->prepare('DELETE FROM inventory_products WHERE id = ? AND name = ?')->execute([$fixtures['product'], $prefix]);
    foreach (['client', 'supplier'] as $role) if (isset($fixtures[$role])) $db->prepare('DELETE FROM contacts WHERE id = ? AND name = ?')->execute([$fixtures[$role], $prefix . $role]);
    if (is_file($cookie)) unlink($cookie);
    if(isset($fixtures['account']))$db->prepare('DELETE FROM accounting_payment_accounts WHERE id=? AND name=?')->execute([$fixtures['account'],$prefix]);
}
