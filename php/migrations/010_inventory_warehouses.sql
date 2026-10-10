CREATE TABLE IF NOT EXISTS inventory_warehouses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    UNIQUE KEY warehouse_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    parent_scope INT UNSIGNED GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED,
    UNIQUE KEY location_name (warehouse_id, parent_scope, name),
    CONSTRAINT location_warehouse FOREIGN KEY (warehouse_id) REFERENCES inventory_warehouses(id),
    CONSTRAINT location_parent FOREIGN KEY (parent_id) REFERENCES inventory_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
