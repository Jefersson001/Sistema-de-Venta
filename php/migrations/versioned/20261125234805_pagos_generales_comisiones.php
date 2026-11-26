<?php
declare(strict_types=1);

return static function (PDO $db): void {
    if(!$db->query("SHOW COLUMNS FROM sales_commission_payments LIKE 'payment_scope'")->fetch()){
        $db->exec("ALTER TABLE sales_commission_payments ADD COLUMN payment_scope ENUM('invoice','seller') NOT NULL DEFAULT 'invoice'");
    }
};
