<?php
declare(strict_types=1);
require_once __DIR__.'/access-repository.php';

function authPassword(mixed $password): string
{
    if(!is_string($password)||mb_strlen($password,'UTF-8')<12||strlen($password)>72||str_contains($password,"\0")) {
        throw new InvalidArgumentException('La contraseña debe tener al menos 12 caracteres y no ser demasiado larga. Puedes usar una frase.');
    }
    return $password;
}

function authUserIsAdministrator(PDO $db,int $id): bool
{
    $query=$db->prepare("SELECT 1 FROM auth_users u JOIN auth_user_groups ug ON ug.user_id=u.id JOIN access_groups g ON g.id=ug.group_id
        WHERE u.id=? AND u.active=1 AND g.active=1 AND g.system_key='administrator' LIMIT 1");
    $query->execute([$id]);return (bool)$query->fetchColumn();
}

function loadAuthUser(PDO $db,int $id): ?array
{
    $query=$db->prepare('SELECT id,name,login,email,contact_id,active,must_change_password,auth_version,version,last_login_at FROM auth_users WHERE id=?');
    $query->execute([$id]);$user=$query->fetch();if(!$user)return null;
    $query=$db->prepare('SELECT g.id,g.name,g.active,g.system_key FROM access_groups g JOIN auth_user_groups ug ON ug.group_id=g.id WHERE ug.user_id=? ORDER BY g.name,g.id');
    $query->execute([$id]);$user['groups']=$query->fetchAll();$user['group_ids']=array_map(static fn($g)=>(int)$g['id'],$user['groups']);
    $user['administrator']=authUserIsAdministrator($db,$id);
    return $user;
}

function authLockState(PDO $db): array
{
    $state=$db->query('SELECT * FROM auth_state WHERE id=1 FOR UPDATE')->fetch();
    if(!$state)throw new RuntimeException('Autenticación no instalada.');return $state;
}

function authRequireAdminActor(PDO $db,int $actor,string $action='edit'): void
{
    $user=loadAuthUser($db,$actor);
    if(!$user||!$user['active']||!accessPermissionAllows(resolveAccessGroupPermissions($db,$user['group_ids']),'ajustes.users.'.$action))throw new InvalidArgumentException('La cuenta ya no tiene autorización para administrar usuarios.',403);
}

function validateAuthUser(PDO $db,array $input,?array $existing=null): array
{
    $name=$input['name']??'';$login=$input['login']??'';$email=$input['email']??'';
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>160)throw new InvalidArgumentException('Escribe un nombre de entre 1 y 160 caracteres.');
    if(!is_string($login)||!preg_match('/^[a-z0-9._@+\-]{3,120}$/D',strtolower(trim($login))))throw new InvalidArgumentException('El usuario debe tener entre 3 y 120 caracteres: letras sin acentos, números, punto, guion o @.');
    if(!is_string($email)||strlen($email)>160||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('Escribe un correo electrónico válido.');
    $active=$input['active']??'0';if(!in_array($active,['0','1',0,1],true))throw new InvalidArgumentException('El estado del usuario no es válido.');
    $contact=$input['contact_id']??'';$contactId=$contact===''||$contact===null?null:accessId($contact);
    if($contact!==''&&$contact!==null&&!$contactId)throw new InvalidArgumentException('Selecciona un contacto válido.');
    if($contactId) {
        $query=$db->prepare('SELECT id,active FROM contacts WHERE id=?');$query->execute([$contactId]);$record=$query->fetch();
        if(!$record||(!$record['active']&&(int)($existing['contact_id']??0)!==$contactId))throw new InvalidArgumentException('Selecciona un contacto activo.');
    }
    $groups=$input['groups']??[];if(!is_array($groups)||!array_is_list($groups))throw new InvalidArgumentException('Selecciona grupos válidos.');
    $ids=[];
    foreach($groups as $group) {
        $id=accessId($group);if(!$id||isset($ids[$id]))throw new InvalidArgumentException('Selecciona grupos válidos, sin duplicados.');
        $query=$db->prepare('SELECT active FROM access_groups WHERE id=?');$query->execute([$id]);$groupActive=$query->fetchColumn();
        if($groupActive===false||(!$groupActive&&!in_array($id,$existing['group_ids']??[],true)))throw new InvalidArgumentException('No puedes asignar un grupo archivado o inexistente.');
        $ids[$id]=$id;
    }
    sort($ids,SORT_NUMERIC);
    return ['name'=>trim($name),'login'=>strtolower(trim($login)),'email'=>trim($email),'contact_id'=>$contactId,'active'=>(int)$active,'groups'=>$ids];
}

function authEnsureAdministratorRemains(PDO $db): void
{
    $count=$db->query("SELECT COUNT(DISTINCT u.id) FROM auth_users u JOIN auth_user_groups ug ON ug.user_id=u.id
        JOIN access_groups g ON g.id=ug.group_id WHERE u.active=1 AND g.active=1 AND g.system_key='administrator'")->fetchColumn();
    if(!(int)$count)throw new InvalidArgumentException('Debe quedar al menos un usuario Administrador activo.');
}

function saveAuthUser(PDO $db,array $input,?int $id,?int $version,int $actor,?string $creationKey=null): int
{
    if(!$id&&(!$creationKey||!preg_match('/^[a-f0-9]{64}$/D',$creationKey)))throw new InvalidArgumentException('La solicitud de creación no es válida.');
    $db->beginTransaction();
    try {
        authLockState($db);authRequireAdminActor($db,$actor,$id?'edit':'create');
        if(!$id) {
            $query=$db->prepare('SELECT id FROM auth_users WHERE creation_key=?');$query->execute([$creationKey]);
            if($old=$query->fetchColumn()){$db->commit();return (int)$old;}
        }
        $existing=$id?loadAuthUser($db,$id):null;
        if($id&&(!$existing||(int)$existing['version']!==$version))throw new InvalidArgumentException('El usuario cambió en otra ventana. Recarga antes de guardar.',409);
        $data=validateAuthUser($db,$input,$existing);
        if($id) {
            $oldGroups=$existing['group_ids'];sort($oldGroups,SORT_NUMERIC);
            $invalidate=$data['login']!==$existing['login']||$data['active']!==(int)$existing['active']
                ||$data['contact_id']!==($existing['contact_id']===null?null:(int)$existing['contact_id'])||$oldGroups!==$data['groups'];
            $db->prepare('UPDATE auth_users SET name=?,login=?,email=?,contact_id=?,active=?,version=version+1,auth_version=auth_version+? WHERE id=?')
                ->execute([$data['name'],$data['login'],$data['email'],$data['contact_id'],$data['active'],(int)$invalidate,$id]);
        } else {
            $password=authPassword($input['password']??null);
            if(!is_string($input['password_confirmation']??null)||!hash_equals($password,$input['password_confirmation']))throw new InvalidArgumentException('Las contraseñas no coinciden.');
            $db->prepare('INSERT INTO auth_users(name,login,email,contact_id,active,password_hash,creation_key) VALUES(?,?,?,?,?,?,?)')
                ->execute([$data['name'],$data['login'],$data['email'],$data['contact_id'],$data['active'],password_hash($password,PASSWORD_DEFAULT),$creationKey]);
            $id=(int)$db->lastInsertId();
        }
        $db->prepare('DELETE FROM auth_user_groups WHERE user_id=?')->execute([$id]);
        $query=$db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)');foreach($data['groups'] as $group)$query->execute([$id,$group]);
        authEnsureAdministratorRemains($db);$db->commit();return $id;
    } catch(Throwable $exception) {
        if($db->inTransaction())$db->rollBack();
        if($exception instanceof PDOException&&($exception->errorInfo[1]??null)===1062)throw new InvalidArgumentException('El usuario de acceso o el contacto relacionado ya está utilizado por otra cuenta.');
        throw $exception;
    }
}

function prepareFirstAdministrator(PDO $db,int $now=0): string
{
    $now=$now?:time();$db->beginTransaction();
    try {
        $state=authLockState($db);
        if($state['bootstrapped_at']!==null||(int)$db->query('SELECT COUNT(*) FROM auth_users')->fetchColumn()>0)throw new InvalidArgumentException('La primera cuenta ya fue creada.');
        $token=bin2hex(random_bytes(32));
        $db->prepare('UPDATE auth_state SET bootstrap_hash=?,bootstrap_expires=? WHERE id=1')->execute([hash('sha256',$token),$now+1800]);
        $db->commit();return $token;
    }catch(Throwable $exception){if($db->inTransaction())$db->rollBack();throw $exception;}
}

function authBootstrapValid(PDO $db,string $hash,int $now=0): bool
{
    $state=$db->query('SELECT bootstrap_hash,bootstrap_expires,bootstrapped_at FROM auth_state WHERE id=1')->fetch();
    return $state&&$state['bootstrapped_at']===null&&is_string($state['bootstrap_hash'])
        &&hash_equals($state['bootstrap_hash'],$hash)&&(int)$state['bootstrap_expires']>=($now?:time());
}

function createFirstAdministrator(PDO $db,array $input,string $tokenHash,int $now=0): int
{
    $now=$now?:time();$password=authPassword($input['password']??null);
    if(!is_string($input['password_confirmation']??null)||!hash_equals($password,$input['password_confirmation']))throw new InvalidArgumentException('Las contraseñas no coinciden.');
    $db->beginTransaction();
    try {
        authLockState($db);
        if(!authBootstrapValid($db,$tokenHash,$now)||(int)$db->query('SELECT COUNT(*) FROM auth_users')->fetchColumn()>0)throw new InvalidArgumentException('El enlace de activación venció o ya se utilizó.',403);
        $admin=$db->query("SELECT id FROM access_groups WHERE system_key='administrator' AND active=1")->fetchColumn();
        if(!$admin)throw new RuntimeException('El grupo Administrador no está instalado.');
        $data=validateAuthUser($db,['name'=>$input['name']??null,'login'=>$input['login']??null,'email'=>$input['email']??'', 'active'=>'1','groups'=>[(int)$admin]]);
        $db->prepare('INSERT INTO auth_users(name,login,email,active,password_hash,must_change_password) VALUES(?,?,?,1,?,0)')
            ->execute([$data['name'],$data['login'],$data['email'],password_hash($password,PASSWORD_DEFAULT)]);
        $id=(int)$db->lastInsertId();$db->prepare('INSERT INTO auth_user_groups(user_id,group_id) VALUES(?,?)')->execute([$id,$admin]);
        $db->prepare('UPDATE auth_state SET bootstrap_hash=NULL,bootstrap_expires=NULL,bootstrapped_at=? WHERE id=1')->execute([$now]);
        $db->commit();return $id;
    }catch(Throwable $exception){if($db->inTransaction())$db->rollBack();throw $exception;}
}

function resetAuthUserPassword(PDO $db,int $id,int $version,int $actor,mixed $password,mixed $confirmation): void
{
    $password=authPassword($password);if(!is_string($confirmation)||!hash_equals($password,$confirmation))throw new InvalidArgumentException('Las contraseñas no coinciden.');
    $db->beginTransaction();
    try {
        authLockState($db);authRequireAdminActor($db,$actor,'password');
        $query=$db->prepare('UPDATE auth_users SET password_hash=?,must_change_password=1,auth_version=auth_version+1,version=version+1 WHERE id=? AND version=?');
        $query->execute([password_hash($password,PASSWORD_DEFAULT),$id,$version]);
        if($query->rowCount()!==1)throw new InvalidArgumentException('El usuario cambió en otra ventana. Recarga antes de restablecer la contraseña.',409);
        $db->commit();
    }catch(Throwable $exception){if($db->inTransaction())$db->rollBack();throw $exception;}
}

function changeAuthPassword(PDO $db,int $id,int $authVersion,mixed $current,mixed $password,mixed $confirmation): void
{
    $password=authPassword($password);if(!is_string($confirmation)||!hash_equals($password,$confirmation))throw new InvalidArgumentException('Las contraseñas no coinciden.');
    $db->beginTransaction();
    try {
        $query=$db->prepare('SELECT password_hash,active,auth_version FROM auth_users WHERE id=? FOR UPDATE');$query->execute([$id]);$user=$query->fetch();
        if(!$user||!$user['active']||(int)$user['auth_version']!==$authVersion)throw new InvalidArgumentException('La sesión venció. Vuelve a iniciar sesión.',403);
        if(!is_string($current)||strlen($current)>72||str_contains($current,"\0")||!password_verify($current,$user['password_hash']))throw new InvalidArgumentException('La contraseña actual no es correcta.');
        if(password_verify($password,$user['password_hash']))throw new InvalidArgumentException('La nueva contraseña debe ser diferente a la actual.');
        $db->prepare('UPDATE auth_users SET password_hash=?,must_change_password=0,auth_version=auth_version+1,version=version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        $db->commit();
    }catch(Throwable $exception){if($db->inTransaction())$db->rollBack();throw $exception;}
}

function authAttemptLogin(PDO $db,mixed $login,mixed $password,string $ip,int $now=0): array
{
    $now=$now?:time();$login=is_string($login)?strtolower(trim($login)):'';
    $password=is_string($password)&&strlen($password)<=72&&!str_contains($password,"\0")?$password:'';
    $state=$db->query('SELECT rate_secret,dummy_password_hash FROM auth_state WHERE id=1')->fetch();
    if(!$state)throw new RuntimeException('Autenticación no instalada.');
    $keys=[hash_hmac('sha256','login:'.substr($login,0,120),$state['rate_secret'])=>10,
        hash_hmac('sha256','ip:'.$ip,$state['rate_secret'])=>30];ksort($keys);
    $db->beginTransaction();
    try {
        $insert=$db->prepare('INSERT IGNORE INTO auth_login_limits(key_hash,window_started) VALUES(?,?)');
        $lock=$db->prepare('SELECT * FROM auth_login_limits WHERE key_hash=? FOR UPDATE');$limits=[];
        foreach($keys as $key=>$maximum) {
            $insert->execute([$key,$now]);$lock->execute([$key]);$limit=$lock->fetch();
            if((int)$limit['window_started']+900<=$now){$limit['failed_count']=0;$limit['window_started']=$now;$limit['blocked_until']=0;}
            if((int)$limit['blocked_until']>$now){$db->commit();return ['user'=>null,'blocked'=>true];}
            $limits[$key]=$limit;
        }
        $query=$db->prepare('SELECT id,password_hash,active,auth_version FROM auth_users WHERE login=? FOR UPDATE');$query->execute([$login]);$user=$query->fetch();
        $valid=password_verify($password,$user['password_hash']??$state['dummy_password_hash'])&&$user&&$user['active'];
        $update=$db->prepare('UPDATE auth_login_limits SET failed_count=?,window_started=?,blocked_until=? WHERE key_hash=?');
        foreach($limits as $key=>$limit) {
            $failures=$valid&&$keys[$key]===10?0:(int)$limit['failed_count']+($valid?0:1);
            $blocked=$failures>=$keys[$key]?$now+900:0;
            $update->execute([$failures,$limit['window_started'],$blocked,$key]);
        }
        if($valid)$db->prepare('UPDATE auth_users SET last_login_at=? WHERE id=?')->execute([$now,$user['id']]);
        $db->commit();$loaded=$valid?loadAuthUser($db,(int)$user['id']):null;
        if($loaded&&(int)$loaded['auth_version']!==(int)$user['auth_version'])$loaded=null;
        return ['user'=>$loaded,'blocked'=>false];
    }catch(Throwable $exception){if($db->inTransaction())$db->rollBack();throw $exception;}
}
