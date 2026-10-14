<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/php/connection.php';
require_once dirname(__DIR__).'/php/auth-schema.php';
require_once dirname(__DIR__).'/php/auth-repository.php';

function startAuthTestServer(): array
{
    $db=createDatabaseConnection();$source=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $name='nexo_auth_test_'.bin2hex(random_bytes(8));
    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$source)||!preg_match('/^nexo_auth_test_[a-f0-9]{16}$/D',$name))throw new RuntimeException('Base de prueba inválida.');
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$server=['database'=>$name,'db'=>$db];
    try {
        $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db->exec('USE `'.$name.'`');$db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table) {
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Tabla inválida.');
            $sql=$db->query('SHOW CREATE TABLE `'.$source.'`.`'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$db->exec($sql);
            // No copiar credenciales, sesiones de activación ni límites de cuentas reales.
            if(!str_starts_with($table,'auth_'))$db->exec('INSERT INTO `'.$table.'` SELECT * FROM `'.$source.'`.`'.$table.'`');
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');installAuthentication($db);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('No se pudo reservar un puerto de prueba.');
        $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
        $server['url']='http://127.0.0.1:'.$port.'/project';$server['log']=tempnam(__DIR__,'.auth-server-log-');
        $server['sessions']=sys_get_temp_dir().'/nexo-auth-sessions-'.bin2hex(random_bytes(8));mkdir($server['sessions'],0700);
        $env=getenv();$env['NEXO_DATABASE_NAME']=$name;
        $server['process']=proc_open([PHP_BINARY,'-d','session.save_path='.$server['sessions'],'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__),__DIR__.'/auth-router.php'],
            [0=>['pipe','r'],1=>['file',$server['log'],'a'],2=>['file',$server['log'],'a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true,'create_new_console'=>false]);
        if(!is_resource($server['process']))throw new RuntimeException('No se pudo iniciar el servidor de prueba.');fclose($pipes[0]);
        for($attempt=0;$attempt<100;$attempt++) {
            $curl=curl_init($server['url'].'/login.php');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>300]);curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            if($code===200)return $server;usleep(100000);
        }
        throw new RuntimeException('El servidor de autenticación no respondió.');
    }catch(Throwable $exception){stopAuthTestServer($server);throw $exception;}
}

function stopAuthTestServer(array $server): void
{
    if(isset($server['process'])&&is_resource($server['process'])){proc_terminate($server['process'],9);proc_close($server['process']);}
    if(isset($server['log'])&&is_file($server['log']))unlink($server['log']);
    if(isset($server['sessions'])&&is_dir($server['sessions'])&&dirname(realpath($server['sessions']))===realpath(sys_get_temp_dir())
        &&preg_match('/^nexo-auth-sessions-[a-f0-9]{16}$/D',basename($server['sessions']))) {
        foreach(glob($server['sessions'].'/sess_*') as $file)if(is_file($file))unlink($file);rmdir($server['sessions']);
    }
    if(isset($server['database'])&&preg_match('/^nexo_auth_test_[a-f0-9]{16}$/D',$server['database'])) {
        $server['db']->exec('USE information_schema');$server['db']->exec('DROP DATABASE IF EXISTS `'.$server['database'].'`');
    }
}

function authTestRequest(array $server,string $path,string $cookie,?array $post=null,string $method='GET'): array
{
    if(getenv('NEXO_AUTH_TRACE'))fwrite(STDERR,($post===null?$method:'POST').' '.parse_url($path,PHP_URL_PATH)."\n");
    $curl=curl_init($server['url'].$path);$headers=[];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15,
        CURLOPT_HEADERFUNCTION=>static function($curl,$line)use(&$headers){$headers[]=$line;return strlen($line);}]);
    if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));elseif($method!=='GET')curl_setopt($curl,CURLOPT_CUSTOMREQUEST,$method);
    $body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$location=curl_getinfo($curl,CURLINFO_REDIRECT_URL);curl_close($curl);
    if(!is_string($body)||preg_match('/Fatal error|Warning(?:<\/b>)?:|Notice(?:<\/b>)?:/',$body))throw new RuntimeException('Error en la consulta de prueba '.$path);
    if(getenv('NEXO_AUTH_TRACE'))fwrite(STDERR,'HTTP '.$code."\n");
    return [$code,$body,$location,$headers];
}

function authTestToken(string $body,string $field='csrf'): string
{
    if(!preg_match('/name="'.preg_quote($field,'/').'" value="([a-f0-9]+)"/',$body,$match))throw new RuntimeException('Falta la protección del formulario de prueba.');
    return $match[1];
}
