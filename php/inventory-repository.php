<?php

declare(strict_types=1);

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
    return $db->query('SELECT id, name, warehouse_id, parent_id FROM inventory_locations ORDER BY name, id')->fetchAll();
}

function validateInventoryStorage(string $section, array $input, array $warehouses, array $locations, ?int $id): array
{
    $errors = [];
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    if ($name === '' || mb_strlen($name, 'UTF-8') > 160) { $errors[] = 'Escribe un nombre de entre 1 y 160 caracteres.'; }
    $data = ['name' => $name];
    if ($section === 'locations') {
        $warehouse = inventoryId($input['warehouse_id'] ?? null);
        $parent = inventoryId($input['parent_id'] ?? null);
        $data['warehouse_id'] = $warehouse;
        $data['parent_id'] = $parent;
        if (! inventoryCategoryRecord($warehouses, $warehouse)) { $errors[] = 'Selecciona un almacén válido.'; }
        $parentRecord = inventoryCategoryRecord($locations, $parent);
        if (($input['parent_id'] ?? '') !== '' && ! $parentRecord) { $errors[] = 'Selecciona una ubicación superior válida.'; }
        if ($parentRecord && (int) $parentRecord['warehouse_id'] !== $warehouse) { $errors[] = 'La ubicación superior debe pertenecer al mismo almacén.'; }
        $descendants = $id ? inventoryCategoryDescendants($locations, $id) : [];
        if ($parent && in_array($parent, $descendants, true)) { $errors[] = 'La ubicación superior no puede ser la misma ubicación ni una de sus sububicaciones.'; }
        $original = inventoryCategoryRecord($locations, $id);
        if ($original && $warehouse !== (int) $original['warehouse_id'] && count($descendants) > 1) {
            $errors[] = 'No se puede cambiar el almacén de una ubicación con sububicaciones. Reorganiza primero sus sububicaciones.';
        }
    }
    return [$data, $errors];
}

function saveInventoryStorage(PDO $db, string $section, array $data, ?int $id): int
{
    if ($section === 'locations' && $id) {
        $original = $db->prepare('SELECT warehouse_id FROM inventory_locations WHERE id = ?');
        $original->execute([$id]);
        if ((int) $original->fetchColumn() !== $data['warehouse_id']) {
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
    $fields = $section === 'warehouses' ? ['name'] : ['name', 'warehouse_id', 'parent_id'];
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
    return [$data, $errors];
}

function saveInventoryProduct(PDO $db, array $data, ?int $id): int
{
    if ($id) {
        $data['id'] = $id;
        $sql = 'UPDATE inventory_products SET name = :name, sale_enabled = :sale_enabled, purchase_enabled = :purchase_enabled,
            sale_price = :sale_price, category_id = :category_id, purchase_tax_id = :purchase_tax_id, sale_tax_id = :sale_tax_id WHERE id = :id';
    } else {
        $sql = 'INSERT INTO inventory_products (name, sale_enabled, purchase_enabled, sale_price, category_id, purchase_tax_id, sale_tax_id)
            VALUES (:name, :sale_enabled, :purchase_enabled, :sale_price, :category_id, :purchase_tax_id, :sale_tax_id)';
    }
    $db->prepare($sql)->execute($data);
    return $id ?? (int) $db->lastInsertId();
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
    return $db->query('SELECT id, name, parent_id FROM inventory_categories ORDER BY name, id')->fetchAll();
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
    return [['name' => $name, 'parent_id' => $parent], $errors];
}

function saveInventoryCategory(PDO $db, array $data, ?int $id): int
{
    if ($id) {
        $data['id'] = $id;
        $statement = $db->prepare('UPDATE inventory_categories SET name = :name, parent_id = :parent_id WHERE id = :id');
    } else {
        $statement = $db->prepare('INSERT INTO inventory_categories (name, parent_id) VALUES (:name, :parent_id)');
    }
    $statement->execute($data);
    return $id ?? (int) $db->lastInsertId();
}
