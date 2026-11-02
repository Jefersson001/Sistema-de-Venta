<?php
declare(strict_types=1);
require_once __DIR__.'/phase15-schema.php';
require_once __DIR__.'/access-schema.php';

function installInventoryTracking(PDO $db): void
{
    phase15Column($db,'inventory_products','tracking_mode',"ENUM('none','lot','serial') NOT NULL DEFAULT 'none'");
    phase15Column($db,'inventory_products','expiration_enabled','TINYINT UNSIGNED NOT NULL DEFAULT 0');
    foreach(['expiration_days','alert_days','removal_days','best_before_days'] as $field) phase15Column($db,'inventory_products',$field,'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_lots(
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, tracking_mode ENUM('lot','serial') NOT NULL,
        expiration_date DATE NULL, alert_date DATE NULL, removal_date DATE NULL, best_before_date DATE NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        UNIQUE KEY lot_product_name(product_id,name), FOREIGN KEY(product_id) REFERENCES inventory_products(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_lot_lines(
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, line_id INT UNSIGNED NOT NULL, lot_id INT UNSIGNED NOT NULL,
        quantity DECIMAL(11,2) NOT NULL, UNIQUE KEY line_lot(line_id,lot_id),
        FOREIGN KEY(line_id) REFERENCES inventory_operation_lines(id) ON DELETE CASCADE,
        FOREIGN KEY(lot_id) REFERENCES inventory_lots(id), CHECK(quantity>0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_lot_stock(
        lot_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, location_id INT UNSIGNED NOT NULL,
        quantity DECIMAL(17,2) NOT NULL DEFAULT 0,
        PRIMARY KEY(lot_id,location_id), FOREIGN KEY(lot_id) REFERENCES inventory_lots(id),
        FOREIGN KEY(product_id) REFERENCES inventory_products(id), FOREIGN KEY(location_id) REFERENCES inventory_locations(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS inventory_lot_moves(
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, lot_id INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL,
        location_id INT UNSIGNED NOT NULL, operation_id INT UNSIGNED NULL, line_id INT UNSIGNED NULL,
        quantity DECIMAL(17,2) NOT NULL, reason ENUM('initial','movement') NOT NULL,
        FOREIGN KEY(lot_id) REFERENCES inventory_lots(id), FOREIGN KEY(product_id) REFERENCES inventory_products(id),
        FOREIGN KEY(location_id) REFERENCES inventory_locations(id), FOREIGN KEY(operation_id) REFERENCES inventory_operations(id),
        FOREIGN KEY(line_id) REFERENCES inventory_operation_lines(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(['inventory_lots','inventory_lot_lines','inventory_lot_stock','inventory_lot_moves'] as $table) installCompanyTable($db,$table);
    $tables=$db->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);
    foreach(['inventory_lots','inventory_lot_lines','inventory_lot_stock','inventory_lot_moves'] as $table) installCompanyTriggers($db,$table,$tables);
    $new=[];
    foreach(['read','create','edit'] as $action){$code='inventario.lots.'.$action;$q=$db->prepare('SELECT 1 FROM access_permissions WHERE code=?');$q->execute([$code]);if(!$q->fetchColumn())$new[]=$code;}
    syncAccessPermissionCatalog($db);
    foreach($new as $code)$db->prepare("INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope) SELECT id,?,'all' FROM access_groups WHERE system_key='administrator'")->execute([$code]);
}
