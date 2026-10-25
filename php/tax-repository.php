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
    $statement = $db->prepare('SELECT id, name, rate FROM business_taxes WHERE module_key = ? ORDER BY name, id');
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
    $rate = is_string($input['rate']??null) ? str_replace(',', '.', trim($input['rate'])) : '0';
    if (!preg_match('/^[0-9]{1,3}(\.[0-9]{1,2})?$/',$rate) || bccomp($rate,'100',2)>0) { $errors[]='El porcentaje debe estar entre 0 y 100, con hasta dos decimales.'; $rate='0'; }
    return [['name' => $name, 'rate' => bcadd($rate,'0',2)], $errors];
}

function saveBusinessTax(PDO $db, string $moduleKey, array $data, ?int $id): int
{
    if (! in_array($moduleKey, ['compras', 'ventas'], true)) {
        throw new InvalidArgumentException('Módulo de impuestos no válido.');
    }
    $data['module_key'] = $moduleKey;
    if ($id) {
        $data['id'] = $id;
        $sql = 'UPDATE business_taxes SET name = :name, rate = :rate WHERE id = :id AND module_key = :module_key';
    } else {
        $sql = 'INSERT INTO business_taxes (name, module_key, rate) VALUES (:name, :module_key, :rate)';
    }
    $db->prepare($sql)->execute($data);
    return $id ?? (int) $db->lastInsertId();
}
