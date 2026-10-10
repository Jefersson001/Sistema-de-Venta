<?php
declare(strict_types=1);

function installExternalLocations(PDO $db): void
{
    if (! $db->query("SHOW COLUMNS FROM inventory_locations LIKE 'location_type'")->fetch()) {
        $db->exec("ALTER TABLE inventory_locations MODIFY warehouse_id INT UNSIGNED NULL,
            ADD location_type ENUM('internal', 'customer', 'supplier') NOT NULL DEFAULT 'internal',
            ADD system_key VARCHAR(32) NULL UNIQUE,
            ADD warehouse_scope INT UNSIGNED GENERATED ALWAYS AS (COALESCE(warehouse_id, 0)) STORED,
            ADD INDEX location_warehouse_index (warehouse_id),
            DROP INDEX location_name, ADD UNIQUE KEY location_name (location_type, warehouse_scope, parent_scope, name)");
    }
    foreach (['customer' => 'Clientes', 'supplier' => 'Proveedores'] as $location_type => $name) {
        $db->prepare('INSERT INTO inventory_locations (name, location_type, system_key) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE system_key = VALUES(system_key)')->execute([$name, $location_type, 'default_' . $location_type]);
    }
    if ($db->query("SHOW TABLES LIKE 'contacts'")->fetch() && ! $db->query("SHOW COLUMNS FROM contacts LIKE 'partner_location_id'")->fetch()) {
        $db->exec('ALTER TABLE contacts ADD partner_location_id INT UNSIGNED NULL,
            ADD CONSTRAINT contact_external_location FOREIGN KEY (partner_location_id) REFERENCES inventory_locations(id)');
    }
}
