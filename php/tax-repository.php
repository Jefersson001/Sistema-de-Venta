<?php

declare(strict_types=1);

function businessTaxId(mixed $value): ?int
{
    if (! is_scalar($value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function loadBusinessTaxes(PDO $db, string $moduleKey): array
{
    $statement = $db->prepare('SELECT id, name FROM business_taxes WHERE module_key = ? ORDER BY name, id');
    $statement->execute([$moduleKey]);
    return $statement->fetchAll();
}

function validateBusinessTax(array $input): array
{
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $errors = [];
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
        $errors[] = 'Escribe un nombre de impuesto de entre 1 y 120 caracteres.';
    }
    return [['name' => $name], $errors];
}

function saveBusinessTax(PDO $db, string $moduleKey, array $data, ?int $id): int
{
    if (! in_array($moduleKey, ['compras', 'ventas'], true)) {
        throw new InvalidArgumentException('Módulo de impuestos no válido.');
    }
    $data['module_key'] = $moduleKey;
    if ($id) {
        $data['id'] = $id;
        $sql = 'UPDATE business_taxes SET name = :name WHERE id = :id AND module_key = :module_key';
    } else {
        $sql = 'INSERT INTO business_taxes (name, module_key) VALUES (:name, :module_key)';
    }
    $db->prepare($sql)->execute($data);
    return $id ?? (int) $db->lastInsertId();
}
