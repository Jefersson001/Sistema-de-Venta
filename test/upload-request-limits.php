<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/upload-request-limits.php';
function requestLimitCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function requestLimitReject(callable $work): void {try{$work();}catch(RuntimeException){return;}throw new RuntimeException('Se aceptó una configuración inválida.');}
$nginx=<<<'CONF'
worker_processes 1;
events { worker_connections 1024; }
# http { client_max_body_size 1m; }
http {
    log_format main 'http { } client_max_body_size 9m;';
    root ${document_root};
    server {
        listen 3000;
        client_max_body_size 1m;
        location ~ "file{2}" { client_max_body_size "2m"; }
        location / { try_files $uri $uri/ /index.php; }
    }
}
CONF;
$updated=uploadNginxConfiguration($nginx);
requestLimitCheck(substr_count($updated,'client_max_body_size 32m;')===3,'No se alinearon http, server y location.');
requestLimitCheck(str_contains($updated,"log_format main 'http { } client_max_body_size 9m;';")&&str_contains($updated,'# http { client_max_body_size 1m; }'),'Se modificaron comentarios o cadenas.');
requestLimitCheck(str_contains($updated,'root ${document_root};')&&str_contains($updated,'try_files $uri $uri/ /index.php;'),'Se modificó el enrutamiento.');
requestLimitCheck(uploadNginxConfiguration($updated)===$updated,'Repetir la configuración de Nginx no es idempotente.');
$withHttp=uploadNginxConfiguration('events {} http { client_max_body_size 0; server { listen 3000; } }');
requestLimitCheck(substr_count($withHttp,'client_max_body_size')===1&&str_contains($withHttp,'32m'),'Se duplicó la directiva de http.');
requestLimitReject(fn()=>uploadNginxConfiguration('events {} server { listen 3000; }'));
requestLimitReject(fn()=>uploadNginxConfiguration('http { server {'));
$fpm="[global]\ndaemonize=no\n[www]\nuser=nobody\nlisten=127.0.0.1:9000\nphp_value[post_max_size] = 8M\nphp_admin_value[upload_max_filesize] = 2M\n[another]\nphp_value[post_max_size]=7M\n";
$fpmUpdated=uploadFpmConfiguration($fpm);
$settings=parse_ini_string($fpmUpdated,true,INI_SCANNER_RAW);
requestLimitCheck($settings['www']['php_admin_value']['post_max_size']==='32M'&&$settings['www']['php_admin_value']['upload_max_filesize']==='10M'&&$settings['www']['php_admin_value']['max_file_uploads']==='20','Límites incorrectos en PHP-FPM.');
requestLimitCheck($settings['www']['user']==='nobody'&&$settings['another']['php_value']['post_max_size']==='7M','Se modificaron identidad o pools ajenos.');
requestLimitCheck(uploadFpmConfiguration($fpmUpdated)===$fpmUpdated,'Repetir la configuración PHP-FPM no es idempotente.');
requestLimitReject(fn()=>uploadFpmConfiguration('[global]'));
$root=sys_get_temp_dir().'/nexo-request-limits-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    file_put_contents($root.'/nginx.conf',$nginx);file_put_contents($root.'/php-fpm.conf',$fpm);
    prepareUploadRequestLimits($root.'/nginx.conf',$root.'/php-fpm.conf');
    requestLimitCheck(file_get_contents($root.'/nginx.conf')===$updated&&file_get_contents($root.'/php-fpm.conf')===$fpmUpdated,'No se guardaron las configuraciones.');
    prepareUploadRequestLimits($root.'/nginx.conf',$root.'/php-fpm.conf');
    requestLimitCheck(file_get_contents($root.'/nginx.conf')===$updated,'El segundo arranque alteró la configuración.');
    file_put_contents($root.'/nginx.conf',$nginx);file_put_contents($root.'/php-fpm.conf','[global]');
    requestLimitReject(fn()=>prepareUploadRequestLimits($root.'/nginx.conf',$root.'/php-fpm.conf'));
    requestLimitCheck(file_get_contents($root.'/nginx.conf')===$nginx,'Se modificó Nginx antes de validar PHP-FPM.');
}finally{foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);}
echo "PASS: límite Nginx de 32 MB, PHP-FPM 10/32 MB y 20 archivos, ámbitos, comentarios, enrutamiento, reintento y fallo sin escritura parcial.\n";
