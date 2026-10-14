<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(!is_string($path)||!str_starts_with($path,'/project/')){http_response_code(404);return;}
$relative=rawurldecode(substr($path,9));
if(str_contains($relative,'..')||str_contains($relative,'\\')||preg_match('~^(?:\.git|\.codex|\.agents|php|html|test|docs)(?:/|$)~i',$relative)
    ||preg_match('~(?:^|/)\.|\.(?:sql|md|env|log|bak|ini|json|txt)$~i',$relative)){http_response_code(403);return;}
$file=dirname(__DIR__).'/'.$relative;
if(!is_file($file)){http_response_code(404);return;}
error_log($_SERVER['REQUEST_METHOD'].' '.$path);
$_SERVER['SCRIPT_NAME']=$path;$_SERVER['SCRIPT_FILENAME']=$file;
if(pathinfo($file,PATHINFO_EXTENSION)==='php'){require $file;return;}
$types=['css'=>'text/css','js'=>'application/javascript','png'=>'image/png','svg'=>'image/svg+xml','jpg'=>'image/jpeg','webp'=>'image/webp'];
header('Content-Type: '.($types[pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));readfile($file);
