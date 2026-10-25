CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(32) NOT NULL UNIQUE,
    supplier_id INT UNSIGNED NOT NULL,
    order_date DATE NOT NULL,
    approved_date DATE NULL,
    status ENUM('draft', 'sent', 'purchase', 'cancel') NOT NULL DEFAULT 'draft',
    FOREIGN KEY (supplier_id) REFERENCES contacts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS purchase_order_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(11,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (order_id) REFERENCES purchase_orders(id),
    FOREIGN KEY (product_id) REFERENCES inventory_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
