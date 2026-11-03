<?php
declare(strict_types=1);

/** Adopt the installed schema without rerunning the legacy installers or their seed data. */
return static function (PDO $db): void {
    migrationAssertApplicationReady($db);
    $existing=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $required=array_merge(companyTables(),[
        'access_groups','access_group_permissions','access_permissions','auth_user_groups',
        'auth_login_limits','contact_addresses','contact_attachments','payment_proofs',
        'inventory_lots','inventory_lot_lines','inventory_lot_stock','inventory_lot_moves',
        'restaurant_areas','restaurant_tables','restaurant_accounts','restaurant_account_carts',
        'restaurant_account_items','restaurant_requests','business_record_actions',
    ]);
    foreach ($required as $table) {
        if (!in_array($table,$existing,true)) throw new RuntimeException('El esquema inicial está incompleto: falta '.$table.'. Completa la instalación antes de actualizar.');
    }
};
