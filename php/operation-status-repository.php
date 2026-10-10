<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory-operations-repository.php';

function operationStatuses(): array { return ['draft' => 'Borrador', 'waiting' => 'En espera', 'ready' => 'Listo', 'done' => 'Hecho']; }

function transitionInventoryOperation(PDO $db, int $id, ?int $version, string $action): void
{
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM inventory_operations WHERE id = ? FOR UPDATE'); $query->execute([$id]); $operation = $query->fetch();
        if (! $operation || (int) $operation['version'] !== $version) throw new InvalidArgumentException('La operación cambió. Recarga antes de continuar.');
        $lines = loadInventoryOperationLines($db, $id);
        if (! $lines) throw new InvalidArgumentException('Agrega al menos un producto antes de confirmar la operación.');
        if ($action === 'confirm_operation') {
            if ($operation['status'] !== 'draft') throw new InvalidArgumentException('Solo puedes confirmar una operación en borrador.');
            $target = 'waiting';
        } elseif ($action === 'check_ready') {
            if ($operation['status'] !== 'waiting') throw new InvalidArgumentException('Solo puedes comprobar una operación en espera.');
            $location = inventoryId($operation[$operation['operation_type'] === 'receipt' ? 'destination_location_id' : 'source_location_id']);
            $storage = loadStorageRelations($db); $record = storageRecord($storage['locations'], $location);
            if (! $record || $record['location_type'] !== 'internal') throw new InvalidArgumentException('Selecciona una ubicación interna válida antes de continuar.');
            if ($operation['operation_type'] !== 'receipt') {
                $needed = [];
                foreach ($lines as $line) {
                    $qty = bccomp($line['quantity'], '0', 3) > 0 ? $line['quantity'] : $line['demand'];
                    $needed[$line['product_id']] = bcadd($needed[$line['product_id']] ?? '0.000', $qty, 3);
                }
                foreach ($needed as $product => $quantity) {
                    $balance = $db->prepare('SELECT quantity FROM inventory_stock WHERE product_id = ? AND location_id = ?'); $balance->execute([$product, $location]);
                    if (bccomp((string) ($balance->fetchColumn() ?: '0.000'), $quantity, 3) < 0) throw new InvalidArgumentException('No hay cantidad suficiente a la mano. La operación permanece En espera.');
                }
            }
            $target = 'ready';
        } else throw new InvalidArgumentException('La transición solicitada no es válida.');
        $db->prepare('UPDATE inventory_operations SET status = ?, version = version + 1 WHERE id = ?')->execute([$target, $id]);
        $db->commit();
    } catch (Throwable $exception) { $db->rollBack(); throw $exception; }
}
