<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/php/connection.php';
require_once dirname(__DIR__) . '/php/contacts-repository.php';

function expectContact(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$db = createDatabaseConnection();
$db->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    saveContactCatalog($db, 'countries', ['name' => 'Test país ' . $suffix, 'code' => ''], null);
    $country = (int) $db->lastInsertId();
    saveContactCatalog($db, 'states', ['name' => 'Test estado ' . $suffix, 'country_id' => $country], null);
    $state = (int) $db->lastInsertId();
    saveContactCatalog($db, 'states', ['name' => 'Otro estado ' . $suffix, 'country_id' => $country], null);
    $otherState = (int) $db->lastInsertId();
    saveContactCatalog($db, 'cities', ['name' => 'Test ciudad ' . $suffix, 'state_id' => $state], null);
    $city = (int) $db->lastInsertId();
    $catalogs = loadContactCatalogs($db);
    $type = (int) $catalogs['types'][0]['id'];
    $documentTypes = array_column($catalogs['document_types'], 'id', 'code');
    $documentType = (int) $documentTypes['V'];
    $input = ['name' => 'María <contacto> ' . $suffix, 'entity_kind' => 'person', 'type_id' => $type,
        'email' => 'maria@example.test', 'country_id' => $country, 'state_id' => $state, 'city_id' => $city,
        'document_type_id' => $documentType, 'tax_id' => '12345678'];
    [$data, $errors] = validateContact($input, $catalogs);
    expectContact($errors === [], 'Un contacto válido fue rechazado.');
    $id = saveContact($db, $data, null);
    $statement = $db->prepare('SELECT * FROM contacts WHERE id = ?');
    $statement->execute([$id]);
    $record = $statement->fetch();
    expectContact($record['name'] === $input['name'] && (int) $record['city_id'] === $city, 'No se conservaron los datos del contacto.');
    expectContact((int) $record['document_type_id'] === $documentType && $record['tax_id'] === '12345678', 'No se guardó el tipo de documento.');
    $data['name'] = 'Contacto editado ' . $suffix;
    saveContact($db, $data, $id);
    $statement->execute([$id]);
    expectContact($statement->fetch()['name'] === $data['name'], 'La edición no se guardó.');
    $roles = array_column($catalogs['types'], 'id', 'system_key');
    $salespersonId = saveContact($db, array_replace($data, ['name' => 'Vendedor ' . $suffix, 'type_id' => $roles['salesperson']]), null);
    $salespeople = loadContactSalespeople($db);
    $clientInput = array_replace($input, ['type_id' => $roles['client'], 'salesperson_id' => $salespersonId, 'notes' => 'Notas que deben conservarse']);
    [$clientData, $errors] = validateContact($clientInput, $catalogs, $salespeople);
    expectContact($errors === [], 'Se rechazó un vendedor válido para un cliente.');
    $clientId = saveContact($db, $clientData, null);
    $statement->execute([$clientId]);
    $clientRecord = $statement->fetch();
    expectContact((int) $clientRecord['salesperson_id'] === $salespersonId && $clientRecord['notes'] === $clientInput['notes'], 'No se guardaron el vendedor y las notas.');
    foreach ([$id, 999999999, 'inválido'] as $invalidSalesperson) {
        [, $errors] = validateContact(array_replace($clientInput, ['salesperson_id' => $invalidSalesperson]), $catalogs, $salespeople);
        expectContact($errors !== [], 'Se permitió asignar un contacto que no es vendedor.');
    }
    [, $errors] = validateContact($clientInput, $catalogs, $salespeople, $salespersonId);
    expectContact($errors !== [], 'Se permitió asignar al propio contacto.');
    try {
        saveContact($db, array_replace($data, ['type_id' => $roles['employee']]), $salespersonId);
        throw new RuntimeException('Se permitió cambiar el tipo de un vendedor asignado.');
    } catch (InvalidArgumentException $exception) {
        expectContact(str_contains($exception->getMessage(), 'Reasigna'), 'El bloqueo no explica la relación con clientes.');
    }
    $db->prepare('UPDATE contacts SET active = 0 WHERE id = ?')->execute([$salespersonId]);
    expectContact(! contactCatalogRecord(loadContactSalespeople($db), $salespersonId), 'Un vendedor archivado aparece como nueva opción.');
    expectContact(contactCatalogRecord(loadContactSalespeople($db, $salespersonId), $salespersonId) !== null, 'Se perdió el vendedor archivado ya asignado.');
    [$nonClient, $errors] = validateContact(array_replace($clientInput, ['type_id' => $roles['employee']]), $catalogs, $salespeople);
    expectContact($errors === [] && $nonClient['salesperson_id'] === null, 'Se mantuvo el vendedor al cambiar el cliente a otro tipo.');
    saveContact($db, $nonClient, $clientId);
    $statement->execute([$clientId]);
    expectContact($statement->fetch()['salesperson_id'] === null, 'La asignación no se limpió en MySQL.');
    foreach ([['name' => ''], ['email' => 'correo inválido'], ['type_id' => 999999999],
        ['website' => 'javascript:alert(1)'], ['state_id' => $otherState], ['country_id' => ''],
        ['name' => str_repeat('á', 161)], ['document_type_id' => ''], ['document_type_id' => 999999999],
        ['tax_id' => ''], ['tax_id' => 'E-12345678']] as $invalid) {
        [, $errors] = validateContact(array_replace($input, $invalid), $catalogs);
        expectContact($errors !== [], 'No se rechazaron datos inválidos: ' . json_encode($invalid));
    }
    [, $errors] = validateContact(array_replace($input, ['country_id' => '', 'state_id' => '', 'city_id' => '']), $catalogs);
    expectContact($errors === [], 'La localización opcional fue rechazada.');
    [$normalized, $errors] = validateContact(array_replace($input, ['tax_id' => 'V-12345678']), $catalogs);
    expectContact($errors === [] && $normalized['tax_id'] === '12345678', 'La letra pegada en el documento no se separó.');
    [, $errors] = validateContact(array_replace($input, ['document_type_id' => '', 'tax_id' => '']), $catalogs);
    expectContact($errors === [], 'El documento opcional fue rechazado.');
    [, $errors] = validateContactCatalog('document_types', ['name' => 'Inválido', 'code' => '12'], $catalogs, null);
    expectContact($errors !== [], 'Se aceptó una letra de documento inválida.');
    [, $errors] = validateContactCatalog('document_types', ['name' => 'Renombrado', 'code' => 'X'], $catalogs, $documentType);
    expectContact($errors !== [], 'Se permitió cambiar la letra de un tipo existente.');
    [, $errors] = validateContactCatalog('cities', ['name' => 'Ciudad', 'state_id' => $otherState], $catalogs, $city);
    expectContact($errors !== [], 'Se permitió cambiar el padre de una ciudad relacionada.');
    [, $errors] = validateContactCatalog('countries', ['name' => 'País', 'code' => '123'], $catalogs, null);
    expectContact($errors !== [], 'Se aceptó un código de país inválido.');
    try {
        saveContactCatalog($db, 'states', ['name' => 'Test estado ' . $suffix, 'country_id' => $country], null);
        throw new RuntimeException('Se permitió duplicar un estado dentro del mismo país.');
    } catch (PDOException $exception) {
        expectContact(($exception->errorInfo[1] ?? null) === 1062, 'La restricción de duplicados falló por otro motivo.');
    }
    echo "PASS: guardado, edición, localización jerárquica, validación y duplicados.\n";
} finally {
    $db->rollBack();
}
