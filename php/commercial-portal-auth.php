<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/commercial-repository.php';

function commercialPortalEmail(mixed $value): string {
    $email=strtolower(commercialText($value,254,true));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))throw new InvalidArgumentException('Escribe un correo electrónico válido.');
    return $email;
}

// Separate session identity: never establishes an ERP user or company membership.
function commercialPortalUser(): ?array {
    authStartSession();$id=filter_var($_SESSION['portal_account_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)return null;
    if(time()-(int)($_SESSION['portal_seen']??0)>1800||time()-(int)($_SESSION['portal_started']??0)>28800){commercialPortalForget();return null;}
    $q=createDatabaseConnection(true)->prepare('SELECT id,full_name,business_name,email,phone,active,auth_version FROM commercial_portal_accounts WHERE id=?');$q->execute([$id]);$user=$q->fetch();
    if(!$user||!(int)$user['active']||(int)$user['auth_version']!==(int)($_SESSION['portal_auth_version']??0)){commercialPortalForget();return null;}
    $_SESSION['portal_seen']=time();return $user;
}

function commercialPortalForget(): void {
    authStartSession();foreach(['portal_account_id','portal_auth_version','portal_started','portal_seen','commercial_form_keys','portal_success'] as $key)unset($_SESSION[$key]);
    session_regenerate_id(true);$_SESSION['auth_csrf']=bin2hex(random_bytes(32));
}

function commercialPortalSession(array $account): void {
    commercialPortalForget();$_SESSION['portal_account_id']=(int)$account['id'];$_SESSION['portal_auth_version']=(int)$account['auth_version'];
    $_SESSION['portal_started']=$_SESSION['portal_seen']=time();
}

// Shared transactional rate buckets; separate namespaces for signup and sign-in.
function commercialPortalLimit(PDO $db,string $purpose,string $identity,int $maximum): void {
    $bucket=hash('sha256','portal:'.$purpose.':'.$identity);$now=new DateTimeImmutable('now',new DateTimeZone('America/Caracas'));
    $db->beginTransaction();
    try{
        $db->prepare('INSERT INTO commercial_demo_rate_limits(bucket,window_started,hits) VALUES(?,?,0) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket)')->execute([$bucket,$now->format('Y-m-d H:i:s')]);
        $q=$db->prepare('SELECT window_started,hits FROM commercial_demo_rate_limits WHERE bucket=? FOR UPDATE');$q->execute([$bucket]);$row=$q->fetch();
        $expired=$row['window_started']<=$now->modify('-15 minutes')->format('Y-m-d H:i:s');$hits=$expired?0:(int)$row['hits'];
        if($hits>=$maximum)throw new InvalidArgumentException('Demasiados intentos. Espera 15 minutos antes de volver a intentar.',429);
        $db->prepare('UPDATE commercial_demo_rate_limits SET hits=?,window_started=? WHERE bucket=?')->execute([$hits+1,$expired?$now->format('Y-m-d H:i:s'):$row['window_started'],$bucket]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function commercialPortalRegister(PDO $db,array $input,string $ip): array {
    commercialPortalLimit($db,'register',$ip,5);
    if(($input['website']??'')!=='')throw new InvalidArgumentException('No se pudo validar el registro.');
    $name=commercialText($input['full_name']??'',160,true);$business=commercialText($input['business_name']??'',180,true);
    $email=commercialPortalEmail($input['email']??'');$phone=commercialText($input['phone']??'',40,true);
    if(!preg_match('/^\+?[0-9() .-]+$/D',$phone)||!preg_match('/^[0-9]{7,15}$/D',preg_replace('/\D/','',$phone)))throw new InvalidArgumentException('Escribe un teléfono válido.');
    $password=authPassword($input['password']??null);
    if(!is_string($input['password_confirmation']??null)||!hash_equals($password,$input['password_confirmation']))throw new InvalidArgumentException('Las contraseñas no coinciden.');
    if(($input['consent']??null)!=='1')throw new InvalidArgumentException('Acepta el uso de tus datos para gestionar tu cuenta y demostración.');
    try{
        $db->prepare('INSERT INTO commercial_portal_accounts(full_name,business_name,email,phone,password_hash,consented_at) VALUES(?,?,?,?,?,?)')->execute([$name,$business,$email,$phone,password_hash($password,PASSWORD_DEFAULT),(new DateTimeImmutable('now',new DateTimeZone('America/Caracas')))->format('Y-m-d H:i:s')]);
    }catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)throw new InvalidArgumentException('No pudimos crear la cuenta con esos datos. Si ya tienes una cuenta, inicia sesión.');throw $e;}
    return ['id'=>(int)$db->lastInsertId(),'auth_version'=>1];
}

function commercialPortalLogin(PDO $db,array $input,string $ip): array {
    commercialPortalLimit($db,'login-ip',$ip,30);
    $email=commercialPortalEmail($input['email']??'');commercialPortalLimit($db,'login-email',$email,10);
    $password=$input['password']??null;
    if(!is_string($password)||strlen($password)>72||str_contains($password,"\0"))$password='';
    $q=$db->prepare('SELECT id,password_hash,auth_version,active FROM commercial_portal_accounts WHERE email=?');$q->execute([$email]);$account=$q->fetch();
    $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $valid=password_verify($password,$account['password_hash']??$dummy);
    if(!$valid||!$account||!(int)$account['active'])throw new InvalidArgumentException('El correo o la contraseña no son correctos.',401);
    if(password_needs_rehash($account['password_hash'],PASSWORD_DEFAULT))$db->prepare('UPDATE commercial_portal_accounts SET password_hash=? WHERE id=? AND password_hash=?')->execute([password_hash($password,PASSWORD_DEFAULT),$account['id'],$account['password_hash']]);
    return $account;
}
