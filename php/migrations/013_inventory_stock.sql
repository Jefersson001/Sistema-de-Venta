CREATE TABLE IF NOT EXISTS inventory_operation_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    demand DECIMAL(11,2) NOT NULL,
    quantity DECIMAL(11,2) NOT NULL,
    FOREIGN KEY (operation_id) REFERENCES inventory_operations(id),
    FOREIGN KEY (product_id) REFERENCES inventory_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS inventory_stock (
    product_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(17,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (product_id, location_id),
    FOREIGN KEY (product_id) REFERENCES inventory_products(id),
    FOREIGN KEY (location_id) REFERENCES inventory_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS inventory_stock_moves (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_id INT UNSIGNED NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(11,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY movement_once (line_id, location_id),
    FOREIGN KEY (operation_id) REFERENCES inventory_operations(id),
    FOREIGN KEY (line_id) REFERENCES inventory_operation_lines(id),
    FOREIGN KEY (product_id) REFERENCES inventory_products(id),
    FOREIGN KEY (location_id) REFERENCES inventory_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
