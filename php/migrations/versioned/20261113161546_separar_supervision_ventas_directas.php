<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/access-schema.php';
    syncAccessPermissionCatalog($db);
    $db->exec("DELETE p FROM access_group_permissions p JOIN access_groups g ON g.id=p.group_id
        WHERE g.system_key='administrator' AND p.permission_code IN ('venta_directa.sales.create','venta_directa.requests.create')");
    $db->exec("INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope)
        SELECT id,'venta_directa.stock.read','all' FROM access_groups WHERE system_key IN ('administrator','direct_sales_manager')");
};
