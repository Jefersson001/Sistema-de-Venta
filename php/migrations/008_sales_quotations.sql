CREATE TABLE IF NOT EXISTS sales_quotations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(32) NULL,
    status ENUM('draft', 'sent', 'sale') NOT NULL DEFAULT 'draft',
    customer_id INT UNSIGNED NOT NULL,
    invoice_address TEXT NOT NULL,
    delivery_address TEXT NOT NULL,
    order_date DATE NOT NULL,
    payment_term_id INT UNSIGNED NULL,
    salesperson_id INT UNSIGNED NULL,
    base_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY quotation_reference (name),
    INDEX quotation_status_date (status, order_date),
    CONSTRAINT quotation_customer FOREIGN KEY (customer_id) REFERENCES contacts(id),
    CONSTRAINT quotation_payment_term FOREIGN KEY (payment_term_id) REFERENCES accounting_payment_terms(id),
    CONSTRAINT quotation_salesperson FOREIGN KEY (salesperson_id) REFERENCES contacts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_quotation_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quotation_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(160) NOT NULL,
    quantity DECIMAL(11,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    tax_id INT UNSIGNED NULL,
    tax_name VARCHAR(120) NULL,
    base_amount DECIMAL(18,2) NOT NULL,
    total_amount DECIMAL(18,2) NOT NULL,
    CONSTRAINT quotation_line_header FOREIGN KEY (quotation_id) REFERENCES sales_quotations(id),
    CONSTRAINT quotation_line_product FOREIGN KEY (product_id) REFERENCES inventory_products(id),
    CONSTRAINT quotation_line_tax FOREIGN KEY (tax_id) REFERENCES business_taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
