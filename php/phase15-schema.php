<?php
declare(strict_types=1);
require_once __DIR__.'/company-schema.php';

function phase15Backup(): string
{
    $config=require __DIR__.'/config/database.php';
    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$config['database']))throw new RuntimeException('Base de datos inválida.');
    $directory=__DIR__.'/backups';if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('No se pudo preparar el respaldo.');
    $file=$directory.'/before-phases-1-5-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
    $env=getenv();$env['MYSQL_PWD']=$config['password'];
    $p=proc_open(['C:/xampp/mysql/bin/mysqldump.exe','--host='.$config['host'],'--port='.$config['port'],'--user='.$config['username'],'--single-transaction','--routines','--triggers','--hex-blob','--result-file='.$file,$config['database']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env,['bypass_shell'=>true,'create_new_console'=>false]);
    if(!is_resource($p))throw new RuntimeException('No se pudo iniciar el respaldo.');fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
    if($exit!==0||!is_file($file)||filesize($file)===0)throw new RuntimeException('El respaldo falló: '.$error);
    return $file;
}
function phase15Column(PDO $db,string $table,string $column,string $definition): void
{
    if(!$db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch())$db->exec("ALTER TABLE `$table` ADD `$column` $definition");
}
function installPhase15Schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS contact_municipalities(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,state_id INT UNSIGNED NOT NULL,name VARCHAR(120) NOT NULL,UNIQUE KEY municipality_name(state_id,name),FOREIGN KEY(state_id) REFERENCES contact_states(id),created_by INT UNSIGNED NULL,created_at DATETIME NULL,updated_by INT UNSIGNED NULL,updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(['contacts','contact_cities'] as $table){
        phase15Column($db,$table,'municipality_id','INT UNSIGNED NULL');
        $q=$db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?");$q->execute([$table,$table.'_municipality']);
        if(!$q->fetchColumn())$db->exec("ALTER TABLE `$table` ADD CONSTRAINT `{$table}_municipality` FOREIGN KEY(municipality_id) REFERENCES contact_municipalities(id)");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS contact_addresses(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,contact_id INT UNSIGNED NOT NULL,label VARCHAR(80) NOT NULL,address VARCHAR(255) NOT NULL,postal_code VARCHAR(20) NULL,country_id INT UNSIGNED NULL,state_id INT UNSIGNED NULL,municipality_id INT UNSIGNED NULL,city_id INT UNSIGNED NULL,usage_type ENUM('both','invoice','delivery') NOT NULL DEFAULT 'both',is_primary TINYINT UNSIGNED NOT NULL DEFAULT 0,default_invoice TINYINT UNSIGNED NOT NULL DEFAULT 0,default_delivery TINYINT UNSIGNED NOT NULL DEFAULT 0,active TINYINT UNSIGNED NOT NULL DEFAULT 1,version INT UNSIGNED NOT NULL DEFAULT 1,INDEX contact_address_parent(contact_id),FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE CASCADE,FOREIGN KEY(country_id) REFERENCES contact_countries(id),FOREIGN KEY(state_id) REFERENCES contact_states(id),FOREIGN KEY(municipality_id) REFERENCES contact_municipalities(id),FOREIGN KEY(city_id) REFERENCES contact_cities(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    installCompanyTable($db,'contact_addresses');
    foreach(['sales_quotations'=>['invoice_address_id','delivery_address_id'],'purchase_orders'=>['invoice_address_id'],'accounting_invoices'=>['invoice_address_id','delivery_address_id'],'inventory_operations'=>['partner_address_id']] as $table=>$columns)foreach($columns as $column){
        phase15Column($db,$table,$column,'INT UNSIGNED NULL');
        $name=$table.'_'.$column;$q=$db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$q->execute([$table,$name]);
        if(!$q->fetchColumn())$db->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY(`$column`) REFERENCES contact_addresses(id)");
    }
    phase15Column($db,'accounting_invoices','delivery_address','TEXT NULL');
    phase15Column($db,'inventory_operations','partner_address','TEXT NULL');
    $tables=$db->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);
    foreach(['contact_addresses','contacts','sales_quotations','purchase_orders','accounting_invoices','inventory_operations','contact_municipalities','contact_cities'] as $table)installCompanyTriggers($db,$table,$tables);
    // Preserve legacy data: one primary address, no fabricated geography or
    // retroactive replacement of transactional snapshots.
    $db->beginTransaction();
    try{
        foreach($db->query('SELECT * FROM contacts ORDER BY id')->fetchAll() as $contact){
            $q=$db->prepare('SELECT id FROM contact_addresses WHERE contact_id=? AND is_primary=1');$q->execute([$contact['id']]);if($q->fetchColumn())continue;
            $db->exec('SET @nexo_company_id='.(int)$contact['company_id'].', @nexo_actor_id=NULL');
            $db->prepare("INSERT INTO contact_addresses(company_id,contact_id,label,address,postal_code,country_id,state_id,municipality_id,city_id,is_primary,default_invoice,default_delivery) VALUES(?,?,'Principal',?,?,?,?,?,?,1,1,1)")->execute([$contact['company_id'],$contact['id'],$contact['address']??'',$contact['postal_code'],$contact['country_id'],$contact['state_id'],$contact['municipality_id'],$contact['city_id']]);
        }
        $db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
}
