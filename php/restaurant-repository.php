<?php
declare(strict_types=1);
require_once __DIR__.'/connection.php';
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/company.php';
require_once __DIR__.'/workflow-repository.php';

function restaurantText(mixed $value, int $max, bool $required=true): string
{
    if (!is_string($value) || mb_strlen(trim($value))>$max || ($required && trim($value)==='')) {
        throw new InvalidArgumentException('Revisa los campos obligatorios y su longitud.');
    }
    return trim($value);
}
function restaurantInt(mixed $value, int $min=1, int $max=2147483647): int
{
    $number=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if ($number===false) throw new InvalidArgumentException('Indica un número válido entre '.$min.' y '.$max.'.');
    return $number;
}
function restaurantRecord(PDO $db,string $table,int $id,bool $lock=false): array
{
    if (!in_array($table,['restaurant_areas','restaurant_tables','restaurant_accounts'],true)) throw new LogicException('Entidad no admitida.');
    $q=$db->prepare('SELECT * FROM '.$table.' WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$row=$q->fetch();
    if (!$row) throw new InvalidArgumentException('El registro no está disponible en esta empresa.',404);
    return $row;
}
function restaurantVersion(array $record,mixed $version): void
{
    if ((int)$record['version']!==restaurantInt($version)) throw new InvalidArgumentException('El registro cambió en otra ventana. Recarga antes de continuar.',409);
}
function restaurantAudit(PDO $db,string $action,array $data=[],string $reason=''): void
{
    $db->prepare('INSERT INTO restaurant_audit_events(account_id,table_id,area_id,action,reason,details) VALUES(?,?,?,?,?,?)')
        ->execute([$data['account_id']??null,$data['table_id']??null,$data['area_id']??null,$action,$reason,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function restaurantAtomic(PDO $db,callable $work): mixed
{
    workflowBegin($db);
    try { $result=$work(); $db->commit(); return $result; }
    catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}
function restaurantWaiters(PDO $db): array
{
    $q=$db->prepare("SELECT u.id,u.name FROM auth_users u WHERE u.active=1 AND
        (EXISTS(SELECT 1 FROM company_user_access a WHERE a.user_id=u.id AND a.company_id=?)
        OR EXISTS(SELECT 1 FROM auth_user_groups ug JOIN access_groups g ON g.id=ug.group_id
            WHERE ug.user_id=u.id AND g.system_key='administrator' AND g.active=1)) ORDER BY u.name,u.id");
    $q->execute([companyActiveId()]);return $q->fetchAll();
}
function restaurantSaveArea(PDO $db,array $input,?int $id=null): int
{
    permissionRequire('restaurante.configuration','configure');
    $name=restaurantText($input['name']??null,100);$order=restaurantInt($input['sort_order']??0,0,999);
    $active=restaurantInt($input['active']??0,0,1);
    return restaurantAtomic($db,function()use($db,$input,$id,$name,$order,$active){
        $before=null;
        if($id){
            $before=restaurantRecord($db,'restaurant_areas',$id,true);restaurantVersion($before,$input['version']??null);
            if(!$active){$q=$db->prepare('SELECT t.id FROM restaurant_tables t JOIN restaurant_active_tables a ON a.table_id=t.id WHERE t.area_id=? LIMIT 1');$q->execute([$id]);if($q->fetch())throw new InvalidArgumentException('El área tiene mesas ocupadas. Cierra o traslada sus cuentas antes de desactivarla.');}
            $db->prepare('UPDATE restaurant_areas SET name=?,sort_order=?,active=?,version=version+1 WHERE id=?')->execute([$name,$order,$active,$id]);
        }else{$db->prepare('INSERT INTO restaurant_areas(name,sort_order,active) VALUES(?,?,?)')->execute([$name,$order,$active]);$id=(int)$db->lastInsertId();}
        restaurantAudit($db,$before?'area_updated':'area_created',['area_id'=>$id,'before'=>$before,'name'=>$name,'active'=>$active]);return $id;
    });
}
function restaurantQuickSetup(PDO $db,array $input): int
{
    permissionRequire('restaurante.configuration','configure');$count=restaurantInt($input['table_count']??null,1,30);
    return restaurantAtomic($db,function()use($db,$input,$count){
        $q=$db->prepare('SELECT id FROM business_company WHERE id=? FOR UPDATE');$q->execute([companyActiveId()]);
        return restaurantRequest($db,'setup_tables',restaurantText($input['request_key']??null,64),[$count],function()use($db,$count){
            $q=$db->prepare('SELECT id FROM restaurant_tables WHERE company_id=? LIMIT 1');$q->execute([companyActiveId()]);if($q->fetch())throw new InvalidArgumentException('Ya hay mesas configuradas. Agrega o edita mesas desde Configuración.',409);
            $q=$db->prepare('SELECT id FROM restaurant_areas WHERE company_id=? AND active=1 ORDER BY sort_order,id LIMIT 1');$q->execute([companyActiveId()]);$area=$q->fetchColumn();
            if(!$area){$db->prepare('INSERT INTO restaurant_areas(name,active) VALUES(?,1)')->execute(['Salón principal']);$area=(int)$db->lastInsertId();}
            $q=$db->prepare('INSERT INTO restaurant_tables(area_id,name,capacity,position_x,position_y) VALUES(?,?,4,?,?)');
            for($i=1;$i<=$count;$i++)$q->execute([$area,'Mesa '.str_pad((string)$i,2,'0',STR_PAD_LEFT),(($i-1)%6)+1,intdiv($i-1,6)+1]);
            restaurantAudit($db,'tables_setup',['area_id'=>$area,'table_count'=>$count]);return (int)$area;
        });
    });
}
function restaurantSaveTable(PDO $db,array $input,?int $id=null): int
{
    permissionRequire('restaurante.configuration','configure');
    $area=restaurantInt($input['area_id']??null);$name=restaurantText($input['name']??null,60);
    $capacity=restaurantInt($input['capacity']??null,1,100);$location=restaurantText($input['location_label']??'',120,false);
    $x=restaurantInt($input['position_x']??1,1,6);$y=restaurantInt($input['position_y']??1,1,20);$active=restaurantInt($input['active']??0,0,1);
    return restaurantAtomic($db,function()use($db,$input,$id,$area,$name,$capacity,$location,$x,$y,$active){
        // Every operation that changes occupancy locks the area before its tables.
        $parent=restaurantRecord($db,'restaurant_areas',$area,true);
        if($active && !(int)$parent['active'])throw new InvalidArgumentException('Activa el área antes de habilitar su mesa.');
        $before=null;
        if($id){
            $before=restaurantRecord($db,'restaurant_tables',$id,true);restaurantVersion($before,$input['version']??null);
            $q=$db->prepare('SELECT account_id FROM restaurant_active_tables WHERE table_id=?');$q->execute([$id]);
            if($q->fetchColumn())throw new InvalidArgumentException('No se modifica una mesa ocupada. Traslada o cierra primero su cuenta.');
        }
        $q=$db->prepare('SELECT id FROM restaurant_tables WHERE area_id=? AND position_x=? AND position_y=? AND active=1 AND id<>?');
        $q->execute([$area,$x,$y,$id??0]);if($active && $q->fetch())throw new InvalidArgumentException('Ya hay una mesa activa en esa posición del mapa. Elige otra columna o fila.');
        if($id)$db->prepare('UPDATE restaurant_tables SET area_id=?,name=?,capacity=?,location_label=?,position_x=?,position_y=?,active=?,version=version+1 WHERE id=?')->execute([$area,$name,$capacity,$location,$x,$y,$active,$id]);
        else{$db->prepare('INSERT INTO restaurant_tables(area_id,name,capacity,location_label,position_x,position_y,active) VALUES(?,?,?,?,?,?,?)')->execute([$area,$name,$capacity,$location,$x,$y,$active]);$id=(int)$db->lastInsertId();}
        restaurantAudit($db,$before?'table_updated':'table_created',['table_id'=>$id,'area_id'=>$area,'before'=>$before,'name'=>$name,'active'=>$active]);return $id;
    });
}
function restaurantLockTable(PDO $db,int $id): array
{
    $before=restaurantRecord($db,'restaurant_tables',$id);
    $area=restaurantRecord($db,'restaurant_areas',(int)$before['area_id'],true);
    $table=restaurantRecord($db,'restaurant_tables',$id,true);
    if((int)$table['area_id']!==(int)$area['id'])throw new InvalidArgumentException('La mesa cambió de área. Recarga.',409);
    if(!(int)$area['active'] || !(int)$table['active'])throw new InvalidArgumentException('La mesa o su área están desactivadas.');
    return $table;
}
function restaurantAssignTable(PDO $db,int $account,int $table): void
{
    $db->prepare('INSERT INTO restaurant_table_assignments(account_id,table_id) VALUES(?,?)')->execute([$account,$table]);$assignment=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO restaurant_active_tables(table_id,account_id,assignment_id) VALUES(?,?,?)')->execute([$table,$account,$assignment]);
    $db->prepare("UPDATE restaurant_tables SET service_state='free',version=version+1 WHERE id=?")->execute([$table]);
}
function restaurantRequest(PDO $db,string $operation,string $key,array $payload,callable $work): int
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('La solicitud no es válida. Recarga la página.');
    $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
    $q=$db->prepare('SELECT payload_hash,result_id FROM restaurant_requests WHERE operation=? AND request_key=?');
    $q->execute([$operation,$key]);
    if($old=$q->fetch()){
        if(!hash_equals($old['payload_hash'],$hash))throw new InvalidArgumentException('Esta solicitud ya se usó con otros datos. Recarga la página.',409);
        return (int)$old['result_id'];
    }
    $id=$work();
    $db->prepare('INSERT INTO restaurant_requests(operation,request_key,payload_hash,result_id) VALUES(?,?,?,?)')->execute([$operation,$key,$hash,$id]);return $id;
}
function restaurantOpenAccount(PDO $db,array $input): int
{
    permissionRequire('restaurante.accounts','create');permissionRequire('restaurante.tables','read');
    $tableId=restaurantInt($input['table_id']??null);$waiter=restaurantInt($input['waiter_id']??null);$guests=restaurantInt($input['guests']??1,1,100);
    $notes=restaurantText($input['notes']??'',500,false);$key=restaurantText($input['request_key']??null,64);
    if(permissionRuntime() && permissionScope('restaurante.accounts','create')==='own' && $waiter!==(int)permissionUser()['id'])throw new InvalidArgumentException('Solo puedes abrir cuentas a tu nombre.',403);
    return restaurantAtomic($db,function()use($db,$input,$tableId,$waiter,$guests,$notes,$key){
        $table=restaurantLockTable($db,$tableId);
        return restaurantRequest($db,'open_account',$key,[$tableId,$waiter,$guests,$notes],function()use($db,$input,$table,$tableId,$waiter,$guests,$notes){
            restaurantVersion($table,$input['table_version']??null);
            $q=$db->prepare('SELECT account_id FROM restaurant_active_tables WHERE table_id=?');$q->execute([$tableId]);
            if($q->fetchColumn())throw new InvalidArgumentException('La mesa ya tiene una cuenta abierta. Recarga para consultarla.',409);
            if($table['service_state']!=='free')throw new InvalidArgumentException('La mesa debe estar libre y limpia antes de abrir su cuenta.');
            if(!in_array($waiter,array_map('intval',array_column(restaurantWaiters($db),'id')),true))throw new InvalidArgumentException('El mesero no está activo o no tiene acceso a esta empresa.');
            if($guests>(int)$table['capacity'])throw new InvalidArgumentException('Los comensales superan la capacidad de esta mesa.');
            $db->prepare('INSERT INTO restaurant_accounts(waiter_id,guests,notes) VALUES(?,?,?)')->execute([$waiter,$guests,$notes]);$id=(int)$db->lastInsertId();
            $db->prepare('UPDATE restaurant_accounts SET name=? WHERE id=?')->execute(['R'.str_pad((string)$id,5,'0',STR_PAD_LEFT),$id]);
            restaurantAssignTable($db,$id,$tableId);restaurantAudit($db,'account_opened',['account_id'=>$id,'table_id'=>$tableId,'waiter_id'=>$waiter,'guests'=>$guests]);return $id;
        });
    });
}
function restaurantChangeService(PDO $db,int $id,array $input): void
{
    permissionRequire('restaurante.tables','edit',$id);
    $state=$input['service_state']??'';if(!in_array($state,['free','reserved','cleaning'],true))throw new InvalidArgumentException('Estado de mesa no válido.');
    $reason=restaurantText($input['reason']??null,255);
    restaurantAtomic($db,function()use($db,$id,$input,$state,$reason){
        $table=restaurantLockTable($db,$id);restaurantVersion($table,$input['version']??null);
        $q=$db->prepare('SELECT account_id FROM restaurant_active_tables WHERE table_id=?');$q->execute([$id]);if($q->fetchColumn())throw new InvalidArgumentException('Una mesa ocupada cambia de estado desde su cuenta.');
        $db->prepare('UPDATE restaurant_tables SET service_state=?,version=version+1 WHERE id=?')->execute([$state,$id]);
        restaurantAudit($db,'table_service_changed',['table_id'=>$id,'before'=>$table['service_state'],'after'=>$state],$reason);
    });
}
function restaurantReleaseTable(PDO $db,int $account,int $table,string $reason,string $state): void
{
    $db->prepare('UPDATE restaurant_table_assignments SET released_at=CURRENT_TIMESTAMP,release_reason=? WHERE account_id=? AND table_id=? AND released_at IS NULL')->execute([$reason,$account,$table]);
    $db->prepare('DELETE FROM restaurant_active_tables WHERE table_id=? AND account_id=?')->execute([$table,$account]);
    $db->prepare('UPDATE restaurant_tables SET service_state=?,version=version+1 WHERE id=?')->execute([$state,$table]);
}
function restaurantAccountAction(PDO $db,int $id,array $input): int
{
    $action=$input['action']??'';$permission=$action==='close_empty_account'?'cancel':'edit';
    permissionRequire('restaurante.accounts',$permission,$id);
    if(!in_array($action,['move_account','attach_table','detach_table','close_empty_account'],true))throw new InvalidArgumentException('Acción no válida.');
    $reason=restaurantText($input['reason']??null,255);
    return restaurantAtomic($db,function()use($db,$id,$input,$action,$reason){
        // Account first, then areas/tables sorted by ID across all account commands.
        $account=restaurantRecord($db,'restaurant_accounts',$id,true);
        $q=$db->prepare('SELECT table_id FROM restaurant_active_tables WHERE account_id=? ORDER BY table_id');$q->execute([$id]);$current=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $target=$action==='close_empty_account'?0:restaurantInt($input['table_id']??null);
        $ids=array_values(array_unique(array_merge($current,$target?[$target]:[])));sort($ids,SORT_NUMERIC);
        $areas=[];foreach($ids as $table){$row=restaurantRecord($db,'restaurant_tables',$table);$areas[(int)$row['area_id']]=true;}
        ksort($areas);foreach(array_keys($areas) as $area)restaurantRecord($db,'restaurant_areas',$area,true);
        $locked=[];foreach($ids as $table)$locked[$table]=restaurantLockTable($db,$table);
        $key=restaurantText($input['request_key']??null,64);
        return restaurantRequest($db,$action,$key,[$id,$target,$reason],function()use($db,$input,$id,$account,$current,$target,$action,$reason,$locked){
            restaurantVersion($account,$input['version']??null);
            if($account['status']!=='open')throw new InvalidArgumentException('La cuenta ya no admite esta operación.',409);
            if($action==='close_empty_account'){
                if($db->query("SHOW TABLES LIKE 'restaurant_account_items'")->fetch()){
                    $q=$db->prepare('SELECT id FROM restaurant_account_items WHERE account_id=? LIMIT 1');$q->execute([$id]);if($q->fetch())throw new InvalidArgumentException('La mesa tiene productos. Usa Facturar para finalizar su consumo.');
                    $q=$db->prepare('SELECT invoice_id FROM restaurant_account_carts WHERE account_id=?');$q->execute([$id]);if($q->fetchColumn())throw new InvalidArgumentException('La cuenta ya tiene una factura relacionada.');
                }
                // Future order phases cannot accidentally use the empty-account shortcut.
                if($db->query("SHOW TABLES LIKE 'restaurant_orders'")->fetch()){
                    $q=$db->prepare('SELECT id FROM restaurant_orders WHERE account_id=? LIMIT 1');$q->execute([$id]);if($q->fetch())throw new InvalidArgumentException('La cuenta tiene pedidos. Debe cerrarse por el circuito de cobro.');
                }
                if($db->query("SHOW TABLES LIKE 'restaurant_account_payments'")->fetch()){
                    $q=$db->prepare('SELECT id FROM restaurant_account_payments WHERE account_id=? LIMIT 1');$q->execute([$id]);if($q->fetch())throw new InvalidArgumentException('La cuenta tiene pagos relacionados. Resuélvelos desde caja.');
                }
                foreach($current as $table)restaurantReleaseTable($db,$id,$table,$reason,'cleaning');
                $db->prepare("UPDATE restaurant_accounts SET status='closed',closed_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?")->execute([$id]);
            }elseif($action==='detach_table'){
                if(!in_array($target,$current,true)||count($current)<2)throw new InvalidArgumentException('Conserva al menos una mesa en la cuenta.');
                $capacity=0;foreach($current as $table)if($table!==$target)$capacity+=(int)$locked[$table]['capacity'];
                if($capacity<(int)$account['guests'])throw new InvalidArgumentException('Las mesas restantes no tienen capacidad para los comensales.');
                restaurantReleaseTable($db,$id,$target,$reason,'cleaning');
            }else{
                $q=$db->prepare('SELECT account_id FROM restaurant_active_tables WHERE table_id=?');$q->execute([$target]);
                if($q->fetchColumn()||$locked[$target]['service_state']!=='free')throw new InvalidArgumentException('Elige una mesa libre y limpia.',409);
                $capacity=(int)$locked[$target]['capacity'];if($action==='attach_table')foreach($current as $table)$capacity+=(int)$locked[$table]['capacity'];
                if($capacity<(int)$account['guests'])throw new InvalidArgumentException('La capacidad de las mesas no alcanza para los comensales.');
                restaurantAssignTable($db,$id,$target);
                if($action==='move_account')foreach($current as $table)restaurantReleaseTable($db,$id,$table,$reason,'cleaning');
            }
            if($action!=='close_empty_account')$db->prepare('UPDATE restaurant_accounts SET version=version+1 WHERE id=?')->execute([$id]);
            restaurantAudit($db,$action,['account_id'=>$id,'table_id'=>$target?:null,'previous_tables'=>$current],$reason);return $id;
        });
    });
}
