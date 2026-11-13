<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/access-schema.php';
    require_once dirname(__DIR__,2).'/workflow-schema.php';
    workflowColumn($db,'direct_stock_requests','request_kind',"ENUM('replenishment','return') NOT NULL DEFAULT 'replenishment'");
    workflowColumn($db,'direct_stock_requests','seller_location_id','INT UNSIGNED NULL REFERENCES inventory_locations(id)');
    workflowColumn($db,'direct_stock_requests','fulfilled_by','INT UNSIGNED NULL REFERENCES auth_users(id)');
    workflowColumn($db,'direct_stock_requests','fulfilled_at','DATETIME NULL');
    workflowColumn($db,'direct_sale_checkouts','credit_warning_json','TEXT NULL');
    $db->exec("ALTER TABLE direct_stock_requests MODIFY status ENUM('pending','approved','transferred','rejected','cancelled') NOT NULL DEFAULT 'pending'");
    $previousCompany=$db->query('SELECT @nexo_company_id')->fetchColumn();
    try{
        foreach($db->query('SELECT DISTINCT company_id FROM direct_stock_requests WHERE seller_location_id IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $company){
            $db->prepare('SET @nexo_company_id=?')->execute([$company]);
            $db->prepare('UPDATE direct_stock_requests SET seller_location_id=destination_location_id WHERE company_id=? AND seller_location_id IS NULL')->execute([$company]);
        }
    }finally{$db->prepare('SET @nexo_company_id=?')->execute([$previousCompany]);}
    $db->exec("CREATE TABLE IF NOT EXISTS direct_customer_credit (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,contact_id INT UNSIGNED NOT NULL UNIQUE,
        credit_limit DECIMAL(16,2) NULL,currency_code CHAR(3) NOT NULL,
        policy ENUM('warn','block') NOT NULL DEFAULT 'warn',version INT UNSIGNED NOT NULL DEFAULT 1,
        FOREIGN KEY(contact_id) REFERENCES contacts(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS direct_sale_actions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sale_id INT UNSIGNED NOT NULL,seller_id INT UNSIGNED NOT NULL,
        action_kind ENUM('payment','return') NOT NULL,request_key CHAR(64) NOT NULL UNIQUE,payload_hash CHAR(64) NOT NULL,
        status ENUM('pending','done','rejected') NOT NULL DEFAULT 'pending',payload_json MEDIUMTEXT NOT NULL,
        payment_id INT UNSIGNED NULL,operation_id INT UNSIGNED NULL,credit_id INT UNSIGNED NULL,
        decision_note VARCHAR(1000) NOT NULL DEFAULT '',decided_by INT UNSIGNED NULL,decided_at DATETIME NULL,
        FOREIGN KEY(sale_id) REFERENCES direct_sale_checkouts(id),FOREIGN KEY(seller_id) REFERENCES contacts(id),
        FOREIGN KEY(payment_id) REFERENCES accounting_payments(id),FOREIGN KEY(operation_id) REFERENCES inventory_operations(id),
        FOREIGN KEY(credit_id) REFERENCES accounting_invoices(id),FOREIGN KEY(decided_by) REFERENCES auth_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS direct_day_closings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,seller_id INT UNSIGNED NOT NULL,closing_date DATE NOT NULL,
        status ENUM('submitted','validated') NOT NULL DEFAULT 'submitted',snapshot_json MEDIUMTEXT NOT NULL,
        declared_json TEXT NOT NULL,notes VARCHAR(1000) NOT NULL DEFAULT '',validated_by INT UNSIGNED NULL,validated_at DATETIME NULL,
        UNIQUE KEY seller_day(seller_id,closing_date),FOREIGN KEY(seller_id) REFERENCES contacts(id),FOREIGN KEY(validated_by) REFERENCES auth_users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(['direct_customer_credit','direct_sale_actions','direct_day_closings'] as $table)registerCompanyTable($db,$table);
    // Refresh the existing request triggers after adding its company-owned relations.
    registerCompanyTable($db,'direct_stock_requests');
    syncAccessPermissionCatalog($db);
    $db->exec("DELETE p FROM access_group_permissions p JOIN access_groups g ON g.id=p.group_id WHERE g.system_key='administrator' AND p.permission_code IN ('venta_directa.sales.register_payment','venta_directa.returns.create','venta_directa.closing.create')");
    $grant=$db->prepare('INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,?)');
    $new=['venta_directa.sales.register_payment','venta_directa.requests.validate','venta_directa.returns.read','venta_directa.returns.create','venta_directa.returns.confirm','venta_directa.returns.cancel','venta_directa.closing.read','venta_directa.closing.create','venta_directa.closing.confirm','venta_directa.configuration.read','venta_directa.configuration.configure'];
    $groups=accessInitialGroups();
    foreach($db->query("SELECT id,system_key FROM access_groups WHERE system_key IN ('administrator','direct_sales','direct_sales_manager')")->fetchAll() as $group){
        foreach($groups[$group['system_key']]['permissions'] as $code=>$scope)if(in_array($code,$new,true))$grant->execute([$group['id'],$code,$scope]);
    }
};
