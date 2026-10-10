CREATE TABLE IF NOT EXISTS inventory_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    parent_id INT UNSIGNED NULL,
    parent_scope INT UNSIGNED GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED,
    UNIQUE KEY inventory_category_name (parent_scope, name),
    CONSTRAINT inventory_category_parent FOREIGN KEY (parent_id) REFERENCES inventory_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
