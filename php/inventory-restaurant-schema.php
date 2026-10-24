<?php
declare(strict_types=1);

function installInventoryRestaurantCategories(PDO $db): void
{
    if(!$db->query("SHOW COLUMNS FROM inventory_categories LIKE 'restaurant_enabled'")->fetch()){
        $db->exec('ALTER TABLE inventory_categories ADD restaurant_enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0');
    }
    $q=$db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='inventory_categories' AND CONSTRAINT_NAME='inventory_category_restaurant_root'");$q->execute();
    if(!$q->fetchColumn())$db->exec('ALTER TABLE inventory_categories ADD CONSTRAINT inventory_category_restaurant_root CHECK(restaurant_enabled IN (0,1) AND (parent_id IS NULL OR restaurant_enabled=0))');
}
