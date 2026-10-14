<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

function testHttpCurl(string $url,?string $cookie=null): CurlHandle
{
    static $defaultCookie=null,$authenticated=[];
    $base=getenv('NEXO_TEST_BASE_URL');$login=getenv('NEXO_TEST_LOGIN');$password=getenv('NEXO_TEST_PASSWORD');$database=getenv('NEXO_DATABASE_NAME');
    if(!$base||!$login||!$password||!$database||!preg_match('/^nexo_auth_test_[a-f0-9]{16}$/D',$database))throw new RuntimeException('Ejecuta esta prueba HTTP con test/run-authenticated.php para usar una cuenta y base de datos aisladas.');
    if(str_starts_with($url,'http://localhost/project'))$url=$base.substr($url,strlen('http://localhost/project'));
    if($cookie===null){
        if($defaultCookie===null){$defaultCookie=tempnam(sys_get_temp_dir(),'auth-regression-');register_shutdown_function(static function()use($defaultCookie){if(is_file($defaultCookie))unlink($defaultCookie);});}
        $cookie=$defaultCookie;
    }
    if(!isset($authenticated[$cookie])) {
        $request=curl_init($base.'/login.php');curl_setopt_array($request,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);
        $body=curl_exec($request);curl_close($request);
        if(!is_string($body)||!preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$token))throw new RuntimeException('No se abrió el login del entorno de prueba.');
        $request=curl_init($base.'/login.php');curl_setopt_array($request,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15,
            CURLOPT_POSTFIELDS=>http_build_query(['csrf'=>$token[1],'login'=>$login,'password'=>$password])]);curl_exec($request);$status=curl_getinfo($request,CURLINFO_RESPONSE_CODE);curl_close($request);
        if($status!==303)throw new RuntimeException('No se autenticó la cuenta de prueba.');$authenticated[$cookie]=true;
    }
    $request=curl_init($url);curl_setopt_array($request,[CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);return $request;
}

function testHttpGet(string $url): string|false
{
    $request=testHttpCurl($url);curl_setopt_array($request,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$body=curl_exec($request);curl_close($request);return $body;
}
