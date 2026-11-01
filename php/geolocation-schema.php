<?php
declare(strict_types=1);
require_once __DIR__.'/phase15-schema.php';
function installGeolocation(PDO $db): void
{
    foreach(['contacts'=>[['latitude','longitude']],'contact_addresses'=>[['latitude','longitude']],'sales_quotations'=>[['customer_latitude','customer_longitude'],['sale_latitude','sale_longitude']],'inventory_operations'=>[['partner_latitude','partner_longitude']],'accounting_invoices'=>[['delivery_latitude','delivery_longitude']]] as $table=>$pairs){
        foreach($pairs as [$lat,$lon]){
            phase15Column($db,$table,$lat,'DECIMAL(9,6) NULL');phase15Column($db,$table,$lon,'DECIMAL(10,6) NULL');
            $name=$table.'_'.$lat.'_pair';$q=$db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$q->execute([$table,$name]);
            if(!$q->fetchColumn())$db->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ((`$lat` IS NULL AND `$lon` IS NULL) OR (`$lat` IS NOT NULL AND `$lon` IS NOT NULL AND `$lat` BETWEEN -90 AND 90 AND `$lon` BETWEEN -180 AND 180))");
        }
    }
    phase15Column($db,'sales_quotations','customer_location_source',"VARCHAR(12) NOT NULL DEFAULT 'address'");
    phase15Column($db,'sales_quotations','sale_location_source',"VARCHAR(12) NOT NULL DEFAULT 'manual'");
    phase15Column($db,'sales_quotations','sale_location_recorded_at','DATETIME NULL');
    phase15Column($db,'sales_quotations','sale_location_recorded_by','INT UNSIGNED NULL');
    $q=$db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='sales_quotations' AND CONSTRAINT_NAME='sale_location_user'");$q->execute();
    if(!$q->fetchColumn())$db->exec('ALTER TABLE sales_quotations ADD CONSTRAINT sale_location_user FOREIGN KEY(sale_location_recorded_by) REFERENCES auth_users(id) ON DELETE SET NULL');
}
