<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/company-schema.php';
    require_once dirname(__DIR__,2).'/access-schema.php';
    $columns=$db->query('SHOW COLUMNS FROM document_email_log')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    if($columns['invoice_id']['Null']!=='YES')$db->exec('ALTER TABLE document_email_log MODIFY invoice_id INT UNSIGNED NULL');
    foreach(['origin_kind'=>"VARCHAR(16) NOT NULL DEFAULT 'invoice'",'origin_id'=>'INT UNSIGNED NULL','sale_id'=>'INT UNSIGNED NULL','operation_id'=>'INT UNSIGNED NULL'] as $name=>$definition)if(!isset($columns[$name]))$db->exec("ALTER TABLE document_email_log ADD $name $definition");
    $context=$db->query('SELECT @nexo_company_id AS company,@nexo_actor_id AS actor')->fetch();
    try{
        foreach($db->query("SELECT DISTINCT company_id FROM document_email_log WHERE origin_kind='invoice' AND origin_id IS NULL AND invoice_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $company){
            $db->exec('SET @nexo_company_id='.(int)$company.', @nexo_actor_id=NULL');
            $q=$db->prepare("UPDATE document_email_log SET origin_id=invoice_id WHERE company_id=? AND origin_kind='invoice' AND origin_id IS NULL AND invoice_id IS NOT NULL");$q->execute([$company]);
        }
    }finally{$db->exec('SET @nexo_company_id='.($context['company']===null?'NULL':(int)$context['company']).', @nexo_actor_id='.($context['actor']===null?'NULL':(int)$context['actor']));}
    foreach(['sale_id'=>'sales_quotations','operation_id'=>'inventory_operations'] as $column=>$parent){
        $name='document_email_'.$column.'_fk';$q=$db->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$q->execute(['document_email_log',$name]);if(!$q->fetchColumn())$db->exec("ALTER TABLE document_email_log ADD CONSTRAINT $name FOREIGN KEY ($column) REFERENCES $parent(id)");
    }
    registerCompanyTable($db,'document_email_log');
    syncAccessPermissionCatalog($db);
    $grant=$db->prepare('INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,?)');
    foreach($db->query("SELECT id,system_key FROM access_groups WHERE system_key IN ('administrator','inventory','sales')")->fetchAll() as $group)$grant->execute([$group['id'],'inventario.deliveries.send',$group['system_key']==='sales'?'own':'all']);
};
