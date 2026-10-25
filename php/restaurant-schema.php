<?php
declare(strict_types=1);
require_once __DIR__.'/company-schema.php';
require_once __DIR__.'/access-schema.php';
require_once __DIR__.'/inventory-restaurant-schema.php';
require_once __DIR__.'/receipt-snapshot.php';

function restaurantTables(): array
{
    return ['restaurant_areas','restaurant_tables','restaurant_accounts','restaurant_table_assignments',
        'restaurant_active_tables','restaurant_audit_events','restaurant_requests','restaurant_account_carts','restaurant_account_items'];
}

function installRestaurant(PDO $db,?callable $progress=null): void
{
    if (!$db->query("SHOW TABLES LIKE 'company_schema_tables'")->fetch()) {
        throw new RuntimeException('Instala primero la base multiempresa.');
    }
    installInventoryRestaurantCategories($db);
    $definitions = [
        'restaurant_areas' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0, active TINYINT UNSIGNED NOT NULL DEFAULT 1,
            version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY restaurant_area_name(name)",
        'restaurant_tables' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, area_id INT UNSIGNED NOT NULL,
            name VARCHAR(60) NOT NULL, capacity SMALLINT UNSIGNED NOT NULL DEFAULT 2,
            location_label VARCHAR(120) NOT NULL DEFAULT '', position_x TINYINT UNSIGNED NOT NULL DEFAULT 1,
            position_y TINYINT UNSIGNED NOT NULL DEFAULT 1, active TINYINT UNSIGNED NOT NULL DEFAULT 1,
            service_state ENUM('free','reserved','cleaning') NOT NULL DEFAULT 'free',
            version INT UNSIGNED NOT NULL DEFAULT 1, UNIQUE KEY restaurant_table_name(area_id,name),
            FOREIGN KEY(area_id) REFERENCES restaurant_areas(id), CHECK(capacity BETWEEN 1 AND 100),
            CHECK(position_x BETWEEN 1 AND 6), CHECK(position_y BETWEEN 1 AND 20)",
        'restaurant_accounts' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) NULL,
            waiter_id INT UNSIGNED NOT NULL, status ENUM('open','billing','closed','cancel') NOT NULL DEFAULT 'open',
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL,
            guests SMALLINT UNSIGNED NOT NULL DEFAULT 1, notes VARCHAR(500) NOT NULL DEFAULT '',
            version INT UNSIGNED NOT NULL DEFAULT 1, INDEX restaurant_account_state(status,opened_at),
            INDEX restaurant_waiter_state(waiter_id,status), FOREIGN KEY(waiter_id) REFERENCES auth_users(id),
            CHECK(guests BETWEEN 1 AND 100)",
        'restaurant_table_assignments' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL, table_id INT UNSIGNED NOT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, released_at DATETIME NULL,
            release_reason VARCHAR(255) NULL, INDEX restaurant_account_tables(account_id,released_at),
            FOREIGN KEY(account_id) REFERENCES restaurant_accounts(id), FOREIGN KEY(table_id) REFERENCES restaurant_tables(id)",
        'restaurant_active_tables' => "table_id INT UNSIGNED PRIMARY KEY, account_id INT UNSIGNED NOT NULL,
            assignment_id INT UNSIGNED NOT NULL, UNIQUE KEY restaurant_assignment_once(assignment_id),
            INDEX restaurant_active_account(account_id), FOREIGN KEY(table_id) REFERENCES restaurant_tables(id),
            FOREIGN KEY(account_id) REFERENCES restaurant_accounts(id),
            FOREIGN KEY(assignment_id) REFERENCES restaurant_table_assignments(id)",
        'restaurant_audit_events' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NULL, table_id INT UNSIGNED NULL, area_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL, reason VARCHAR(255) NOT NULL DEFAULT '', details LONGTEXT NOT NULL,
            INDEX restaurant_events_account(account_id,id), FOREIGN KEY(account_id) REFERENCES restaurant_accounts(id),
            FOREIGN KEY(table_id) REFERENCES restaurant_tables(id), FOREIGN KEY(area_id) REFERENCES restaurant_areas(id)",
        'restaurant_account_carts' => "account_id INT UNSIGNED PRIMARY KEY, currency_code CHAR(3) NOT NULL,
            primary_code CHAR(3) NOT NULL, alternate_code CHAR(3) NOT NULL, exchange_rate DECIMAL(14,2) NULL,
            sale_id INT UNSIGNED NULL, invoice_id INT UNSIGNED NULL, receipt_snapshot LONGTEXT NULL, UNIQUE KEY restaurant_cart_sale(sale_id),
            UNIQUE KEY restaurant_cart_invoice(invoice_id), FOREIGN KEY(account_id) REFERENCES restaurant_accounts(id),
            FOREIGN KEY(sale_id) REFERENCES sales_quotations(id), FOREIGN KEY(invoice_id) REFERENCES accounting_invoices(id)",
        'restaurant_account_items' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL, product_name VARCHAR(160) NOT NULL, quantity DECIMAL(11,2) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL, unit_cost DECIMAL(12,2) NOT NULL, tax_id INT UNSIGNED NULL,
            tax_name VARCHAR(120) NULL, tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            product_type ENUM('goods','service') NOT NULL, notes VARCHAR(255) NOT NULL DEFAULT '',
            FOREIGN KEY(account_id) REFERENCES restaurant_accounts(id), FOREIGN KEY(product_id) REFERENCES inventory_products(id),
            FOREIGN KEY(tax_id) REFERENCES business_taxes(id), INDEX restaurant_cart_account(account_id), CHECK(quantity>0)",
        'restaurant_requests' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, operation VARCHAR(40) NOT NULL,
            request_key CHAR(64) NOT NULL, payload_hash CHAR(64) NOT NULL, result_id INT UNSIGNED NOT NULL,
            UNIQUE KEY restaurant_request_once(operation,request_key)"
    ];
    foreach ($definitions as $table => $definition) {
        if($progress)$progress($table);
        $db->exec("CREATE TABLE IF NOT EXISTS $table ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        installCompanyTable($db, $table);
    }
    installReceiptSnapshots($db);
    // Refresh the cross-company triggers once, after every new relationship is registered.
    registerCompanyTable($db,'restaurant_requests');
    // Install only this module's permissions. Never reseed existing groups or business data.
    if($progress)$progress('permissions');
    $insert=$db->prepare('INSERT IGNORE INTO access_permissions(code,module_key,resource_key,action_key,label,allows_own) VALUES(?,?,?,?,?,?)');
    $grant=$db->prepare("INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope)
        SELECT id,?,'all' FROM access_groups WHERE system_key='administrator'");
    $label=$db->prepare('UPDATE access_permissions SET label=? WHERE code=?');
    $db->beginTransaction();
    try {
        foreach (accessPermissionCatalog() as $code=>$permission) {
            if ($permission['module']!=='restaurante') continue;
            $insert->execute([$code,'restaurante',$permission['resource'],$permission['action'],$permission['label'],(int)$permission['allows_own']]);
            if ($insert->rowCount()) $grant->execute([$code]);
            $label->execute([$permission['label'],$code]);
        }
        $db->commit();
    } catch(Throwable $e) { $db->rollBack(); throw $e; }
}

function uninstallEmptyRestaurant(PDO $db): void
{
    foreach (restaurantTables() as $table) {
        if ($db->query("SHOW TABLES LIKE '$table'")->fetch() && (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()>0) {
            throw new RuntimeException('No se puede desinstalar: el restaurante tiene datos. Conserva su historial.');
        }
    }
    // DDL is not transactional. This operation is for a verified empty installation in maintenance mode.
    foreach (array_reverse(restaurantTables()) as $table) {
        $db->exec('DROP TABLE IF EXISTS '.$table);
        $db->prepare('DELETE FROM company_schema_tables WHERE table_name=?')->execute([$table]);
    }
}
