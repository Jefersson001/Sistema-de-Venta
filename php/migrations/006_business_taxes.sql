CREATE TABLE IF NOT EXISTS business_taxes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    module_key ENUM('compras', 'ventas') NOT NULL,
    UNIQUE KEY business_tax_name (module_key, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
