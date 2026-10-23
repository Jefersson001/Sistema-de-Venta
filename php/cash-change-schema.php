<?php
declare(strict_types=1);

/** Additive, repeatable migration. Existing payment amounts and ledger entries stay intact. */
function installCashChange(PDO $db): void
{
    $newFlag=!$db->query("SHOW COLUMNS FROM accounting_payment_methods LIKE 'allow_change'")->fetch();
    $columns=['accounting_payment_methods'=>['allow_change'=>'TINYINT(1) NOT NULL DEFAULT 0'],
        'accounting_payments'=>['received_amount'=>'DECIMAL(18,2) NULL','change_amount'=>'DECIMAL(18,2) NOT NULL DEFAULT 0','change_enabled'=>'TINYINT(1) NOT NULL DEFAULT 0'],
        'accounting_treasury_entries'=>['flow_amount'=>'DECIMAL(20,2) NULL','cash_role'=>"VARCHAR(32) NOT NULL DEFAULT 'normal'"]];
    foreach($columns as $table=>$fields){$existing=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);foreach($fields as $field=>$definition)if(!in_array($field,$existing,true))$db->exec("ALTER TABLE $table ADD $field $definition");}
    $tenant=(bool)$db->query("SHOW COLUMNS FROM accounting_treasury_entries LIKE 'company_id'")->fetch();
    $indexes=$db->query('SHOW INDEX FROM accounting_treasury_entries')->fetchAll();$names=array_unique(array_column($indexes,'Key_name'));
    foreach(['cash_payment_lookup'=>'payment_id','cash_document_lookup'=>'document_id'] as $name=>$field)if(!in_array($name,$names,true))$db->exec("ALTER TABLE accounting_treasury_entries ADD INDEX $name($field)");
    $unique=[];foreach($indexes as $index)if(!(int)$index['Non_unique'])$unique[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];
    foreach($unique as $name=>$fields){ksort($fields);$fields=array_values($fields);
        if($name==='PRIMARY'||in_array('cash_role',$fields,true))continue;
        if(in_array('payment_id',$fields,true)||in_array('document_id',$fields,true)){
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new LogicException('Índice no válido.');
            $db->exec('ALTER TABLE accounting_treasury_entries DROP INDEX `'.$name.'`');
        }
    }
    foreach(['cash_payment_role'=>'payment_id,cash_role','cash_document_role'=>'document_id,account_id,cash_role'] as $name=>$fields)if(!in_array($name,$names,true))$db->exec('ALTER TABLE accounting_treasury_entries ADD UNIQUE KEY '.$name.'('.($tenant?'company_id,':'').$fields.')');
    // Enable the existing standard cash method once; subsequent installations respect user choices.
    if($newFlag){$tenantMethods=(bool)$db->query("SHOW COLUMNS FROM accounting_payment_methods LIKE 'company_id'")->fetch();
        if($tenantMethods){foreach($db->query("SELECT id,company_id FROM accounting_payment_methods WHERE system_key='cash'")->fetchAll() as $method){$db->exec('SET @nexo_company_id='.(int)$method['company_id'].', @nexo_actor_id=NULL');$db->prepare('UPDATE accounting_payment_methods SET allow_change=1 WHERE id=?')->execute([$method['id']]);}}
        else $db->exec("UPDATE accounting_payment_methods SET allow_change=1 WHERE system_key='cash'");
    }
}
