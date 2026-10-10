<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory-repository.php';
require_once __DIR__ . '/storage-repository.php';
require_once __DIR__ . '/inventory-stock-repository.php';

function inventoryOperationTypes(): array
{
    return [
        'delivery' => ['label' => 'Entregas', 'new' => 'Nueva entrega', 'prefix' => 'OUT', 'role' => 'client'],
        'receipt' => ['label' => 'Recepciones', 'new' => 'Nueva recepción', 'prefix' => 'IN', 'role' => 'supplier'],
        'internal' => ['label' => 'Interno', 'new' => 'Nuevo traslado interno', 'prefix' => 'INT', 'role' => null],
    ];
}

function validateInventoryOperation(array $input, string $type, array $storage, array $contacts): array
{
    $errors = [];
    $date = is_string($input['scheduled_date'] ?? null) ? $input['scheduled_date'] : '';
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (! $parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01' || $date > '9999-12-31') {
        $errors[] = 'Selecciona una fecha programada válida.';
    }
    $contact = $type === 'internal' ? null : inventoryId($input['contact_id'] ?? null);
    if ($type !== 'internal' && ! storageRecord($contacts, $contact)) { $errors[] = 'Selecciona un contacto válido para esta operación.'; }
    $notes = is_string($input['notes'] ?? null) ? trim($input['notes']) : '';
    if (mb_strlen($notes) > 5000) { $errors[] = 'Las notas admiten hasta 5000 caracteres.'; }
    $data = ['operation_type' => $type, 'scheduled_date' => $date, 'contact_id' => $contact, 'notes' => $notes];
    $partner = storageRecord($contacts, $contact);
    foreach (['source', 'destination'] as $side) {
        $required = $side === 'source' ? $type !== 'receipt' : $type !== 'delivery';
        $location = $required ? inventoryId($input[$side . '_location_id'] ?? null) : null;
        $locationRecord = storageRecord($storage['locations'], $location);
        if (! $required) {
            $locationRecord = storagePartnerLocation($storage, $type === 'receipt' ? 'supplier' : 'customer', inventoryId($partner['partner_location_id'] ?? null));
            $location = $locationRecord ? (int) $locationRecord['id'] : null;
            if (! $locationRecord) { $errors[] = 'Configura una ubicación externa para el contacto.'; }
        }
        $warehouse = $locationRecord ? inventoryId($locationRecord['warehouse_id']) : null;
        if ($required && (! $locationRecord || ($locationRecord['location_type'] ?? 'internal') !== 'internal' || ! storageRecord($storage['warehouses'], $warehouse))) {
            $errors[] = 'Selecciona una ubicación de ' . ($side === 'source' ? 'origen' : 'destino') . ' válida.';
        }
        $data[$side . '_warehouse_id'] = $warehouse;
        $data[$side . '_location_id'] = $location;
    }
    if ($type === 'internal' && $data['source_warehouse_id'] !== null
        && $data['source_warehouse_id'] === $data['destination_warehouse_id']
        && $data['source_location_id'] === $data['destination_location_id']) {
        $errors[] = 'El origen y el destino del traslado deben ser diferentes.';
    }
    return [$data, $errors];
}

function saveInventoryOperation(PDO $db, array $data, ?int $id, ?int $version, array $lines = []): int
{
    $before=null;
    if($id){$query=$db->prepare('SELECT * FROM inventory_operations WHERE id=?');$query->execute([$id]);$before=$query->fetch();if(!$before)throw new InvalidArgumentException('La operación no existe.');}
    workflowBegin($db);
    try {
        $fields = array_keys($data);
        if ($id) {
            workflowLockOrder($db,$before);
            $lock = $db->prepare('SELECT * FROM inventory_operations WHERE id = ? FOR UPDATE');
            $lock->execute([$id]); $current = $lock->fetch();
            if (! $current || (int) $current['version'] !== $version || $current['operation_type'] !== $data['operation_type']) {
                throw new InvalidArgumentException('La operación cambió en otra ventana. Recarga antes de guardar.');
            }
            if (in_array($current['status'], ['done','cancel'], true)) { throw new InvalidArgumentException('La operación realizada no admite cambios.'); }
            $lines = validateLinkedOperationLines($db,$current,$lines);
            if ($current['return_of_id'] && ($data['source_location_id'] !== (int)$current['source_location_id'] || $data['destination_location_id'] !== (int)$current['destination_location_id'])) throw new InvalidArgumentException('La devolución conserva las ubicaciones inversas de la operación original.');
            if ($current['return_of_id'] && $data['contact_id'] !== (int)$current['contact_id']) throw new InvalidArgumentException('La devolución conserva el contacto de la operación original.');
            $db->prepare('DELETE FROM inventory_reservations WHERE operation_id=?')->execute([$id]);
            $db->prepare('UPDATE inventory_operations SET ' . implode(', ', array_map(static fn ($field) => $field . ' = :' . $field, $fields))
                . ", status = CASE WHEN status = 'ready' THEN 'waiting' ELSE status END, version = version + 1 WHERE id = :id")->execute($data + ['id' => $id]);
        } else {
            $db->prepare('INSERT INTO inventory_operations (' . implode(', ', $fields) . ') VALUES (:' . implode(', :', $fields) . ')')->execute($data);
            $id = (int) $db->lastInsertId();
            $name = inventoryOperationTypes()[$data['operation_type']]['prefix'] . '/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
            $db->prepare('UPDATE inventory_operations SET name = ? WHERE id = ?')->execute([$name, $id]);
        }
        if (!empty($current['quotation_id']) || !empty($current['purchase_order_id']) || !empty($current['return_of_id'])) {
            $update=$db->prepare('UPDATE inventory_operation_lines SET quantity=? WHERE id=? AND operation_id=?');
            foreach($lines as $line) $update->execute([$line['quantity'],$line['id'],$id]);
        } else {
            $db->prepare('DELETE FROM inventory_operation_lines WHERE operation_id = ?')->execute([$id]);
            $insert = $db->prepare('INSERT INTO inventory_operation_lines (operation_id, position, product_id, demand, quantity) VALUES (?, ?, ?, ?, ?)');
            foreach ($lines as $position => $line) $insert->execute([$id,$position,$line['product_id'],$line['demand'],$line['quantity']]);
        }
        $db->commit(); return $id;
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}
