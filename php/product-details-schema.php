<?php
declare(strict_types=1);
require_once __DIR__.'/phase15-schema.php';

function installProductDetails(PDO $db): void
{
    phase15Column($db,'inventory_products','barcode','VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL');
    phase15Column($db,'inventory_products','color','VARCHAR(80) NULL');
    foreach(['weight_kg','width_cm','height_cm','depth_cm'] as $field){
        phase15Column($db,'inventory_products',$field,'DECIMAL(10,2) NULL');
        $name='inventory_products_'.$field.'_nonnegative';
        $q=$db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_products' AND CONSTRAINT_NAME=?");$q->execute([$name]);
        if(!$q->fetchColumn())$db->exec("ALTER TABLE inventory_products ADD CONSTRAINT `$name` CHECK (`$field` IS NULL OR `$field`>=0)");
    }
    $q=$db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_products' AND INDEX_NAME='inventory_product_barcode'");
    if(!$q->fetchColumn())$db->exec('ALTER TABLE inventory_products ADD UNIQUE KEY inventory_product_barcode(company_id,barcode)');
}
