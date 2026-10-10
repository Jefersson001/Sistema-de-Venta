CREATE TABLE IF NOT EXISTS inventory_products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    sale_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    purchase_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    INDEX inventory_product_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
