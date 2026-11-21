<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/access-schema.php';
    require_once dirname(__DIR__,2).'/company-schema.php';
    $db->exec("CREATE TABLE IF NOT EXISTS direct_purchase_checkouts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_key CHAR(64) NOT NULL UNIQUE,payload_hash CHAR(64) NOT NULL,
        purchase_id INT UNSIGNED NULL UNIQUE,operation_id INT UNSIGNED NULL UNIQUE,invoice_id INT UNSIGNED NULL UNIQUE,
        FOREIGN KEY(purchase_id) REFERENCES purchase_orders(id),
        FOREIGN KEY(operation_id) REFERENCES inventory_operations(id),
        FOREIGN KEY(invoice_id) REFERENCES accounting_invoices(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    registerCompanyTable($db,'direct_purchase_checkouts');
    syncAccessPermissionCatalog($db);
    $grant=$db->prepare('INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,?)');
    foreach($db->query("SELECT id FROM access_groups WHERE system_key IN ('administrator','purchases')")->fetchAll(PDO::FETCH_COLUMN) as $group){
        foreach(['read','create'] as $action)$grant->execute([$group,'compra_directa.purchases.'.$action,'all']);
    }
};
