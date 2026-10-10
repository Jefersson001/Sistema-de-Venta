ALTER TABLE sales_quotations
    ADD order_type ENUM('delivery', 'invoice') NOT NULL DEFAULT 'delivery';
