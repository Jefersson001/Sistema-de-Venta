CREATE TABLE IF NOT EXISTS inventory_operations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(32) NULL UNIQUE,
    operation_type ENUM('delivery', 'receipt', 'internal') NOT NULL,
    scheduled_date DATE NOT NULL,
    contact_id INT UNSIGNED NULL,
    source_warehouse_id INT UNSIGNED NULL,
    source_location_id INT UNSIGNED NULL,
    destination_warehouse_id INT UNSIGNED NULL,
    destination_location_id INT UNSIGNED NULL,
    notes TEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX operation_type_date (operation_type, scheduled_date),
    FOREIGN KEY (contact_id) REFERENCES contacts(id),
    FOREIGN KEY (source_warehouse_id) REFERENCES inventory_warehouses(id),
    FOREIGN KEY (source_location_id) REFERENCES inventory_locations(id),
    FOREIGN KEY (destination_warehouse_id) REFERENCES inventory_warehouses(id),
    FOREIGN KEY (destination_location_id) REFERENCES inventory_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
