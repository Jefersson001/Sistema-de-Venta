<?php
declare(strict_types=1);

function installProductVariants(PDO $db): void
{
    if(!$db->query("SHOW COLUMNS FROM inventory_products LIKE 'parent_product_id'")->fetch()){
        $db->exec('ALTER TABLE inventory_products ADD parent_product_id INT UNSIGNED NULL');
    }
    $q=$db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_products' AND CONSTRAINT_NAME='inventory_variant_parent'");$q->execute();
    if(!$q->fetchColumn())$db->exec('ALTER TABLE inventory_products ADD CONSTRAINT inventory_variant_parent FOREIGN KEY(parent_product_id) REFERENCES inventory_products(id)');
    $q=$db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_products' AND INDEX_NAME='inventory_variant_name'");$q->execute();
    if(!$q->fetchColumn()){
        $company=$db->query("SHOW COLUMNS FROM inventory_products LIKE 'company_id'")->fetch();
        $db->exec('ALTER TABLE inventory_products ADD UNIQUE KEY inventory_variant_name('.($company?'company_id,':'').'parent_product_id,name)');
    }
    if($db->query("SHOW TABLES LIKE 'company_schema_tables'")->fetch()){
        require_once __DIR__.'/company-schema.php';registerCompanyTable($db,'inventory_products');
    }
}

function uninstallEmptyProductVariants(PDO $db): void
{
    if(!$db->query("SHOW COLUMNS FROM inventory_products LIKE 'parent_product_id'")->fetch())return;
    if($db->query('SELECT id FROM inventory_products WHERE parent_product_id IS NOT NULL LIMIT 1')->fetch())throw new RuntimeException('Hay variantes creadas. Conserva sus relaciones e historial.');
    $db->exec('ALTER TABLE inventory_products DROP FOREIGN KEY inventory_variant_parent, DROP INDEX inventory_variant_name, DROP COLUMN parent_product_id');
    if($db->query("SHOW TABLES LIKE 'company_schema_tables'")->fetch()){
        require_once __DIR__.'/company-schema.php';registerCompanyTable($db,'inventory_products');
    }
}
