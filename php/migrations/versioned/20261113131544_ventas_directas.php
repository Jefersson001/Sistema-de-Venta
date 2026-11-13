<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/access-schema.php';
    $db->exec("CREATE TABLE IF NOT EXISTS direct_sale_checkouts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,seller_id INT UNSIGNED NOT NULL,
        request_key CHAR(64) NOT NULL UNIQUE,payload_hash CHAR(64) NOT NULL,
        payment_mode ENUM('cash','credit') NOT NULL,sale_id INT UNSIGNED NULL UNIQUE,
        operation_id INT UNSIGNED NULL UNIQUE,invoice_id INT UNSIGNED NULL UNIQUE,
        FOREIGN KEY(seller_id) REFERENCES contacts(id),FOREIGN KEY(sale_id) REFERENCES sales_quotations(id),
        FOREIGN KEY(operation_id) REFERENCES inventory_operations(id),FOREIGN KEY(invoice_id) REFERENCES accounting_invoices(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS direct_stock_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,seller_id INT UNSIGNED NOT NULL,
        destination_location_id INT UNSIGNED NOT NULL,source_location_id INT UNSIGNED NULL,
        status ENUM('pending','transferred','rejected','cancelled') NOT NULL DEFAULT 'pending',version INT UNSIGNED NOT NULL DEFAULT 1,
        request_key CHAR(64) NOT NULL UNIQUE,payload_hash CHAR(64) NOT NULL,notes VARCHAR(1000) NOT NULL DEFAULT '',
        decision_note VARCHAR(1000) NOT NULL DEFAULT '',decided_by INT UNSIGNED NULL,decided_at DATETIME NULL,
        operation_id INT UNSIGNED NULL UNIQUE,
        FOREIGN KEY(seller_id) REFERENCES contacts(id),FOREIGN KEY(destination_location_id) REFERENCES inventory_locations(id),
        FOREIGN KEY(source_location_id) REFERENCES inventory_locations(id),FOREIGN KEY(operation_id) REFERENCES inventory_operations(id),
        FOREIGN KEY(decided_by) REFERENCES auth_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS direct_stock_request_lines (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,request_id INT UNSIGNED NOT NULL,product_id INT UNSIGNED NOT NULL,
        requested_quantity DECIMAL(11,2) NOT NULL,approved_quantity DECIMAL(11,2) NULL,
        UNIQUE KEY request_product(request_id,product_id),
        FOREIGN KEY(request_id) REFERENCES direct_stock_requests(id),FOREIGN KEY(product_id) REFERENCES inventory_products(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(['direct_sale_checkouts','direct_stock_requests','direct_stock_request_lines'] as $table)registerCompanyTable($db,$table);
    syncAccessPermissionCatalog($db);
    $grant=$db->prepare('INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,?)');
    $find=$db->prepare('SELECT id FROM access_groups WHERE system_key=?');
    foreach(['direct_sales','direct_sales_manager'] as $key){
        $find->execute([$key]);if($find->fetchColumn())continue;
        $spec=accessInitialGroups()[$key];
        $db->prepare('INSERT INTO access_groups(name,description,system_key) VALUES(?,?,?)')->execute([$spec['name'].' · '.$key,$spec['description'],$key]);
        $group=(int)$db->lastInsertId();foreach($spec['permissions'] as $permission=>$scope)$grant->execute([$group,$permission,$scope]);
    }
    $find->execute(['administrator']);$admin=$find->fetchColumn();
    if($admin)foreach(accessPermissionCatalog() as $permission=>$definition)if($definition['module']==='venta_directa')$grant->execute([$admin,$permission,'all']);
};
