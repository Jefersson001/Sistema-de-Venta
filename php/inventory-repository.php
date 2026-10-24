<?php

declare(strict_types=1);
require_once __DIR__.'/commission-math.php';
require_once __DIR__.'/product-variants-repository.php';

function inventoryConfigurationDefinitions(): array
{
    return [
        'categories' => ['label' => 'Categorías de productos', 'short' => 'Categorías', 'new' => 'Nueva categoría', 'action' => 'save_category'],
        'warehouses' => ['label' => 'Almacenes', 'short' => 'Almacenes', 'new' => 'Nuevo almacén', 'action' => 'save_warehouse'],
        'locations' => ['label' => 'Ubicaciones', 'short' => 'Ubicaciones', 'new' => 'Nueva ubicación', 'action' => 'save_location'],
    ];
}

function loadInventoryWarehouses(PDO $db): array
{
    return $db->query('SELECT id, name FROM inventory_warehouses ORDER BY name, id')->fetchAll();
}

function loadInventoryLocations(PDO $db): array
{
    return $db->query('SELECT id, name, warehouse_id, parent_id, location_type, system_key FROM inventory_locations ORDER BY name, id')->fetchAll();
}

function validateInventoryStorage(string $section, array $input, array $warehouses, array $locations, ?int $id): array
{
    $errors = [];
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) { $errors[] = 'Escribe un nombre de entre 1 y 160 caracteres.'; }
    $data = ['name' => $name];
    if ($section === 'locations') {
        $original = inventoryCategoryRecord($locations, $id);
        $location_type = $input['location_type'] ?? ($original['location_type'] ?? 'internal');
        if (! is_string($location_type) || ! in_array($location_type, ['internal', 'customer', 'supplier'], true)) { $errors[] = 'Selecciona un tipo de ubicación válido.'; $location_type = 'internal'; }
        $data['location_type'] = $location_type;
        $warehouse = $location_type === 'internal' ? inventoryId($input['warehouse_id'] ?? null) : null;
        $parent = inventoryId($input['parent_id'] ?? null);
        $data['warehouse_id'] = $warehouse;
        $data['parent_id'] = $parent;
        if ($location_type === 'internal' && ! inventoryCategoryRecord($warehouses, $warehouse)) { $errors[] = 'Selecciona un almacén válido.'; }
        $parentRecord = inventoryCategoryRecord($locations, $parent);
        if (($input['parent_id'] ?? '') !== '' && ! $parentRecord) { $errors[] = 'Selecciona una ubicación superior válida.'; }
        if ($parentRecord && (inventoryId($parentRecord['warehouse_id']) !== $warehouse || ($parentRecord['location_type'] ?? 'internal') !== $location_type)) { $errors[] = 'La ubicación superior debe pertenecer al mismo almacén y tipo.'; }
        $descendants = $id ? inventoryCategoryDescendants($locations, $id) : [];
        if ($parent && in_array($parent, $descendants, true)) { $errors[] = 'La ubicación superior no puede ser la misma ubicación ni una de sus sububicaciones.'; }
        $original = inventoryCategoryRecord($locations, $id);
        if ($original && ($warehouse !== inventoryId($original['warehouse_id']) || $location_type !== $original['location_type']) && count($descendants) > 1) {
            $errors[] = 'No se puede cambiar el almacén de una ubicación con sububicaciones. Reorganiza primero sus sububicaciones.';
        }
    }
    return [$data, $errors];
}

function saveInventoryStorage(PDO $db, string $section, array $data, ?int $id): int
{
    if ($section === 'locations' && $id) {
        $original = $db->prepare('SELECT warehouse_id, location_type, system_key FROM inventory_locations WHERE id = ?');
        $original->execute([$id]);
        $previous = $original->fetch();
        if (inventoryId($previous['warehouse_id']) !== $data['warehouse_id'] || $previous['location_type'] !== $data['location_type']) {
            if ($previous['system_key']) { throw new InvalidArgumentException('No puedes cambiar el tipo de una ubicación predeterminada.'); }
            $partnerReferences = $db->prepare('SELECT COUNT(*) FROM contacts WHERE partner_location_id = ?'); $partnerReferences->execute([$id]);
            if ((int) $partnerReferences->fetchColumn() > 0) { throw new InvalidArgumentException('Esta ubicación está relacionada con contactos. No puedes cambiar su tipo.'); }
            foreach (['contacts', 'sales_quotations'] as $table) {
                $columns = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ('warehouse_id', 'location_id')");
                $columns->execute([$table]);
                if ((int) $columns->fetchColumn() === 2) {
                    $references = $db->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE location_id = ?');
                    $references->execute([$id]);
                    if ((int) $references->fetchColumn() > 0) {
                        throw new InvalidArgumentException('Esta ubicación está relacionada con contactos u órdenes. No puede cambiar de almacén; crea otra ubicación para conservar esas relaciones.');
                    }
                }
            }
            $tableExists = $db->query("SHOW TABLES LIKE 'inventory_operations'")->fetch();
            if ($tableExists) {
                $references = $db->prepare('SELECT COUNT(*) FROM inventory_operations WHERE source_location_id = ? OR destination_location_id = ?');
                $references->execute([$id, $id]);
                if ((int) $references->fetchColumn() > 0) {
                    throw new InvalidArgumentException('Esta ubicación está relacionada con operaciones. No puede cambiar de almacén.');
                }
            }
        }
    }
    $table = match ($section) { 'warehouses' => 'inventory_warehouses', 'locations' => 'inventory_locations' };
    $fields = $section === 'warehouses' ? ['name'] : ['name', 'warehouse_id', 'parent_id', 'location_type'];
    if ($id) {
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', array_map(static fn ($field) => $field . ' = :' . $field, $fields)) . ' WHERE id = :id';
        $data['id'] = $id;
    } else {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')';
    }
    $db->prepare($sql)->execute($data);
    return $id ?? (int) $db->lastInsertId();
}

function validateInventoryProduct(array $input, array $categories = [], array $purchaseTaxes = [], array $saleTaxes = []): array
{
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $errors = [];
    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
        $errors[] = 'Escribe un nombre de producto de entre 1 y 160 caracteres.';
    }
    $data = ['name' => $name];
    foreach (['sale_enabled', 'purchase_enabled'] as $field) {
        $value = $input[$field] ?? '0';
        if (! in_array($value, ['0', '1'], true)) {
            $errors[] = 'Las opciones Venta y Compra deben ser casillas válidas.';
        }
        $data[$field] = $value === '1' ? 1 : 0;
    }
    $rawPrice = $input['sale_price'] ?? '';
    $price = is_string($rawPrice) ? str_replace(',', '.', trim($rawPrice)) : '';
    if ($price === '' && is_string($rawPrice)) {
        $price = '0';
    }
    $data['sale_price'] = $price;
    if (! preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/', $price)) {
        $errors[] = 'El precio de venta debe ser un importe no negativo, con hasta dos decimales y diez dígitos enteros.';
    } else {
        $parts = explode('.', $price);
        $data['sale_price'] = (ltrim($parts[0], '0') ?: '0') . '.' . str_pad($parts[1] ?? '', 2, '0');
    }
    foreach (['category_id' => $categories, 'purchase_tax_id' => $purchaseTaxes, 'sale_tax_id' => $saleTaxes] as $field => $records) {
        $data[$field] = inventoryId($input[$field] ?? null);
        if (($input[$field] ?? '') !== '' && ! inventoryCategoryRecord($records, $data[$field])) {
            $errors[] = match ($field) {
                'category_id' => 'Selecciona una categoría válida.',
                'purchase_tax_id' => 'Selecciona un impuesto del módulo Compras.',
                'sale_tax_id' => 'Selecciona un impuesto del módulo Ventas.',
            };
        }
    }
    $data['product_type']=$input['product_type']??'goods'; $data['invoice_policy']=$input['invoice_policy']??'delivered';
    if(!in_array($data['product_type'],['goods','service'],true))$errors[]='Selecciona Mercancía o Servicio.';
    if(!in_array($data['invoice_policy'],['ordered','delivered'],true))$errors[]='Selecciona una política de facturación válida.';
    $cost=is_string($input['cost_price']??null)?str_replace(',','.',trim($input['cost_price'])):'0';
    if(!preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/',$cost)) { $errors[]='Indica un costo válido con hasta dos decimales.'; $cost='0'; }
    $data['cost_price']=$cost;
    $currency=$input['currency_code']??'USD';if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/',$currency)){$errors[]='Selecciona una moneda válida.';$currency='USD';}$data['currency_code']=$currency;
    $type=$input['commission_type']??'none';
    if (!is_string($type)||!in_array($type,['none','fixed','percent'],true)) { $errors[]='Selecciona Sin comisión automática, Importe fijo o Porcentaje.';$type='none'; }
    $value=is_string($input['commission_value']??null)?str_replace(',','.',trim($input['commission_value'])):'0';
    if ($type!=='none'&&isset($input['commission_value'])&&!is_string($input['commission_value'])) $errors[]='Indica un valor de comisión válido.';
    if ($type==='none') $value='0';
    if ($type==='fixed') $value=preg_replace('/(\.\d{2})0+$/','$1',$value);
    $valid=$type==='percent' ? preg_match('/^[0-9]{1,4}(\.[0-9]{1,4})?$/',$value)&&bccomp($value,'1000',4)<=0
        : preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/',$value);
    if (!$valid) { $errors[]=$type==='percent'?'El porcentaje debe estar entre 0 y 1000, con hasta cuatro decimales.':'La comisión fija debe ser un importe no negativo con hasta dos decimales.';$value='0'; }
    $data['commission_type']=$type;$data['commission_value']=bcadd($value,'0',4);
    if (!$errors) {
        $commission=productCommissionUnit($data['sale_price'],$type,$value)??'0.00';
        if (bccomp(bcadd($data['sale_price'],$commission,2),'9999999999.99',2)>0) $errors[]='El precio para vendedor supera el máximo permitido.';
    }
    if(array_key_exists('parent_product_id',$input)){
        $data['parent_product_id']=inventoryId($input['parent_product_id']);
        if($input['parent_product_id']!==null&&$input['parent_product_id']!==''&&!$data['parent_product_id'])$errors[]='Selecciona un producto principal válido.';
    }
    return [$data, $errors];
}

function inventoryProductRevision(array $record,array $suppliers): string
{
    $snapshot=[];
    foreach(['id','name','sale_enabled','purchase_enabled','sale_price','category_id','purchase_tax_id','sale_tax_id','product_type','invoice_policy','cost_price','currency_code','commission_type','commission_value','parent_product_id'] as $field)$snapshot[$field]=isset($record[$field])?(string)$record[$field]:null;
    $prices=[];foreach($suppliers as $row)$prices[(int)$row['supplier_id']]=(string)$row['price'];ksort($prices,SORT_NUMERIC);
    return hash_hmac('sha256',json_encode([$snapshot,$prices],JSON_THROW_ON_ERROR),$_SESSION['auth_csrf']??'inventory-cli');
}

function saveInventoryProduct(PDO $db, array $data, ?int $id,?string $expectedRevision=null): int
{
    $data+=['commission_type'=>'none','commission_value'=>'0.0000'];
    $ownTransaction=!$db->inTransaction();if($ownTransaction)$db->beginTransaction();
    try{
    $old=null;
    if($id){$q=$db->prepare('SELECT id,parent_product_id FROM inventory_products WHERE id=?');$q->execute([$id]);$old=$q->fetch();if(!$old)throw new InvalidArgumentException('El producto ya no está disponible.');}
    $data['parent_product_id']??=$old['parent_product_id']??null;
    $parent=$data['parent_product_id'];
    if($id&&$parent!=($old['parent_product_id']??null))throw new InvalidArgumentException('No puedes cambiar el producto principal de un producto ya creado.');
    if($parent){
        if($id===(int)$parent)throw new InvalidArgumentException('Una variante no puede ser su propio producto principal.');
        inventoryProductParent($db,(int)$parent,true);
    }
    if($id){
        $q=$db->prepare('SELECT * FROM inventory_products WHERE id=? FOR UPDATE');$q->execute([$id]);$current=$q->fetch();if(!$current)throw new InvalidArgumentException('El producto ya no está disponible.');
        if($expectedRevision!==null){
            $q=$db->prepare('SELECT supplier_id,price FROM product_supplier_prices WHERE product_id=? ORDER BY supplier_id FOR UPDATE');$q->execute([$id]);
            if(!hash_equals(inventoryProductRevision($current,$q->fetchAll()),$expectedRevision))throw new InvalidArgumentException('Este producto fue modificado desde otra ficha. Tus cambios siguen en pantalla; recarga para revisar la versión actual antes de guardar.',409);
        }
    }
    if ($id && $data['product_type']==='service') {
        $q=$db->prepare('SELECT COUNT(*) FROM inventory_operation_lines WHERE product_id=?');$q->execute([$id]);
        if($q->fetchColumn())throw new InvalidArgumentException('Un producto con movimientos de inventario debe conservar el tipo Mercancía.');
    }
    if ($id) {
        $data['id'] = $id;
        $sql = 'UPDATE inventory_products SET name = :name, sale_enabled = :sale_enabled, purchase_enabled = :purchase_enabled,
            sale_price = :sale_price, category_id = :category_id, purchase_tax_id = :purchase_tax_id, sale_tax_id = :sale_tax_id, product_type=:product_type, invoice_policy=:invoice_policy, cost_price=:cost_price, currency_code=:currency_code, commission_type=:commission_type, commission_value=:commission_value, parent_product_id=:parent_product_id WHERE id = :id';
    } else {
        $sql = 'INSERT INTO inventory_products (name, sale_enabled, purchase_enabled, sale_price, category_id, purchase_tax_id, sale_tax_id, product_type, invoice_policy, cost_price, currency_code,commission_type,commission_value,parent_product_id)
            VALUES (:name, :sale_enabled, :purchase_enabled, :sale_price, :category_id, :purchase_tax_id, :sale_tax_id, :product_type, :invoice_policy, :cost_price, :currency_code,:commission_type,:commission_value,:parent_product_id)';
    }
    $db->prepare($sql)->execute($data);
    $saved=$id??(int)$db->lastInsertId();if($ownTransaction)$db->commit();return $saved;
    }catch(Throwable $e){
        if($ownTransaction&&$db->inTransaction())$db->rollBack();
        if($e instanceof PDOException&&($e->errorInfo[1]??null)===1062&&($data['parent_product_id']??null))throw new InvalidArgumentException('Ya existe una variante con ese nombre dentro del producto principal.');
        throw $e;
    }
}

function inventoryId(mixed $value): ?int
{
    if (! is_scalar($value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function loadInventoryCategories(PDO $db): array
{
    return $db->query('SELECT id, name, parent_id, restaurant_enabled FROM inventory_categories ORDER BY name, id')->fetchAll();
}

function inventoryRestaurantCategoryIds(array $categories): array
{
    $ids=[];
    foreach($categories as $category)if(empty($category['parent_id'])&&!empty($category['restaurant_enabled'])){
        $ids=array_merge($ids,inventoryCategoryDescendants($categories,(int)$category['id']));
    }
    return array_values(array_unique($ids));
}

function inventoryCategoryRecord(array $categories, ?int $id): ?array
{
    foreach ($categories as $category) {
        if ((int) $category['id'] === $id) {
            return $category;
        }
    }
    return null;
}

function inventoryCategoryPath(array $categories, array $category): string
{
    $names = [];
    $visited = [];
    while ($category && ! isset($visited[$category['id']])) {
        $visited[$category['id']] = true;
        $names[] = $category['name'];
        $category = inventoryCategoryRecord($categories, inventoryId($category['parent_id']));
    }
    return implode(' / ', array_reverse($names));
}

function inventoryCategoryDescendants(array $categories, int $id): array
{
    $descendants = [$id];
    for ($position = 0; $position < count($descendants); $position++) {
        foreach ($categories as $category) {
            if ((int) $category['parent_id'] === $descendants[$position] && ! in_array((int) $category['id'], $descendants, true)) {
                $descendants[] = (int) $category['id'];
            }
        }
    }
    return $descendants;
}

function validateInventoryCategory(array $input, array $categories, ?int $id): array
{
    $errors = [];
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $parent = inventoryId($input['parent_id'] ?? null);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
        $errors[] = 'Escribe un nombre de entre 1 y 160 caracteres.';
    }
    if (($input['parent_id'] ?? '') !== '' && ! inventoryCategoryRecord($categories, $parent)) {
        $errors[] = 'Selecciona una categoría superior válida.';
    }
    if ($id && $parent && in_array($parent, inventoryCategoryDescendants($categories, $id), true)) {
        $errors[] = 'La categoría superior no puede ser la misma categoría ni una de sus subcategorías.';
    }
    $enabled=$input['restaurant_enabled']??'0';
    if(!in_array($enabled,['0','1',0,1],true))$errors[]='La opción Es para restaurante no es válida.';
    return [['name' => $name, 'parent_id' => $parent,'restaurant_enabled'=>$parent?0:(int)($enabled==='1'||$enabled===1)], $errors];
}

function saveInventoryCategory(PDO $db, array $data, ?int $id): int
{
    $data['restaurant_enabled']=!empty($data['parent_id'])?0:(int)($data['restaurant_enabled']??0);
    if ($id) {
        $data['id'] = $id;
        $statement = $db->prepare('UPDATE inventory_categories SET name = :name, parent_id = :parent_id, restaurant_enabled = :restaurant_enabled WHERE id = :id');
    } else {
        $statement = $db->prepare('INSERT INTO inventory_categories (name, parent_id, restaurant_enabled) VALUES (:name, :parent_id, :restaurant_enabled)');
    }
    $statement->execute($data);
    return $id ?? (int) $db->lastInsertId();
}
