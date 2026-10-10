<?php
declare(strict_types=1);

function storageRecord(array $records, ?int $id): ?array
{
    foreach ($records as $record) { if ((int) $record['id'] === $id) { return $record; } }
    return null;
}

function loadStorageRelations(PDO $db): array
{
    return ['warehouses' => $db->query('SELECT id, name FROM inventory_warehouses ORDER BY name, id')->fetchAll(),
        'locations' => $db->query('SELECT id, name, warehouse_id, parent_id, location_type, system_key FROM inventory_locations ORDER BY name, id')->fetchAll()];
}

function storageLocationLabel(array $storage, ?int $warehouseId, ?int $locationId): string
{
    $warehouse = storageRecord($storage['warehouses'] ?? [], $warehouseId);
    if (! $warehouse) { return ''; }
    if (! $locationId) { return ''; }
    $location = storageRecord($storage['locations'] ?? [], $locationId);
    if (! $location || (int) $location['warehouse_id'] !== $warehouseId) { return ''; }
    $names = []; $visited = [];
    while ($location && ! isset($visited[$location['id']])) {
        $visited[$location['id']] = true; $names[] = $location['name'];
        $location = storageRecord($storage['locations'], $location['parent_id'] ? (int) $location['parent_id'] : null);
    }
    return implode(' / ', array_reverse($names));
}

function storageLabel(array $storage, ?int $warehouseId, ?int $locationId): string
{
    $record = storageRecord($storage['locations'] ?? [], $locationId);
    if ($record && ($record['location_type'] ?? 'internal') !== 'internal') { return $record['name']; }
    $warehouse = storageRecord($storage['warehouses'] ?? [], $warehouseId);
    if (! $warehouse) { return ''; }
    if (! $locationId) { return $warehouse['name']; }
    $location = storageLocationLabel($storage, $warehouseId, $locationId);
    return $location !== '' ? $location . ' / ' . $warehouse['name'] : '';
}

function storagePartnerLocation(array $storage, string $location_type, ?int $preferred = null): ?array
{
    $record = storageRecord($storage['locations'], $preferred);
    if ($record && ($record['location_type'] ?? '') === $location_type) { return $record; }
    foreach ($storage['locations'] as $location) {
        if (($location['system_key'] ?? '') === 'default_' . $location_type && $location['location_type'] === $location_type) { return $location; }
    }
    return null;
}

function resolveStorageDestination(mixed $value, array $storage): ?array
{
    if (! is_string($value)) { return null; }
    if ($value === '') { return ['warehouse_id' => null, 'location_id' => null]; }
    if (! preg_match('/^(warehouse|location):([1-9][0-9]*)$/', $value, $match)) { return null; }
    $id = filter_var($match[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) { return null; }
    if ($match[1] === 'warehouse') {
        return storageRecord($storage['warehouses'] ?? [], $id) ? ['warehouse_id' => $id, 'location_id' => null] : null;
    }
    $location = storageRecord($storage['locations'] ?? [], $id);
    if (! $location || ! storageRecord($storage['warehouses'] ?? [], (int) $location['warehouse_id'])) { return null; }
    return ['warehouse_id' => (int) $location['warehouse_id'], 'location_id' => $id];
}
