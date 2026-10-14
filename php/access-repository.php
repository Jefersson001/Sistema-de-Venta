<?php
declare(strict_types=1);
require_once __DIR__.'/access-catalog.php';

function accessId(mixed $value): ?int
{
    if(!is_string($value)&&!is_int($value))return null;
    $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    return $id===false?null:$id;
}

function loadAccessGroup(PDO $db,int $id): ?array
{
    $query=$db->prepare('SELECT * FROM access_groups WHERE id=?');$query->execute([$id]);$group=$query->fetch();
    if(!$group)return null;
    $query=$db->prepare('SELECT permission_code,record_scope FROM access_group_permissions WHERE group_id=? ORDER BY permission_code');
    $query->execute([$id]);$group['permissions']=$query->fetchAll(PDO::FETCH_KEY_PAIR);
    return $group;
}

function validateAccessGroup(array $input,?array $existing=null): array
{
    $name=$input['name']??'';$description=$input['description']??'';
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>120)throw new InvalidArgumentException('Escribe un nombre de grupo de entre 1 y 120 caracteres.');
    if(!is_string($description)||mb_strlen($description,'UTF-8')>1000)throw new InvalidArgumentException('La descripción admite hasta 1000 caracteres.');
    if(isset($input['active'])&&!in_array($input['active'],['0','1',0,1],true))throw new InvalidArgumentException('El estado del grupo no es válido.');
    $active=isset($input['active'])&&(string)$input['active']==='1';
    $selected=$input['permissions']??[];$scopes=$input['scopes']??[];$catalog=accessPermissionCatalog();$resources=accessResources();
    if(!is_array($selected)||!array_is_list($selected)||!is_array($scopes))throw new InvalidArgumentException('La matriz de permisos no es válida.');
    foreach($scopes as $resource=>$scope) {
        if(!isset($resources[$resource])||!in_array($scope,['own','all'],true)||($scope==='own'&&!$resources[$resource]['allows_own'])) {
            throw new InvalidArgumentException('El alcance de registros no es válido.');
        }
    }
    $permissions=[];
    foreach($selected as $code) {
        if(!is_string($code)||!isset($catalog[$code])||isset($permissions[$code]))throw new InvalidArgumentException('Selecciona únicamente permisos válidos, sin duplicados.');
        $p=$catalog[$code];
        if($p['allows_own']&&!isset($scopes[$p['resource']]))throw new InvalidArgumentException('Selecciona Propios o Todos para cada sección habilitada.');
        $permissions[$code]=$scopes[$p['resource']]??'all';
    }
    foreach($permissions as $code=>$scope) {
        $p=$catalog[$code];
        if(!isset($permissions[$p['resource'].'.read']))throw new InvalidArgumentException('Activa Consultar en cada sección donde permitas realizar acciones.');
    }
    if(($existing['system_key']??null)==='administrator') {
        if(!$active)throw new InvalidArgumentException('El grupo Administrador del sistema debe permanecer activo.');
        foreach(accessAdministratorMinimum() as $code)if(($permissions[$code]??null)!=='all')throw new InvalidArgumentException('El grupo Administrador conserva los permisos para administrar Ajustes, usuarios y grupos.');
    }
    return ['name'=>trim($name),'description'=>trim($description),'active'=>(int)$active,'permissions'=>$permissions];
}

function saveAccessGroup(PDO $db,array $input,?int $id,?int $version,?string $creationKey=null): int
{
    if($creationKey!==null&&!preg_match('/^[a-f0-9]{64}$/D',$creationKey))throw new InvalidArgumentException('La solicitud de creación no es válida.');
    $db->beginTransaction();
    try {
        if(!$id&&$creationKey) {
            $query=$db->prepare('SELECT id FROM access_groups WHERE creation_key=?');$query->execute([$creationKey]);
            if($old=$query->fetchColumn()) {$db->commit();return (int)$old;}
        }
        $existing=null;
        if($id) {
            $query=$db->prepare('SELECT id FROM access_groups WHERE id=? FOR UPDATE');$query->execute([$id]);
            $existing=loadAccessGroup($db,$id);
            if(!$existing||(int)$existing['version']!==$version)throw new InvalidArgumentException('El grupo cambió en otra ventana. Recarga antes de guardar.',409);
        }
        $data=validateAccessGroup($input,$existing);
        if($id) $db->prepare('UPDATE access_groups SET name=?,description=?,active=?,version=version+1 WHERE id=?')->execute([$data['name'],$data['description'],$data['active'],$id]);
        else {
            $db->prepare('INSERT INTO access_groups(name,description,active,creation_key) VALUES(?,?,?,?)')->execute([$data['name'],$data['description'],$data['active'],$creationKey]);
            $id=(int)$db->lastInsertId();
        }
        $db->prepare('DELETE FROM access_group_permissions WHERE group_id=?')->execute([$id]);
        $query=$db->prepare('INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,?)');
        foreach($data['permissions'] as $code=>$scope)$query->execute([$id,$code,$scope]);
        $db->commit();return $id;
    } catch(Throwable $exception) {
        if($db->inTransaction())$db->rollBack();
        if($exception instanceof PDOException&&($exception->errorInfo[1]??null)===1062) {
            if(!$id&&$creationKey) {$query=$db->prepare('SELECT id FROM access_groups WHERE creation_key=?');$query->execute([$creationKey]);if($old=$query->fetchColumn())return (int)$old;}
            throw new InvalidArgumentException('Ya existe un grupo con ese nombre.');
        }
        throw $exception;
    }
}

function resolveAccessGroupPermissions(PDO $db,array $groupIds): array
{
    $ids=[];
    foreach($groupIds as $value) {$id=accessId($value);if(!$id)throw new InvalidArgumentException('El grupo de acceso no es válido.');$ids[$id]=$id;}
    if(!$ids)return [];
    $query=$db->prepare('SELECT p.permission_code,p.record_scope FROM access_group_permissions p JOIN access_groups g ON g.id=p.group_id
        WHERE g.active=1 AND g.id IN('.implode(',',array_fill(0,count($ids),'?')).')');
    $query->execute(array_values($ids));$result=[];$catalog=accessPermissionCatalog();
    foreach($query->fetchAll() as $p) {
        $code=$p['permission_code'];$scope=$p['record_scope'];
        if(!isset($catalog[$code])||!in_array($scope,['own','all'],true)||($scope==='own'&&!$catalog[$code]['allows_own']))continue;
        if(!isset($result[$code])||$scope==='all')$result[$code]=$scope;
    }
    return $result;
}

function accessPermissionScope(array $permissions,string $code): ?string
{
    $catalog=accessPermissionCatalog();$definition=$catalog[$code]??null;
    if(!$definition)return null;
    $scope=$permissions[$code]??null;$read=$permissions[$definition['resource'].'.read']??null;
    if(!in_array($scope,['own','all'],true)||!in_array($read,['own','all'],true))return null;
    if(($scope==='own'||$read==='own')&&!$definition['allows_own'])return null;
    return $scope==='own'||$read==='own'?'own':'all';
}

function accessPermissionAllows(array $permissions,string $code,?int $assignedSellerId=null,?int $userContactId=null): bool
{
    $scope=accessPermissionScope($permissions,$code);
    return $scope==='all'||($scope==='own'&&$assignedSellerId!==null&&$userContactId!==null
        &&$assignedSellerId>0&&$assignedSellerId===$userContactId);
}
