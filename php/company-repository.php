<?php
declare(strict_types=1);
require_once __DIR__.'/company.php';
require_once __DIR__.'/company-schema.php';
require_once __DIR__.'/settings-repository.php';

function companyRequireAdministrator(PDO $db,int $actor): void
{
    if(!authUserIsAdministrator($db,$actor))throw new InvalidArgumentException('Solo un Administrador puede administrar empresas y sus accesos.',403);
}

function companyImpact(PDO $db,int $id): array
{
    $impact=[];
    foreach($db->query('SELECT table_name FROM company_schema_tables ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $q=$db->prepare('SELECT COUNT(*) FROM '.companyQuoteIdentifier($table).' WHERE company_id=?');$q->execute([$id]);
        $impact[$table]=(int)$q->fetchColumn();
    }
    return $impact;
}

function companyImpactHash(array $impact): string { return hash('sha256',json_encode($impact,JSON_THROW_ON_ERROR)); }

function companySeedDefaults(PDO $db,int $company): void
{
    $db->exec('SET @nexo_company_id='.$company);
    $db->exec("INSERT INTO accounting_currency_settings(id,primary_code,alternate_code) VALUES(1,'USD','VES')");
    $q=$db->prepare('INSERT INTO accounting_currencies(code,name,symbol,unit_label,subunit_label,symbol_position) VALUES(?,?,?,?,?,?)');
    $q->execute(['USD','Dólar estadounidense','$','Dólar','Centavo','before']);
    $q->execute(['VES','Bolívar','Bs.','Bolívar','Céntimo','after']);
    $db->exec("INSERT INTO accounting_payment_accounts(name,account_type,currency_code,system_key) VALUES('Caja','cash','USD','default_cash')");
    // Seed explicit constants: another company's methods must not suppress defaults.
    $q=$db->prepare('INSERT IGNORE INTO accounting_payment_methods(name,system_key) VALUES(?,?)');
    foreach(['manual'=>'Manual','cash'=>'Efectivo','bank_transfer'=>'Transferencia bancaria','mobile_payment'=>'Pago móvil','card'=>'Tarjeta','zelle'=>'Zelle','usdt'=>'USDT','paypal'=>'PayPal','biopago'=>'Bio Pago'] as $key=>$name)$q->execute([$name,$key]);
    $db->exec("INSERT INTO accounting_payment_terms(name,days) VALUES('Contado',0)");
    $q=$db->prepare('INSERT INTO inventory_locations(name,location_type,system_key) VALUES(?,?,?)');
    $q->execute(['Clientes','customer','default_customer']);$q->execute(['Proveedores','supplier','default_supplier']);
    $q=$db->prepare('INSERT INTO accounting_cash_concepts(name,direction,system_key) VALUES(?,?,?)');
    foreach(['customer_payment'=>['Cobro de cliente','inbound'],'supplier_payment'=>['Pago a proveedor','outbound'],
        'customer_refund'=>['Devolución a cliente','outbound'],'supplier_refund'=>['Devolución de proveedor','inbound'],
        'sales_commission'=>['Pago de comisiones','outbound']] as $key=>[$name,$direction])$q->execute([$name,$direction,$key]);
}

function createBusinessCompany(PDO $db,array $input,int $actor): int
{
    $data=validateBusinessCompany($input);$db->beginTransaction();
    try {
        authLockState($db);companyRequireAdministrator($db,$actor);
        $db->exec('SET @nexo_actor_id='.$actor);
        $db->prepare('INSERT INTO business_company('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));
        $id=(int)$db->lastInsertId();companySeedDefaults($db,$id);
        $db->prepare('INSERT INTO company_user_access(user_id,company_id) VALUES(?,?)')->execute([$actor,$id]);
        $db->commit();return $id;
    } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    finally {$db->exec('SET @nexo_company_id='.companyActiveId());}
}

function saveCompanyUsers(PDO $db,int $id,array $users,int $version,int $actor,array $contacts=[]): void
{
    $db->beginTransaction();
    try {
        authLockState($db);companyRequireAdministrator($db,$actor);
        $q=$db->prepare('SELECT version FROM business_company WHERE id=? FOR UPDATE');$q->execute([$id]);
        if((int)$q->fetchColumn()!==$version)throw new InvalidArgumentException('La empresa cambió. Recarga antes de guardar sus accesos.',409);
        $ids=[];
        foreach($users as $value){$user=accessId($value);if(!$user)throw new InvalidArgumentException('Usuario inválido.');$ids[]=$user;}
        $q=$db->prepare('SELECT user_id,contact_id FROM company_user_access WHERE company_id=?');$q->execute([$id]);$oldContacts=$q->fetchAll(PDO::FETCH_KEY_PAIR);$nextContacts=[];
        $db->prepare('DELETE FROM company_user_access WHERE company_id=?')->execute([$id]);
        $q=$db->prepare('INSERT INTO company_user_access(user_id,company_id,contact_id) VALUES(?,?,?)');
        foreach(array_unique($ids) as $user){$contact=$contacts[$user]??'';if(!is_string($contact)||($contact!==''&&!accessId($contact)))throw new InvalidArgumentException('Contacto de usuario inválido.');$nextContacts[$user]=$contact===''?null:accessId($contact);$q->execute([$user,$id,$nextContacts[$user]]);}
        $changedUsers=[];
        foreach(array_unique(array_merge(array_keys($oldContacts),array_keys($nextContacts))) as $user)
            if(!array_key_exists($user,$oldContacts)||!array_key_exists($user,$nextContacts)||(string)$oldContacts[$user] !== (string)$nextContacts[$user])$changedUsers[]=$user;
        if($changedUsers)$db->prepare('UPDATE auth_users SET version=version+1,auth_version=auth_version+1 WHERE id IN('.implode(',',array_fill(0,count($changedUsers),'?')).')')->execute($changedUsers);
        $db->exec('SET @nexo_actor_id='.$actor);
        $db->prepare('UPDATE business_company SET version=version+1 WHERE id=?')->execute([$id]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function deleteBusinessCompany(PDO $db,int $id,int $version,string $confirmation,string $impactHash,int $actor): void
{
    $db->beginTransaction();
    try {
        authLockState($db);companyRequireAdministrator($db,$actor);
        $q=$db->prepare('SELECT name,version FROM business_company WHERE id=? FOR UPDATE');$q->execute([$id]);$company=$q->fetch();
        if(!$company||(int)$company['version']!==$version)throw new InvalidArgumentException('La empresa cambió. Revisa de nuevo la eliminación.',409);
        if((int)$db->query('SELECT COUNT(*) FROM business_company')->fetchColumn()<2)throw new InvalidArgumentException('Conserva al menos una empresa en el sistema.');
        if($confirmation!==$company['name'])throw new InvalidArgumentException('Escribe exactamente el nombre de la empresa para confirmar.');
        if(!hash_equals(companyImpactHash(companyImpact($db,$id)),$impactHash))throw new InvalidArgumentException('Los registros cambiaron. Revisa el alcance actualizado antes de eliminar.',409);
        $db->exec('SET @nexo_company_id='.$id.', @nexo_actor_id='.$actor.', @nexo_delete_company='.$id);
        // Flattening nullable hierarchy links can collapse generated unique keys
        // (e.g. "Existencias" in two warehouses). Give these doomed rows unique
        // temporary names first; both renames and deletion roll back together.
        foreach(['inventory_categories','inventory_locations'] as $hierarchy) {
            do {
                $prefix='__delete_'.bin2hex(random_bytes(16)).'_';
                $q=$db->prepare('SELECT COUNT(*) FROM '.companyQuoteIdentifier($hierarchy).' WHERE company_id=? AND name LIKE ?');$q->execute([$id,$prefix.'%']);
            } while((int)$q->fetchColumn()>0);
            $db->prepare('UPDATE '.companyQuoteIdentifier($hierarchy).' SET name=CONCAT(?,id) WHERE company_id=?')->execute([$prefix,$id]);
        }
        // Global identities survive; detach their company-owned salesperson.
        $db->prepare('UPDATE auth_users u JOIN contacts c ON c.id=u.contact_id SET u.contact_id=NULL,u.auth_version=u.auth_version+1 WHERE c.company_id=?')->execute([$id]);
        $tables=array_keys(companyImpact($db,$id));$dependencies=[];
        $fk=$db->prepare('SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,c.IS_NULLABLE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=k.TABLE_SCHEMA AND c.TABLE_NAME=k.TABLE_NAME AND c.COLUMN_NAME=k.COLUMN_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL');
        foreach($tables as $table) {
            $dependencies[$table]=[];$fk->execute([$table]);
            foreach($fk->fetchAll() as $ref) {
                if(!in_array($ref['REFERENCED_TABLE_NAME'],$tables,true))continue;
                if($ref['IS_NULLABLE']==='YES')$db->prepare('UPDATE '.companyQuoteIdentifier($table).' SET '.companyQuoteIdentifier($ref['COLUMN_NAME']).'=NULL WHERE company_id=?')->execute([$id]);
                else $dependencies[$table][]=$ref['REFERENCED_TABLE_NAME'];
            }
        }
        // Children first, with FK checks enabled throughout. A new mandatory
        // dependency cycle aborts the transaction instead of weakening checks.
        while($dependencies) {
            $parents=array_merge(...array_values($dependencies));$leaves=array_diff(array_keys($dependencies),$parents);
            if(!$leaves)throw new RuntimeException('Existe un ciclo de relaciones obligatorias; no se eliminó la empresa.');
            foreach($leaves as $table){$db->prepare('DELETE FROM '.companyQuoteIdentifier($table).' WHERE company_id=?')->execute([$id]);unset($dependencies[$table]);}
        }
        $db->prepare('DELETE FROM business_company WHERE id=?')->execute([$id]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally {$db->exec('SET @nexo_delete_company=NULL, @nexo_company_id='.companyActiveId());}
}
