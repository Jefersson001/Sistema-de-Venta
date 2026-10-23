<?php
declare(strict_types=1);
require_once __DIR__.'/company-catalog.php';

function companyQuoteIdentifier(string $name): string
{
    if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$name))throw new LogicException('Identificador inválido.');
    return '`'.$name.'`';
}

/** Reusable base for future module tables. Call after creating their domain columns. */
function installCompanyTable(PDO $db,string $table): void
{
    $t=companyQuoteIdentifier($table);
    $columns=$db->query('SHOW COLUMNS FROM '.$t)->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    foreach(['company_id'=>'INT UNSIGNED NOT NULL DEFAULT 1','created_by'=>'INT UNSIGNED NULL',
        'created_at'=>'DATETIME NULL DEFAULT NULL','updated_by'=>'INT UNSIGNED NULL','updated_at'=>'DATETIME NULL DEFAULT NULL'] as $name=>$definition) {
        if(!isset($columns[$name]))$db->exec("ALTER TABLE $t ADD `$name` $definition");
    }
    if(!$db->query("SHOW INDEX FROM $t WHERE Key_name='company_scope'")->fetch())$db->exec("ALTER TABLE $t ADD INDEX company_scope(company_id)");
    $find=$db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    foreach(['company_id'=>['business_company','CASCADE'],'created_by'=>['auth_users','SET NULL'],'updated_by'=>['auth_users','SET NULL']] as $col=>[$parent,$onDelete]) {
        $constraint='mc_'.$table.'_'.$col;$find->execute([$table,$constraint]);
        if(!$find->fetchColumn())$db->exec("ALTER TABLE $t ADD CONSTRAINT `$constraint` FOREIGN KEY (`$col`) REFERENCES `$parent`(id) ON DELETE $onDelete");
    }
    // Existing IDs remain globally unique. Other unique business keys are local
    // to the company (names, tax identifiers, currency codes, idempotency keys).
    $indexes=[];
    foreach($db->query('SHOW INDEX FROM '.$t)->fetchAll() as $r)if(!(int)$r['Non_unique'])$indexes[$r['Key_name']][(int)$r['Seq_in_index']]=$r['Column_name'];
    foreach($indexes as $key=>$fields) {
        ksort($fields);$fields=array_values($fields);
        if(in_array('company_id',$fields,true))continue;
        if($key==='PRIMARY'&&$table!=='accounting_currency_settings')continue;
        $cols=implode(',',array_map('companyQuoteIdentifier',array_merge(['company_id'],$fields)));
        if($key==='PRIMARY')$db->exec("ALTER TABLE $t DROP PRIMARY KEY, ADD PRIMARY KEY ($cols)");
        else {
            $support='mc_fk_'.substr(hash('sha256',$table.'.'.$key),0,16);
            if(!$db->query("SHOW INDEX FROM $t WHERE Key_name='$support'")->fetch())
                $db->exec("ALTER TABLE $t ADD INDEX `$support` (".implode(',',array_map('companyQuoteIdentifier',$fields)).')');
            $db->exec("ALTER TABLE $t DROP INDEX ".companyQuoteIdentifier($key).', ADD UNIQUE KEY '.companyQuoteIdentifier($key)." ($cols)");
        }
    }
    $db->prepare('INSERT IGNORE INTO company_schema_tables(table_name) VALUES(?)')->execute([$table]);
}

function installCompanyTriggers(PDO $db,string $table,array $tenantTables): void
{
    $t=companyQuoteIdentifier($table);$tenant=in_array($table,$tenantTables,true);
    $references=$db->prepare('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $references->execute([$table]);$checks='';
    if($tenant)foreach($references->fetchAll() as $fk) {
        if(!in_array($fk['REFERENCED_TABLE_NAME'],$tenantTables,true))continue;
        $col=companyQuoteIdentifier($fk['COLUMN_NAME']);$parent=companyQuoteIdentifier($fk['REFERENCED_TABLE_NAME']);$pk=companyQuoteIdentifier($fk['REFERENCED_COLUMN_NAME']);
        // The actual FK enforces existence. This additional check enforces ownership.
        $checks.="IF NEW.$col IS NOT NULL AND EXISTS(SELECT 1 FROM $parent p WHERE p.$pk=NEW.$col AND p.company_id<>NEW.company_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='No puedes relacionar registros de empresas diferentes.'; END IF; ";
    }
    foreach(['INSERT','UPDATE'] as $event) {
        $trigger='mc_'.$table.'_'.strtolower($event);$db->exec('DROP TRIGGER IF EXISTS '.companyQuoteIdentifier($trigger));
        $guard='';
        if($tenant) {
            $guard=$event==='INSERT'
                ? "IF NEW.company_id<>COALESCE(@nexo_company_id,1) AND NEW.company_id<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La empresa del registro no coincide con la empresa activa.'; END IF; SET NEW.company_id=COALESCE(@nexo_company_id,1); "
                : "IF OLD.company_id<>COALESCE(@nexo_company_id,1) OR NEW.company_id<>OLD.company_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Activa la empresa del registro antes de modificarlo.'; END IF; ";
        }
        $audit=$event==='INSERT'
            ? 'SET NEW.created_by=@nexo_actor_id; SET NEW.created_at=CURRENT_TIMESTAMP; '
            : 'SET NEW.created_by=OLD.created_by; SET NEW.created_at=OLD.created_at; ';
        $audit.='SET NEW.updated_by=@nexo_actor_id; SET NEW.updated_at=CURRENT_TIMESTAMP; ';
        $db->exec('CREATE TRIGGER '.companyQuoteIdentifier($trigger)." BEFORE $event ON $t FOR EACH ROW BEGIN $guard $audit $checks END");
    }
    if($tenant) {
        $name=companyQuoteIdentifier('mc_'.$table.'_delete');$db->exec('DROP TRIGGER IF EXISTS '.$name);
        $db->exec("CREATE TRIGGER $name BEFORE DELETE ON $t FOR EACH ROW BEGIN IF OLD.company_id<>COALESCE(@nexo_company_id,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Activa la empresa del registro antes de eliminarlo.'; END IF; END");
    }
}

/** CLI migration only. DDL is resumable, but cannot be rolled back by MariaDB. */
function installMultiCompany(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS company_schema_state(id TINYINT UNSIGNED PRIMARY KEY,version INT UNSIGNED NOT NULL) ENGINE=InnoDB");
    $db->exec('INSERT IGNORE INTO company_schema_state VALUES(1,0)');
    $db->exec("CREATE TABLE IF NOT EXISTS company_schema_tables(table_name VARCHAR(64) PRIMARY KEY) ENGINE=InnoDB");
    $tables=array_values(array_unique(array_merge(companyTables(),$db->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN))));
    // Refuse incomplete installations before touching business data.
    foreach(array_merge($tables,['auth_users','business_company']) as $table) {
        $q=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
        if(!$q->fetchColumn())throw new RuntimeException('Instala los módulos antes de migrar. Falta '.$table);
    }
    $constraints=$db->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='business_company' AND CONSTRAINT_TYPE='CHECK'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($constraints as $constraint)$db->exec('ALTER TABLE business_company DROP CONSTRAINT '.companyQuoteIdentifier($constraint));
    $db->exec('ALTER TABLE business_company MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');
    foreach(['created_by'=>'INT UNSIGNED NULL','created_at'=>'DATETIME NULL DEFAULT NULL','updated_by'=>'INT UNSIGNED NULL'] as $col=>$def) {
        if(!$db->query("SHOW COLUMNS FROM business_company LIKE '$col'")->fetch())$db->exec("ALTER TABLE business_company ADD `$col` $def");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS company_user_access(
        user_id INT UNSIGNED NOT NULL,company_id INT UNSIGNED NOT NULL,
        PRIMARY KEY(user_id,company_id), FOREIGN KEY(user_id) REFERENCES auth_users(id) ON DELETE CASCADE,
        FOREIGN KEY(company_id) REFERENCES business_company(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    if(!$db->query("SHOW COLUMNS FROM company_user_access LIKE 'contact_id'")->fetch())
        $db->exec('ALTER TABLE company_user_access ADD contact_id INT UNSIGNED NULL, ADD UNIQUE KEY company_contact(contact_id), ADD FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE SET NULL');
    if((int)$db->query('SELECT version FROM company_schema_state WHERE id=1')->fetchColumn()===0)
        $db->exec('INSERT IGNORE INTO company_user_access(user_id,company_id) SELECT id,1 FROM auth_users');
    foreach($tables as $table)installCompanyTable($db,$table);
    // Shared administrative records have audit metadata, but no owning company.
    $shared=['auth_users','auth_user_groups','access_groups','access_group_permissions','business_company','company_user_access',
        'contact_countries','contact_states','contact_cities','contact_types','contact_document_types'];
    foreach($shared as $table)foreach(['created_by'=>'INT UNSIGNED NULL','created_at'=>'DATETIME NULL DEFAULT NULL','updated_by'=>'INT UNSIGNED NULL','updated_at'=>'DATETIME NULL DEFAULT NULL'] as $col=>$def) {
        if(!$db->query('SHOW COLUMNS FROM '.companyQuoteIdentifier($table)." LIKE '$col'")->fetch())$db->exec('ALTER TABLE '.companyQuoteIdentifier($table)." ADD `$col` $def");
    }
    foreach(array_merge($tables,$shared) as $table)installCompanyTriggers($db,$table,$tables);
    foreach(['INSERT','UPDATE'] as $event) {
        $name=companyQuoteIdentifier('mc_membership_contact_'.strtolower($event));$db->exec('DROP TRIGGER IF EXISTS '.$name);
        $db->exec("CREATE TRIGGER $name BEFORE $event ON company_user_access FOR EACH ROW BEGIN IF NEW.contact_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM contacts WHERE id=NEW.contact_id AND company_id=NEW.company_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El contacto del usuario debe pertenecer a la misma empresa.'; END IF; END");
    }
    // Repositories may delete the final company row only through the explicit,
    // transactional delete operation, after showing and checking its impact.
    $db->exec('DROP TRIGGER IF EXISTS mc_business_company_delete');
    $db->exec("CREATE TRIGGER mc_business_company_delete BEFORE DELETE ON business_company FOR EACH ROW BEGIN IF COALESCE(@nexo_delete_company,0)<>OLD.id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Usa la eliminación confirmada de empresas.'; END IF; END");
    $db->exec('UPDATE company_schema_state SET version=1 WHERE id=1');
}

function registerCompanyTable(PDO $db,string $table): void
{
    installCompanyTable($db,$table);
    $tables=$db->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as $registered)installCompanyTriggers($db,$registered,$tables);
}
