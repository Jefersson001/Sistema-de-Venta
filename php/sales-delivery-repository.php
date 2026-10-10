<?php
declare(strict_types=1);
require_once __DIR__ . '/storage-repository.php';
require_once __DIR__ . '/workflow-operations.php';

function loadSalesDelivery(PDO $db, int $quotationId): ?array
{
    $query = $db->prepare("SELECT id, name, status FROM inventory_operations WHERE quotation_id = ? AND operation_type = 'delivery'");
    $query->execute([$quotationId]); return $query->fetch() ?: null;
}

function createSalesDelivery(PDO $db, int $quotationId): int
{
    return createLinkedStockOperation($db, 'sale', $quotationId);
}
