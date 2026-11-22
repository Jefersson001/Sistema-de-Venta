<?php
declare(strict_types=1);
require_once __DIR__.'/connection.php';
require_once __DIR__.'/permissions.php';

function companyMailKey(): string {
    $encoded=getenv('NEXO_MAIL_ENCRYPTION_KEY');
    if($encoded!==false&&$encoded!==''){$key=base64_decode($encoded,true);if($key===false||strlen($key)!==32)throw new RuntimeException('La clave de cifrado del correo no es válida.');return $key;}
    $path=__DIR__.'/runtime/mail-key.php';
    if(!is_dir(dirname($path))&&!@mkdir(dirname($path),0700,true))throw new RuntimeException('No se pudo preparar la clave del correo.');
    $file=@fopen($path,'c+b');if(!$file)throw new RuntimeException('No se pudo abrir la clave del correo.');
    try{if(!flock($file,LOCK_EX))throw new RuntimeException('No se pudo bloquear la clave del correo.');$value=stream_get_contents($file);if($value===''){$value="<?php http_response_code(404); exit; /* ".base64_encode(random_bytes(32))." */";if(fwrite($file,$value)!==strlen($value)||!fflush($file))throw new RuntimeException('No se pudo guardar la clave.');@chmod($path,0600);}if(!preg_match('~/\* ([A-Za-z0-9+/=]+) \*/$~D',$value,$m))throw new RuntimeException('La clave local del correo no es válida.');$key=base64_decode($m[1],true);if($key===false||strlen($key)!==32)throw new RuntimeException('La clave local del correo no es válida.');return $key;}finally{flock($file,LOCK_UN);fclose($file);}
}
function companyMailEncrypt(string $password,int $company): string {
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($password,'aes-256-gcm',companyMailKey(),OPENSSL_RAW_DATA,$iv,$tag,'company:'.$company);
    if($cipher===false)throw new RuntimeException('No se pudo proteger la credencial.');return base64_encode($iv.$tag.$cipher);
}
function companyMailDecrypt(string $cipher,int $company): string {
    $data=base64_decode($cipher,true);if($data===false||strlen($data)<29)throw new RuntimeException('Vuelve a configurar la credencial del correo.');
    $value=openssl_decrypt(substr($data,28),'aes-256-gcm',companyMailKey(),OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16),'company:'.$company);
    if($value===false)throw new RuntimeException('No se pudo recuperar la credencial. Revisa la clave de cifrado del servidor.');return $value;
}
function loadCompanyMail(PDO $db,int $company,bool $secret=false): array {
    $q=$db->prepare('SELECT * FROM company_mail_settings WHERE company_id=?');$q->execute([$company]);$row=$q->fetch()?:['enabled'=>0,'smtp_host'=>'smtp.gmail.com','smtp_port'=>587,'encryption'=>'starttls','smtp_user'=>'','from_email'=>'','from_name'=>'','reply_to'=>'','version'=>0,'password_cipher'=>null];
    $row['has_password']=!empty($row['password_cipher']);if(!$secret)unset($row['password_cipher']);return $row;
}
function mailText(mixed $value,int $max,bool $multiline=false): string {
    if(!is_string($value)||mb_strlen($value)>$max||str_contains($value,"\0")||(!$multiline&&preg_match('/[\r\n]/',$value)))throw new InvalidArgumentException('Revisa el formato y la longitud de los datos del correo.');return trim($value);
}
function mailAddress(mixed $value,bool $optional=false): string {
    $value=mailText($value,254);if(($value===''&&$optional)||filter_var($value,FILTER_VALIDATE_EMAIL))return $value;throw new InvalidArgumentException('Escribe una dirección de correo válida.');
}
function saveCompanyMail(PDO $db,int $company,array $input): void {
    permissionRequire('ajustes.mail','configure');
    $host=mailText($input['smtp_host']??'',253);if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]*$/D',$host))throw new InvalidArgumentException('Escribe el nombre del servidor SMTP, sin http ni rutas.');
    $port=filter_var($input['smtp_port']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if(!$port)throw new InvalidArgumentException('Puerto SMTP no válido.');
    $encryption=$input['encryption']??'';if(!in_array($encryption,['starttls','ssl'],true))throw new InvalidArgumentException('Selecciona STARTTLS o TLS/SSL.');
    $user=mailText($input['smtp_user']??'',254);$from=mailAddress($input['from_email']??'');$name=mailText($input['from_name']??'',160);$reply=mailAddress($input['reply_to']??'',true);
    $password=$input['smtp_password']??'';if(!is_string($password)||strlen($password)>1024||str_contains($password,"\0"))throw new InvalidArgumentException('Credencial no válida.');
    $enabled=($input['enabled']??'')==='1';
    $db->beginTransaction();
    try{
        // Lock a stable parent even before a settings row exists.
        $q=$db->prepare('SELECT id FROM business_company WHERE id=? FOR UPDATE');$q->execute([$company]);if(!$q->fetchColumn())throw new InvalidArgumentException('Empresa no disponible.');
        $current=loadCompanyMail($db,$company,true);if((int)($input['version']??-1)!==(int)$current['version'])throw new InvalidArgumentException('La configuración cambió. Recarga antes de guardar.',409);
        $cipher=$current['password_cipher'];
        if($cipher&&$password===''&&($input['remove_password']??'')!=='1'&&($current['smtp_host']!==$host||(int)$current['smtp_port']!==$port||$current['smtp_user']!==$user||$current['encryption']!==$encryption))throw new InvalidArgumentException('Al cambiar el servidor o usuario SMTP, introduce de nuevo la credencial.');
        if(($input['remove_password']??'')==='1')$cipher=null;
        if($password!=='')$cipher=companyMailEncrypt($password,$company);
        if($enabled&&(!$cipher||$user===''))throw new InvalidArgumentException('Para habilitar el correo, indica el usuario SMTP y una credencial de aplicación.');
        $db->prepare('INSERT INTO company_mail_settings(company_id,enabled,smtp_host,smtp_port,encryption,smtp_user,password_cipher,from_email,from_name,reply_to) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),smtp_host=VALUES(smtp_host),smtp_port=VALUES(smtp_port),encryption=VALUES(encryption),smtp_user=VALUES(smtp_user),password_cipher=VALUES(password_cipher),from_email=VALUES(from_email),from_name=VALUES(from_name),reply_to=VALUES(reply_to),version=version+1')->execute([$company,(int)$enabled,$host,$port,$encryption,$user,$cipher,$from,$name,$reply]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function companyMailer(array $settings,int $company): \PHPMailer\PHPMailer\PHPMailer {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    if(!$settings['enabled']||empty($settings['password_cipher']))throw new InvalidArgumentException('Configura y habilita el correo de esta empresa en Ajustes → Correo saliente.');
    $mail=new \PHPMailer\PHPMailer\PHPMailer(true);$mail->isSMTP();$mail->Host=$settings['smtp_host'];$mail->Port=(int)$settings['smtp_port'];$mail->SMTPSecure=$settings['encryption']==='ssl'?'ssl':'tls';$mail->SMTPAuth=true;$mail->Username=$settings['smtp_user'];$mail->Password=companyMailDecrypt($settings['password_cipher'],$company);$mail->CharSet='UTF-8';$mail->Timeout=20;$mail->Timelimit=30;$mail->SMTPDebug=0;
    $tls=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false];if($ca=getenv('NEXO_SMTP_CA_FILE'))$tls['cafile']=$ca;$mail->SMTPOptions=['ssl'=>$tls];
    $mail->setFrom($settings['from_email'],$settings['from_name']);if($settings['reply_to']!=='')$mail->addReplyTo($settings['reply_to']);return $mail;
}
