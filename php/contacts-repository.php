<?php

declare(strict_types=1);
require_once __DIR__ . '/storage-repository.php';

function contactCatalogDefinitions(): array
{
    return [
        'countries' => ['table' => 'contact_countries', 'label' => 'Países', 'singular' => 'país'],
        'states' => ['table' => 'contact_states', 'label' => 'Estados', 'singular' => 'estado'],
        'cities' => ['table' => 'contact_cities', 'label' => 'Ciudades', 'singular' => 'ciudad'],
        'types' => ['table' => 'contact_types', 'label' => 'Tipos de contacto', 'singular' => 'tipo de contacto'],
        'document_types' => ['table' => 'contact_document_types', 'label' => 'Tipos de documento', 'singular' => 'tipo de documento'],
    ];
}

function loadContactCatalogs(PDO $db): array
{
    $catalogs = [];
    foreach (contactCatalogDefinitions() as $key => $definition) {
        $catalogs[$key] = $db->query('SELECT * FROM ' . $definition['table'] . ' ORDER BY name, id')->fetchAll();
    }
    return $catalogs;
}

function contactId(mixed $value): ?int
{
    if (! is_scalar($value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function contactCatalogRecord(array $records, ?int $id): ?array
{
    foreach ($records as $record) {
        if ((int) $record['id'] === $id) {
            return $record;
        }
    }
    return null;
}

function contactText(array $input, string $key, int $max, array &$errors, string $label): string
{
    $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    if (mb_strlen($value, 'UTF-8') > $max) {
        $errors[] = $label . ': máximo ' . $max . ' caracteres.';
    }
    return $value;
}

function loadContactSalespeople(PDO $db, ?int $currentSalesperson = null, ?int $contactId = null): array
{
    $statement = $db->prepare("SELECT c.id, c.name, c.active, c.warehouse_id, c.location_id FROM contacts c
        JOIN contact_types t ON t.id = c.type_id
        WHERE t.system_key = 'salesperson' AND (c.active = 1 OR c.id = :assigned)
        AND (:contact_id IS NULL OR c.id <> :exclude_id) ORDER BY c.name, c.id");
    $statement->execute(['assigned' => $currentSalesperson, 'contact_id' => $contactId, 'exclude_id' => $contactId]);
    return $statement->fetchAll();
}

function validateContact(array $input, array $catalogs, array $salespeople = [], ?int $currentId = null, array $storage = []): array
{
    $errors = [];
    $data = [];
    foreach ([
        'name' => [160, 'Nombre'], 'tax_id' => [40, 'Documento'],
        'email' => [160, 'Correo'], 'phone' => [40, 'Teléfono'], 'mobile' => [40, 'Móvil'],
        'website' => [200, 'Sitio web'], 'address' => [255, 'Dirección'],
        'postal_code' => [20, 'Código postal'], 'notes' => [5000, 'Notas'],
    ] as $field => [$length, $label]) {
        $data[$field] = contactText($input, $field, $length, $errors, $label);
    }
    if ($data['name'] === '') {
        $errors[] = 'Escribe el nombre del contacto.';
    }
    $data['entity_kind'] = is_string($input['entity_kind'] ?? null) ? $input['entity_kind'] : '';
    if (! in_array($data['entity_kind'], ['person', 'company'], true)) {
        $errors[] = 'Selecciona persona o empresa.';
    }
    $data['type_id'] = contactId($input['type_id'] ?? null);
    $contactType = contactCatalogRecord($catalogs['types'], $data['type_id']);
    if (! $contactType) {
        $errors[] = 'Selecciona un tipo de contacto válido.';
    }
    $data['salesperson_id'] = null;
    $data['warehouse_id'] = null;
    $data['location_id'] = null;
    if (($contactType['system_key'] ?? null) === 'salesperson') {
        if (array_key_exists('warehouse_id', $input) || array_key_exists('location_id', $input)) {
            $warehouseId = contactId($input['warehouse_id'] ?? null);
            $locationId = contactId($input['location_id'] ?? null);
            $warehouse = storageRecord($storage['warehouses'] ?? [], $warehouseId);
            $location = storageRecord($storage['locations'] ?? [], $locationId);
            $invalidWarehouse = ($input['warehouse_id'] ?? '') !== '' && ! $warehouse;
            $invalidLocation = ($input['location_id'] ?? '') !== '' && (! $location || (int) $location['warehouse_id'] !== $warehouseId);
            $destination = $invalidWarehouse || $invalidLocation ? null : ['warehouse_id' => $warehouseId, 'location_id' => $locationId];
        } else {
            $destination = resolveStorageDestination($input['storage_destination'] ?? '', $storage);
        }
        if ($destination === null) { $errors[] = 'Selecciona un almacén o una ubicación válidos.'; }
        else { $data['warehouse_id'] = $destination['warehouse_id']; $data['location_id'] = $destination['location_id']; }
    }
    if (($contactType['system_key'] ?? null) === 'client') {
        $data['salesperson_id'] = contactId($input['salesperson_id'] ?? null);
        if (($input['salesperson_id'] ?? '') !== '' && (! contactCatalogRecord($salespeople, $data['salesperson_id'])
            || $data['salesperson_id'] === $currentId)) {
            $errors[] = 'Selecciona un contacto de tipo Vendedor válido.';
        }
    }
    $data['document_type_id'] = contactId($input['document_type_id'] ?? null);
    $documentType = contactCatalogRecord($catalogs['document_types'], $data['document_type_id']);
    if (($input['document_type_id'] ?? '') !== '' && ! $documentType) {
        $errors[] = 'Selecciona un tipo de documento válido.';
    }
    if ($data['tax_id'] !== '' && ! $documentType) {
        $errors[] = 'Selecciona la letra del tipo de documento.';
    }
    if ($data['tax_id'] === '' && $documentType) {
        $errors[] = 'Escribe el número del documento o deja ambos campos vacíos.';
    }
    if ($documentType && preg_match('/^([A-Za-z])\s*-\s*(.+)$/u', $data['tax_id'], $parts)) {
        if (strtoupper($parts[1]) === $documentType['code']) {
            $data['tax_id'] = trim($parts[2]);
        } else {
            $errors[] = 'La letra del documento no coincide con el tipo seleccionado.';
        }
    }
    if ($data['email'] !== '' && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El correo electrónico no es válido.';
    }
    if ($data['website'] !== '' && (! filter_var($data['website'], FILTER_VALIDATE_URL)
        || ! in_array(strtolower((string) parse_url($data['website'], PHP_URL_SCHEME)), ['http', 'https'], true))) {
        $errors[] = 'El sitio web debe ser una dirección válida que comience con http:// o https://.';
    }
    foreach (['country_id' => 'countries', 'state_id' => 'states', 'city_id' => 'cities'] as $field => $catalog) {
        $data[$field] = contactId($input[$field] ?? null);
        if (($input[$field] ?? '') !== '' && ! contactCatalogRecord($catalogs[$catalog], $data[$field])) {
            $errors[] = 'La localización seleccionada no es válida.';
        }
    }
    $state = contactCatalogRecord($catalogs['states'], $data['state_id']);
    $city = contactCatalogRecord($catalogs['cities'], $data['city_id']);
    if ($state && (int) $state['country_id'] !== $data['country_id']) {
        $errors[] = 'El estado debe pertenecer al país seleccionado.';
    }
    if ($city && (int) $city['state_id'] !== $data['state_id']) {
        $errors[] = 'La ciudad debe pertenecer al estado seleccionado.';
    }
    return [$data, $errors];
}

function saveContact(PDO $db, array $data, ?int $id): int
{
    if ($id) {
        $role = $db->prepare('SELECT system_key FROM contact_types WHERE id = ?');
        $role->execute([$data['type_id']]);
        if ($role->fetchColumn() !== 'salesperson') {
            $assigned = $db->prepare('SELECT COUNT(*) FROM contacts WHERE salesperson_id = ?');
            $assigned->execute([$id]);
            if ((int) $assigned->fetchColumn() > 0) {
                throw new InvalidArgumentException('Este vendedor está asignado a clientes. Reasigna esos clientes antes de cambiar su tipo.');
            }
        }
    }
    $fields = array_keys($data);
    $values = array_map(static fn ($value) => $value === '' ? null : $value, $data);
    if ($id) {
        $values['id'] = $id;
        $sql = 'UPDATE contacts SET ' . implode(', ', array_map(static fn ($field) => $field . ' = :' . $field, $fields)) . ' WHERE id = :id';
    } else {
        $sql = 'INSERT INTO contacts (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')';
    }
    $db->prepare($sql)->execute($values);
    return $id ?? (int) $db->lastInsertId();
}

function validateContactCatalog(string $section, array $input, array $catalogs, ?int $id): array
{
    $errors = [];
    $data = ['name' => contactText($input, 'name', $section === 'types' ? 80 : 120, $errors, 'Nombre')];
    if ($data['name'] === '') {
        $errors[] = 'Escribe un nombre.';
    }
    if ($section === 'countries') {
        $data['code'] = strtoupper(contactText($input, 'code', 3, $errors, 'Código'));
        if ($data['code'] !== '' && ! preg_match('/^[A-Z]{2,3}$/', $data['code'])) {
            $errors[] = 'El código del país debe tener dos o tres letras.';
        }
    }
    if ($section === 'document_types') {
        $data['code'] = strtoupper(contactText($input, 'code', 1, $errors, 'Letra'));
        if (! preg_match('/^[A-Z]$/', $data['code'])) {
            $errors[] = 'El tipo de documento debe tener una letra de A a Z.';
        }
        $original = contactCatalogRecord($catalogs[$section], $id);
        if ($original && $data['code'] !== $original['code']) {
            $errors[] = 'La letra de un tipo existente no puede cambiarse. Crea otro tipo para utilizar una letra diferente.';
        }
    }
    foreach (['states' => ['country_id', 'countries'], 'cities' => ['state_id', 'states']] as $key => [$field, $parent]) {
        if ($section === $key) {
            $data[$field] = contactId($input[$field] ?? null);
            if (! contactCatalogRecord($catalogs[$parent], $data[$field])) {
                $errors[] = 'Selecciona una localización superior válida.';
            }
            $original = contactCatalogRecord($catalogs[$section], $id);
            if ($original && $data[$field] !== (int) $original[$field]) {
                $errors[] = 'No se puede cambiar la localización superior de un registro existente. Crea uno nuevo para conservar las relaciones.';
            }
        }
    }
    return [$data, $errors];
}

function saveContactCatalog(PDO $db, string $section, array $data, ?int $id): void
{
    $table = contactCatalogDefinitions()[$section]['table'];
    $fields = array_keys($data);
    $values = array_map(static fn ($value) => $value === '' ? null : $value, $data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')';
    if ($id) {
        $values['id'] = $id;
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', array_map(static fn ($field) => $field . ' = :' . $field, $fields)) . ' WHERE id = :id';
    }
    $db->prepare($sql)->execute($values);
}
