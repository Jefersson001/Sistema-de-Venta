<?php
declare(strict_types=1);
require_once __DIR__ . '/workflow-repository.php';
require_once __DIR__.'/currency-repository.php';
require_once __DIR__ . '/storage-repository.php';

function salesEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function salesStatuses(): array
{
    return ['draft' => 'Cotización', 'sent' => 'Cotización enviada', 'sale' => 'Orden de venta', 'cancel' => 'Cancelado'];
}

function salesOrderTypes(): array
{
    return ['delivery' => 'Orden de venta', 'invoice' => 'Factura'];
}

function salesId(mixed $value): ?int
{
    if (! is_scalar($value)) { return null; }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function salesRecord(array $records, ?int $id): ?array
{
    foreach ($records as $record) { if ((int) $record['id'] === $id) { return $record; } }
    return null;
}

function salesAddress(array $customer): string
{
    return implode(', ', array_filter(array_map(static fn ($key) => trim((string) ($customer[$key] ?? '')),
        ['address', 'city_name', 'state_name', 'postal_code', 'country_name']), static fn ($value) => $value !== ''));
}

function loadSalesQuotation(PDO $db, int $id): ?array
{
    $statement = $db->prepare('SELECT * FROM sales_quotations WHERE id = ?');
    $statement->execute([$id]); $record = $statement->fetch();
    if (! $record) { return null; }
    $lines = $db->prepare('SELECT * FROM sales_quotation_lines WHERE quotation_id = ? ORDER BY position, id');
    $lines->execute([$id]); $record['lines'] = $lines->fetchAll();
    return $record;
}

function loadSalesCatalogs(PDO $db, ?array $existing): array
{
    $customers = $db->prepare("SELECT c.*, country.name AS country_name, state.name AS state_name, city.name AS city_name
        FROM contacts c JOIN contact_types t ON t.id = c.type_id
        LEFT JOIN contact_countries country ON country.id = c.country_id
        LEFT JOIN contact_states state ON state.id = c.state_id LEFT JOIN contact_cities city ON city.id = c.city_id
        WHERE (t.system_key = 'client' AND c.active = 1) OR c.id = :current ORDER BY c.name, c.id");
    $customers->execute(['current' => $existing['customer_id'] ?? null]);
    $customerRecords = $customers->fetchAll();
    $sellers = $db->prepare("SELECT c.id, c.name, c.active, c.warehouse_id, c.location_id FROM contacts c JOIN contact_types t ON t.id = c.type_id
        WHERE (t.system_key = 'salesperson' AND (c.active = 1 OR c.id IN (
            SELECT assigned.salesperson_id FROM contacts assigned JOIN contact_types assigned_type ON assigned_type.id = assigned.type_id
            WHERE assigned_type.system_key = 'client' AND assigned.salesperson_id IS NOT NULL
        ))) OR c.id = :current ORDER BY c.name, c.id");
    $sellers->execute(['current' => $existing['salesperson_id'] ?? null]);
    $sellerRecords = $sellers->fetchAll();
    $storage = loadStorageRelations($db);
    foreach ($sellerRecords as &$seller) {
        $seller['storage_label'] = storageLabel($storage, salesId($seller['warehouse_id']), salesId($seller['location_id']));
    }
    unset($seller);
    $assignedProducts = array_map(static fn ($line) => (int) $line['product_id'], $existing['lines'] ?? []);
    $products = $db->query('SELECT id, name, sale_price, sale_tax_id, sale_enabled, product_type, invoice_policy, cost_price, currency_code FROM inventory_products ORDER BY name, id')->fetchAll();
    $products = array_values(array_filter($products, static fn ($product) => $product['sale_enabled'] || in_array((int) $product['id'], $assignedProducts, true)));
    $taxes = $db->query("SELECT id, name, rate FROM business_taxes WHERE module_key = 'ventas' ORDER BY name, id")->fetchAll();
    $terms = $db->query('SELECT id, name FROM accounting_payment_terms ORDER BY name, id')->fetchAll();
    return ['settings'=>$existing?['primary_code'=>$existing['currency_primary_code'],'alternate_code'=>$existing['currency_alternate_code'],'exchange_rate'=>$existing['exchange_rate']]:accountingCurrencies($db),'customers' => $customerRecords, 'salespeople' => $sellerRecords,
        'products' => $products, 'taxes' => $taxes, 'terms' => $terms];
}

function salesDecimal(mixed $value, int $integerDigits, int $scale): ?string
{
    if (! is_string($value)) { return null; }
    $value = str_replace(',', '.', trim($value));
    if (! preg_match('/^[0-9]{1,' . $integerDigits . '}(\.[0-9]{1,' . $scale . '})?$/', $value)) { return null; }
    $parts = explode('.', $value);
    return (ltrim($parts[0], '0') ?: '0') . '.' . str_pad($parts[1] ?? '', $scale, '0');
}

function validateSalesQuotation(array $input, array $catalogs, ?array $existing = null): array
{
    $errors = [];
    $orderType = $input['order_type'] ?? ($existing['order_type'] ?? 'delivery');
    if (! is_string($orderType) || ! array_key_exists($orderType, salesOrderTypes())) {
        $errors[] = 'Selecciona un tipo de orden válido.';
        $orderType = 'delivery';
    }
    $customerId = salesId($input['customer_id'] ?? null);
    $customer = salesRecord($catalogs['customers'], $customerId);
    if (! $customer) { $errors[] = 'Selecciona un cliente válido.'; }
    $date = is_string($input['order_date'] ?? null) ? $input['order_date'] : '';
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (! $parsedDate || $parsedDate->format('Y-m-d') !== $date || $date < '1000-01-01' || $date > '9999-12-31') {
        $errors[] = 'Selecciona una fecha de la orden válida.';
    }
    $termId = salesId($input['payment_term_id'] ?? null);
    if (($input['payment_term_id'] ?? '') !== '' && ! salesRecord($catalogs['terms'], $termId)) { $errors[] = 'Selecciona un término de pago válido.'; }
    $sellerInput = $input['salesperson_id'] ?? '';
    $sellerId = salesId($sellerInput === '' ? ($customer['salesperson_id'] ?? null) : $sellerInput);
    if ($sellerId && ! salesRecord($catalogs['salespeople'], $sellerId)) { $errors[] = 'Selecciona un vendedor válido.'; }
    if (($input['salesperson_id'] ?? '') !== '' && ! $sellerId) { $errors[] = 'Selecciona un vendedor válido.'; }
    $sameCustomer = $existing && (int) $existing['customer_id'] === $customerId;
    $seller = salesRecord($catalogs['salespeople'], $sellerId);
    $address = $customer ? salesAddress($customer) : '';
    $settings=$catalogs['settings']??['primary_code'=>'USD','alternate_code'=>'VES','exchange_rate'=>null];
    try{$settings['exchange_rate']=accountingExchangeRate($input['exchange_rate']??$existing['exchange_rate']??$settings['exchange_rate']);}catch(InvalidArgumentException $e){$errors[]=$e->getMessage();}
    $currency=$input['currency_code']??$existing['currency_code']??$settings['primary_code'];
    if(!is_string($currency)||!in_array($currency,array_merge(accountingCurrencyCodes($settings),[$existing['currency_code']??null]),true)){$errors[]='Selecciona una moneda configurada para la orden.';$currency=$settings['primary_code'];}
    $data = ['currency_primary_code'=>$settings['primary_code'],'currency_alternate_code'=>$settings['alternate_code'],'exchange_rate'=>$settings['exchange_rate'],'currency_code'=>$currency,'customer_id' => $customerId, 'order_date' => $date, 'order_type' => $orderType, 'payment_term_id' => $termId,
        'salesperson_id' => $sellerId, 'invoice_address' => $sameCustomer ? $existing['invoice_address'] : $address,
        'delivery_address' => $sameCustomer ? $existing['delivery_address'] : $address,
        'warehouse_id' => salesId($seller['warehouse_id'] ?? null), 'location_id' => salesId($seller['location_id'] ?? null),
        'storage_label' => $seller['storage_label'] ?? ''];
    $rawLines = $input['lines'] ?? [];
    $lines = [];
    $total = '0.00'; $taxTotal = '0.00'; $baseTotal = '0.00';
    if (! is_array($rawLines) || count($rawLines) > 100) { $errors[] = 'La cotización admite hasta 100 líneas.'; $rawLines = []; }
    foreach (array_values($rawLines) as $position => $raw) {
        $number = $position + 1;
        if (! is_array($raw)) { $errors[] = 'Línea ' . $number . ': datos no válidos.'; continue; }
        if (($raw['product_id'] ?? '') === '') { continue; }
        $productId = salesId($raw['product_id'] ?? null);
        $product = salesRecord($catalogs['products'], $productId);
        if (! $product) { $errors[] = 'Línea ' . $number . ': selecciona un producto habilitado para Venta.'; }
        $quantity = salesDecimal($raw['quantity'] ?? '1', 9, 3);
        $price = salesDecimal($raw['unit_price'] ?? ($product['sale_price'] ?? '0'), 10, 2);
        if ($quantity === null || bccomp($quantity, '0', 3) <= 0) { $errors[] = 'Línea ' . $number . ': la cantidad debe ser positiva, con hasta tres decimales.'; }
        if ($price === null) { $errors[] = 'Línea ' . $number . ': el precio unitario debe ser un importe no negativo con hasta dos decimales.'; }
        $taxId = salesId($raw['tax_id'] ?? null);
        $tax = salesRecord($catalogs['taxes'], $taxId);
        if (($raw['tax_id'] ?? '') !== '' && ! $tax) { $errors[] = 'Línea ' . $number . ': selecciona un impuesto de Ventas.'; }
        $oldLine = $existing['lines'][$position] ?? null;
        $sameProduct = $oldLine && (int)$oldLine['product_id'] === $productId;
        $rate = $oldLine && (int)($oldLine['tax_id'] ?? 0) === (int)$taxId ? ($oldLine['tax_rate'] ?? '0') : ($tax['rate'] ?? '0');
        $amounts = commerceAmounts($quantity ?? '0', $price ?? '0', $rate);
        $base = $amounts['base_amount'];
        $total = bcadd($total, $amounts['total_amount'], 2); $baseTotal = bcadd($baseTotal, $base, 2); $taxTotal = bcadd($taxTotal, $amounts['tax_amount'], 2);
        try{$cost=$sameProduct && ($existing['currency_code']??'USD')===$currency ? ($oldLine['unit_cost']??'0') : accountingConvert($product['cost_price']??'0',$product['currency_code']??'USD',$currency,$settings);}catch(InvalidArgumentException $e){$errors[]=$e->getMessage();$cost='0';}
        $lines[] = ['product_id' => $productId, 'product_name' => $product['name'] ?? '',
            'quantity' => $quantity ?? (is_string($raw['quantity'] ?? null) ? $raw['quantity'] : ''),
            'unit_price' => $price ?? (is_string($raw['unit_price'] ?? null) ? $raw['unit_price'] : ''),
            'tax_id' => $taxId, 'tax_name' => $tax['name'] ?? null, 'tax_rate' => $rate,
            'tax_amount' => $amounts['tax_amount'], 'base_amount' => $base, 'total_amount' => $amounts['total_amount'],
            'product_type' => $sameProduct ? ($oldLine['product_type'] ?? 'goods') : ($product['product_type'] ?? 'goods'),
            'invoice_policy' => $orderType === 'invoice' ? 'ordered' : ($sameProduct ? ($oldLine['invoice_policy'] ?? 'delivered') : ($product['invoice_policy'] ?? 'delivered')),
            'unit_cost' => $cost];
    }
    if (bccomp($total, '9999999999999999.99', 2) > 0) { $errors[] = 'El importe de la cotización excede el máximo permitido.'; }
    $data['base_amount'] = $baseTotal; $data['tax_amount'] = $taxTotal;
    $data['total_amount'] = $total;
    return [$data, $lines, $errors];
}

function saveSalesQuotation(PDO $db, array $data, array $lines, ?int $id, ?int $version): int
{
    $db->beginTransaction();
    try {
        $fields = array_keys($data);
        if ($id) {
            $lock = $db->prepare('SELECT status, version FROM sales_quotations WHERE id = ? FOR UPDATE');
            $lock->execute([$id]); $current = $lock->fetch();
            if (! $current || (int) $current['version'] !== $version) { throw new InvalidArgumentException('La cotización cambió en otra ventana. Recarga la página antes de guardar.'); }
            if ($current['status'] === 'sale') { throw new InvalidArgumentException('La orden de venta ya está confirmada y no admite cambios.'); }
            if ($current['status'] === 'cancel') { throw new InvalidArgumentException('La cotización cancelada no admite cambios.'); }
            $sql = 'UPDATE sales_quotations SET ' . implode(', ', array_map(static fn ($field) => $field . ' = :' . $field, $fields)) . ', version = version + 1 WHERE id = :id';
            $db->prepare($sql)->execute($data + ['id' => $id]);
            $db->prepare('DELETE FROM sales_quotation_lines WHERE quotation_id = ?')->execute([$id]);
        } else {
            $sql = 'INSERT INTO sales_quotations (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')';
            $db->prepare($sql)->execute($data);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE sales_quotations SET name = ? WHERE id = ?')->execute(['S' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
        }
        $insert = $db->prepare('INSERT INTO sales_quotation_lines (quotation_id, position, product_id, product_name, quantity, unit_price, tax_id, tax_name, tax_rate, tax_amount, product_type, invoice_policy, unit_cost, base_amount, total_amount)
            VALUES (:quotation_id, :position, :product_id, :product_name, :quantity, :unit_price, :tax_id, :tax_name, :tax_rate, :tax_amount, :product_type, :invoice_policy, :unit_cost, :base_amount, :total_amount)');
        foreach ($lines as $position => $line) { $insert->execute($line + ['quotation_id' => $id, 'position' => $position]); }
        $db->commit(); return $id;
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}

function transitionSalesQuotation(PDO $db, int $id, ?int $version, string $target): void
{
    workflowBegin($db);
    try {
        $statement = $db->prepare('SELECT status, version FROM sales_quotations WHERE id = ? FOR UPDATE');
        $statement->execute([$id]); $current = $statement->fetch();
        if (! $current || (int) $current['version'] !== $version) { throw new InvalidArgumentException('La cotización cambió. Recarga la página antes de continuar.'); }
        if ($target === 'cancel') {
            if (! in_array($current['status'], ['draft', 'sent', 'sale'], true)) throw new InvalidArgumentException('La cotización ya está cancelada.');
            cancelPendingOrderOperations($db, 'sale', $id);
            $db->prepare("UPDATE sales_quotations SET status = 'cancel', version = version + 1 WHERE id = ?")->execute([$id]);
            $db->commit(); return;
        }
        if (! (($target === 'sent' && $current['status'] === 'draft') || ($target === 'sale' && in_array($current['status'], ['draft', 'sent'], true)))) {
            throw new InvalidArgumentException('El cambio de estado no es válido.');
        }
        $count = $db->prepare('SELECT COUNT(*) FROM sales_quotation_lines WHERE quotation_id = ?');
        $count->execute([$id]);
        if ((int) $count->fetchColumn() === 0) { throw new InvalidArgumentException('Agrega al menos una línea antes de cambiar el estado.'); }
        $db->prepare('UPDATE sales_quotations SET status = ?, version = version + 1 WHERE id = ?')->execute([$target, $id]);
        $db->commit();
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}
