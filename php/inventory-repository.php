<?php

declare(strict_types=1);

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
