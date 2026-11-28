<?php
declare(strict_types=1);
require_once __DIR__.'/commercial-portal-auth.php';

function commercialAdminForget(): void {
    authStartSession();foreach(['commercial_admin_id','commercial_admin_version','commercial_admin_started','commercial_admin_seen','commercial_admin_setup_hash'] as $key)unset($_SESSION[$key]);
    session_regenerate_id(true);$_SESSION['auth_csrf']=bin2hex(random_bytes(32));
}
function commercialAdminSession(array $account): void {
    commercialAdminForget();$_SESSION['commercial_admin_id']=(int)$account['id'];$_SESSION['commercial_admin_version']=(int)$account['auth_version'];
    $_SESSION['commercial_admin_started']=$_SESSION['commercial_admin_seen']=time();
}
function commercialAdminCurrent(): ?array {
    authStartSession();$id=filter_var($_SESSION['commercial_admin_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)return null;
    if(time()-(int)($_SESSION['commercial_admin_seen']??0)>1800||time()-(int)($_SESSION['commercial_admin_started']??0)>28800){commercialAdminForget();return null;}
    $q=createDatabaseConnection(true)->prepare('SELECT id,name,email,active,auth_version FROM commercial_admin_accounts WHERE id=?');$q->execute([$id]);$user=$q->fetch();
    if(!$user||!(int)$user['active']||(int)$user['auth_version']!==(int)($_SESSION['commercial_admin_version']??0)){commercialAdminForget();return null;}
    $_SESSION['commercial_admin_seen']=time();return $user;
}
function commercialAdminRequire(): array {
    authPrivacyHeaders();
    try{$user=commercialAdminCurrent();}catch(Throwable $e){error_log('Administración comercial: '.$e->getMessage());authStop(503,'No pudimos comprobar el acceso comercial. Inténtalo más tarde.');}
    if(!$user){header('Location: '.authBasePath().'/admin-comercial.php',true,303);exit;}
    return $user;
}
function commercialAdminLogin(PDO $db,array $input,string $ip): array {
    commercialPortalLimit($db,'admin-login-ip',$ip,30);$email=commercialPortalEmail($input['email']??'');commercialPortalLimit($db,'admin-login-email',$email,10);
    $password=$input['password']??'';if(!is_string($password)||strlen($password)>72||str_contains($password,"\0"))$password='';
    $q=$db->prepare('SELECT id,password_hash,active,auth_version FROM commercial_admin_accounts WHERE email=?');$q->execute([$email]);$user=$q->fetch();
    $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $valid=password_verify($password,$user['password_hash']??$dummy);
    if(!$valid||!$user||!(int)$user['active'])throw new InvalidArgumentException('El correo o la contraseña no son correctos.',401);
    if(password_needs_rehash($user['password_hash'],PASSWORD_DEFAULT))$db->prepare('UPDATE commercial_admin_accounts SET password_hash=? WHERE id=? AND password_hash=?')->execute([password_hash($password,PASSWORD_DEFAULT),$user['id'],$user['password_hash']]);
    return $user;
}
function commercialAdminIssueSetup(PDO $db): string {
    $token=bin2hex(random_bytes(32));$db->beginTransaction();
    try{
        $db->query('SELECT id FROM commercial_admin_setup WHERE id=1 FOR UPDATE')->fetch();
        if($db->query('SELECT id FROM commercial_admin_accounts LIMIT 1 FOR UPDATE')->fetchColumn())throw new InvalidArgumentException('La administración comercial ya tiene una cuenta. No se habilita otro registro inicial.');
        $db->prepare('UPDATE commercial_admin_setup SET token_hash=?,expires_at=? WHERE id=1')->execute([hash('sha256',$token),time()+86400]);$db->commit();return $token;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function commercialAdminSetupValid(PDO $db,mixed $hash): bool {
    if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return false;
    $q=$db->prepare('SELECT token_hash,expires_at FROM commercial_admin_setup WHERE id=1');$q->execute();$state=$q->fetch();
    return $state&&is_string($state['token_hash'])&&hash_equals($state['token_hash'],$hash)&&(int)$state['expires_at']>=time()&&!$db->query('SELECT id FROM commercial_admin_accounts LIMIT 1')->fetchColumn();
}
function commercialAdminActivate(PDO $db,array $input,mixed $hash): array {
    $name=commercialText($input['name']??'',160,true);$email=commercialPortalEmail($input['email']??'');$password=authPassword($input['password']??null);
    if(!is_string($input['password_confirmation']??null)||!hash_equals($password,$input['password_confirmation']))throw new InvalidArgumentException('Las contraseñas no coinciden.');
    $encoded=password_hash($password,PASSWORD_DEFAULT);$db->beginTransaction();
    try{
        $db->query('SELECT id FROM commercial_admin_setup WHERE id=1 FOR UPDATE')->fetch();
        if(!commercialAdminSetupValid($db,$hash))throw new InvalidArgumentException('El enlace de habilitación no es válido o ya venció.',403);
        $db->prepare('INSERT INTO commercial_admin_accounts(name,email,password_hash) VALUES(?,?,?)')->execute([$name,$email,$encoded]);$id=(int)$db->lastInsertId();
        $db->exec('UPDATE commercial_admin_setup SET token_hash=NULL,expires_at=NULL WHERE id=1');$db->commit();return ['id'=>$id,'auth_version'=>1];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function commercialAdminChangePassword(PDO $db,int $id,array $input): array {
    $password=authPassword($input['password']??null);if(!is_string($input['password_confirmation']??null)||!hash_equals($password,$input['password_confirmation']))throw new InvalidArgumentException('Las contraseñas no coinciden.');
    $q=$db->prepare('SELECT password_hash,auth_version,active FROM commercial_admin_accounts WHERE id=?');$q->execute([$id]);$user=$q->fetch();$old=$input['current_password']??null;
    if(!$user||!$user['active']||!is_string($old)||strlen($old)>72||!password_verify($old,$user['password_hash']))throw new InvalidArgumentException('La contraseña actual no es correcta.');
    $q=$db->prepare('UPDATE commercial_admin_accounts SET password_hash=?,auth_version=auth_version+1 WHERE id=? AND auth_version=? AND active=1');$q->execute([password_hash($password,PASSWORD_DEFAULT),$id,$user['auth_version']]);
    if(!$q->rowCount())throw new InvalidArgumentException('La cuenta cambió. Inicia sesión otra vez.',409);
    return ['id'=>$id,'auth_version'=>(int)$user['auth_version']+1];
}
