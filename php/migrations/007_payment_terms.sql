CREATE TABLE IF NOT EXISTS accounting_payment_terms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    UNIQUE KEY payment_term_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
