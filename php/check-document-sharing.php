<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

// Read-only diagnostics. Never print or replace the encryption key.
$failed=false;
$report=static function(bool $ok,string $message)use(&$failed):void{echo ($ok?'OK: ':'REVISAR: ').$message.PHP_EOL;if(!$ok)$failed=true;};
$encoded=getenv('NEXO_MAIL_ENCRYPTION_KEY');
if($encoded!==false&&$encoded!==''){
    $key=base64_decode($encoded,true);
    $report($key!==false&&strlen($key)===32,'NEXO_MAIL_ENCRYPTION_KEY debe contener una clave base64 de 32 bytes.');
}else{
    $path=__DIR__.'/runtime/mail-key.php';
    if(is_file($path)){
        $value=@file_get_contents($path);$valid=is_string($value)&&preg_match('~/\* ([A-Za-z0-9+/=]+) \*/$~D',$value,$m)&&strlen(base64_decode($m[1],true)?:'')===32;
        $report((bool)$valid,'Clave local existente legible y válida. Conservarla; no generar otra si hay datos cifrados.');
        $report(is_writable($path),'El proceso debe poder abrir la clave local en lectura y escritura.');
    }else $report(false,'No hay variable de cifrado ni archivo de clave local. Configurar una clave persistente en Dokploy.');
}
$report(extension_loaded('openssl')&&in_array('aes-256-gcm',openssl_get_cipher_methods(),true),'OpenSSL con AES-256-GCM.');
$autoload=dirname(__DIR__).'/vendor/autoload.php';
try{if(is_file($autoload))require_once $autoload;$report(class_exists('Dompdf\\Dompdf'),'Generador PDF instalado con Composer.');}
catch(Throwable){$report(false,'No se pudieron cargar las dependencias de Composer.');}
foreach(['mbstring','dom','pdo_mysql'] as $extension)$report(extension_loaded($extension),'Extensión PHP '.$extension.'.');
$report(is_writable(sys_get_temp_dir()),'Directorio temporal de PHP accesible.');
$public=getenv('NEXO_PUBLIC_URL')?:'';$parts=parse_url($public);
$report(filter_var($public,FILTER_VALIDATE_URL)!==false&&($parts['scheme']??'')==='https','NEXO_PUBLIC_URL definida con una dirección HTTPS.');
echo 'Este diagnóstico usa el usuario y las variables de la consola. Comprobar también las variables y permisos del proceso PHP-FPM.'.PHP_EOL;
exit($failed?1:0);
