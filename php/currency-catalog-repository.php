<?php
declare(strict_types=1);
require_once __DIR__ . '/currency-repository.php';
require_once __DIR__ . '/workflow-repository.php';

function currencyCatalogId(mixed $value): ?int
{
    if (!is_scalar($value)) return null;
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    return $id === false ? null : $id;
}

function loadCurrencyRecord(PDO $db, int $id): ?array
{
    $q = $db->prepare('SELECT * FROM accounting_currencies WHERE id=?'); $q->execute([$id]); $record = $q->fetch();
    if (!$record) return null;
    $q = $db->prepare('SELECT r.*,b.code AS base_code FROM accounting_currency_rates r JOIN accounting_currencies b ON b.id=r.base_currency_id WHERE r.currency_id=? ORDER BY r.rate_date DESC,r.id DESC');
    $q->execute([$id]); $record['rates'] = $q->fetchAll();
    return $record;
}

function currencyCodeIsUsed(PDO $db, array $record): bool
{
    $settings = $db->query('SELECT primary_code,alternate_code FROM accounting_currency_settings WHERE id=1')->fetch();
    if (in_array($record['code'], $settings, true)) return true;
    $q = $db->prepare('SELECT COUNT(*) FROM accounting_currency_rates WHERE currency_id=? OR base_currency_id=?');
    $q->execute([$record['id'],$record['id']]); if ($q->fetchColumn()) return true;
    $columns = ['inventory_products'=>['currency_code'],'sales_quotations'=>['currency_code','currency_primary_code','currency_alternate_code'],
        'purchase_orders'=>['currency_code','currency_primary_code','currency_alternate_code'],'accounting_invoices'=>['currency_code','currency_primary_code','currency_alternate_code'],
        'accounting_payments'=>['currency_code','primary_code','alternate_code'],'accounting_payment_accounts'=>['currency_code'],
        'accounting_treasury_documents'=>['currency_code','destination_currency_code','primary_code','alternate_code'],'accounting_treasury_entries'=>['currency_code']];
    foreach ($columns as $table=>$fields) {
        if(str_starts_with($table,'accounting_treasury_')){
            $exists=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$exists->execute([$table]);if(!$exists->fetchColumn())continue;
        }
        $q = $db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.implode(' OR ',array_map(static fn($field)=>$field.'=?',$fields))); $q->execute(array_fill(0,count($fields),$record['code']));
        if ($q->fetchColumn()) return true;
    }
    return false;
}

function currencyCatalogText(array $input, string $field, int $maximum, bool $required = false): string
{
    $value = $input[$field] ?? '';
    if (!is_string($value)) throw new InvalidArgumentException('Los campos de la moneda deben contener texto válido.');
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $maximum) throw new InvalidArgumentException('Revisa los campos obligatorios y la longitud de los datos de la moneda.');
    return $value;
}

function saveCurrencyRecord(PDO $db, array $input, ?int $id, ?int $version): int
{
    if (isset($input['active']) && !in_array($input['active'],['0','1'],true)) throw new InvalidArgumentException('Selecciona un estado válido para la moneda.');
    $code = strtoupper(currencyCatalogText($input, 'code', 3, true));
    if (!preg_match('/^[A-Z]{3}$/', $code)) throw new InvalidArgumentException('Moneda debe ser un código de tres letras, como USD o VES.');
    $data = ['code'=>$code,'name'=>currencyCatalogText($input,'name',120,true),
        'active'=>($input['active'] ?? '') === '1' ? 1 : 0,
        'symbol'=>currencyCatalogText($input,'symbol',12,true),
        'unit_label'=>currencyCatalogText($input,'unit_label',80),
        'subunit_label'=>currencyCatalogText($input,'subunit_label',80),
        'symbol_position'=>currencyCatalogText($input,'symbol_position',6,true)];
    if (!in_array($data['symbol_position'], ['before','after'], true)) throw new InvalidArgumentException('Selecciona la posición del símbolo.');
    $rows = $input['rates'] ?? [];
    if (!is_array($rows) || count($rows)>100) throw new InvalidArgumentException('Puedes guardar hasta 100 tasas por formulario.');
    workflowBegin($db);
    try {
        $settings = $db->query('SELECT * FROM accounting_currency_settings WHERE id=1 FOR UPDATE')->fetch();
        if ($id) {
            $q = $db->prepare('SELECT * FROM accounting_currencies WHERE id=? FOR UPDATE'); $q->execute([$id]); $old = $q->fetch();
            if (!$old || (int)$old['version'] !== $version) throw new InvalidArgumentException('La moneda cambió en otra ventana. Recarga antes de guardar.');
            if ($code !== $old['code'] && currencyCodeIsUsed($db, $old)) throw new InvalidArgumentException('Una moneda utilizada conserva su código. Puedes editar su nombre y símbolo.');
            if (!$data['active'] && in_array($old['code'], [$settings['primary_code'],$settings['alternate_code']], true)) throw new InvalidArgumentException('La moneda principal y la alterna deben permanecer activas.');
            $db->prepare('UPDATE accounting_currencies SET code=:code,name=:name,active=:active,symbol=:symbol,unit_label=:unit_label,subunit_label=:subunit_label,symbol_position=:symbol_position,version=version+1 WHERE id=:id')->execute($data+['id'=>$id]);
        } else {
            $db->prepare('INSERT INTO accounting_currencies(code,name,active,symbol,unit_label,subunit_label,symbol_position) VALUES(:code,:name,:active,:symbol,:unit_label,:subunit_label,:symbol_position)')->execute($data);
            $id = (int)$db->lastInsertId();
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Revisa las líneas de tasas.');
            $rateId = currencyCatalogId($row['id'] ?? null);
            if (!$rateId && ($row['rate_date'] ?? '') === '' && ($row['rate'] ?? '') === '') continue;
            $date = $row['rate_date'] ?? '';
            $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (!$parsed || $parsed->format('Y-m-d') !== $date || $date<'1000-01-01' || $date>'9999-12-31') throw new InvalidArgumentException('Selecciona una fecha válida para cada tasa.');
            $rate = accountingExchangeRate($row['rate'] ?? null);
            if (!$rate) throw new InvalidArgumentException('La tasa debe ser positiva.');
            $base = currencyCatalogId($row['base_currency_id'] ?? null);
            $q = $db->prepare('SELECT id FROM accounting_currencies WHERE id=?'); $q->execute([$base]);
            if (!$q->fetchColumn() || $base === $id) throw new InvalidArgumentException('La moneda base de la tasa debe ser otra moneda.');
            $key = $base.':'.$date;
            if (isset($seen[$key])) throw new InvalidArgumentException('Solo puede haber una tasa por fecha y moneda base.');
            $seen[$key] = true;
            if ($rateId) {
                $q = $db->prepare('SELECT id FROM accounting_currency_rates WHERE id=? AND currency_id=?'); $q->execute([$rateId,$id]);
                if (!$q->fetchColumn()) throw new InvalidArgumentException('La tasa no pertenece a esta moneda.');
                $db->prepare('UPDATE accounting_currency_rates SET base_currency_id=?,rate_date=?,rate=? WHERE id=?')->execute([$base,$date,$rate,$rateId]);
            } else $db->prepare('INSERT INTO accounting_currency_rates(currency_id,base_currency_id,rate_date,rate) VALUES(?,?,?,?)')->execute([$id,$base,$date,$rate]);
        }
        $rate = currencyRateForPair($db, $settings['primary_code'], $settings['alternate_code'], commerceToday());
        if ($rate !== $settings['exchange_rate']) $db->prepare('UPDATE accounting_currency_settings SET exchange_rate=?,version=version+1 WHERE id=1')->execute([$rate]);
        $db->commit(); return $id;
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}

function saveBusinessCurrencies(PDO $db, array $input): void
{
    $main = currencyCatalogId($input['primary_id'] ?? null); $alt = currencyCatalogId($input['alternate_id'] ?? null);
    if (!$main || !$alt || $main === $alt) throw new InvalidArgumentException('Selecciona dos monedas activas distintas.');
    workflowBegin($db);
    try {
        $settings = $db->query('SELECT * FROM accounting_currency_settings WHERE id=1 FOR UPDATE')->fetch();
        if ((int)$settings['version'] !== currencyCatalogId($input['version'] ?? null)) throw new InvalidArgumentException('La configuración cambió en otra ventana. Recarga antes de guardar.');
        $q = $db->prepare('SELECT id,code FROM accounting_currencies WHERE id IN(?,?) AND active=1 ORDER BY id FOR UPDATE'); $q->execute([$main,$alt]);
        $currencies = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($currencies)!==2) throw new InvalidArgumentException('Selecciona monedas activas.');
        $rate = currencyRateForPair($db, $currencies[$main], $currencies[$alt], commerceToday());
        $db->prepare('UPDATE accounting_currency_settings SET primary_code=?,alternate_code=?,exchange_rate=?,version=version+1 WHERE id=1')->execute([$currencies[$main],$currencies[$alt],$rate]);
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}
