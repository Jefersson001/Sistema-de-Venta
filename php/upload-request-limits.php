<?php
declare(strict_types=1);

/** Infrastructure envelope; application validators retain 10 MiB/file and 20 MiB/batch. */
function uploadNginxConfiguration(string $source): string
{
    // Tokenize directives: comments, quoted strings and ${variables} must not become blocks.
    preg_match_all('~\#[^\r\n]*|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|\$\{[^}]*\}|[{};]|[^\s{};"\'\#]+~', $source, $matches, PREG_OFFSET_CAPTURE);
    $stack=[];$statement=[];$edits=[];$httpOpening=null;$httpLimit=false;
    foreach($matches[0] as [$token,$offset]){
        if(str_starts_with($token,'#'))continue;
        if($token==='{'){
            $block=$statement[0][0]??'';
            if($block==='http'&&!$stack){
                if($httpOpening!==null)throw new RuntimeException('La configuración Nginx contiene más de un bloque http.');
                $httpOpening=$offset+1;
            }
            $stack[]=$block;$statement=[];
        }elseif($token==='}'){
            if(!$stack)throw new RuntimeException('Los bloques de Nginx no están equilibrados.');
            array_pop($stack);$statement=[];
        }elseif($token===';'){
            if(($statement[0][0]??'')==='client_max_body_size'&&in_array('http',$stack,true)&&in_array(end($stack),['http','server','location'],true)){
                $begin=$statement[0][1];$edits[]=[$begin,$offset+1-$begin,'client_max_body_size 32m;'];
                if($stack===['http'])$httpLimit=true;
            }
            $statement=[];
        }else $statement[]=[$token,$offset];
    }
    if($stack||$httpOpening===null)throw new RuntimeException('No se encontró una configuración Nginx completa con bloque http.');
    if(!$httpLimit)$edits[]=[$httpOpening,0,"\n    client_max_body_size 32m;"];
    usort($edits,static fn($a,$b)=>$b[0]<=>$a[0]);
    foreach($edits as [$offset,$length,$value])$source=substr_replace($source,$value,$offset,$length);
    return $source;
}

function uploadFpmConfiguration(string $source): string
{
    if(!preg_match('/^\[www\][ \t]*(?:;[^\r\n]*)?\r?\n/m',$source,$header,PREG_OFFSET_CAPTURE)){
        throw new RuntimeException('No se encontró el pool [www] de PHP-FPM.');
    }
    $begin=$header[0][1]+strlen($header[0][0]);$rest=substr($source,$begin);
    $length=preg_match('/^\[/m',$rest,$next,PREG_OFFSET_CAPTURE)?$next[0][1]:strlen($rest);
    $body=substr($rest,0,$length);
    $body=preg_replace('/^[ \t]*php_(?:admin_)?value\[(?:upload_max_filesize|post_max_size|max_file_uploads)\][ \t]*=[^\r\n]*(?:\r?\n|$)/mi','',$body);
    $body=preg_replace('/^[ \t]*; NEXO: upload request limits[^\r\n]*(?:\r?\n|$)/m','',$body);
    $body=rtrim($body)."\n; NEXO: upload request limits (managed at startup)\n"
        ."php_admin_value[upload_max_filesize] = 10M\n"
        ."php_admin_value[post_max_size] = 32M\n"
        ."php_admin_value[max_file_uploads] = 20\n";
    return substr($source,0,$begin).$body.substr($rest,$length);
}

function prepareUploadRequestLimits(string $nginx,string $fpm): void
{
    $nginxSource=@file_get_contents($nginx);$fpmSource=@file_get_contents($fpm);
    if($nginxSource===false||$fpmSource===false)throw new RuntimeException('No se pudieron leer las configuraciones de Nginx y PHP-FPM.');
    // Validate both before modifying either file; never start with a silently skipped limit.
    $nginxUpdated=uploadNginxConfiguration($nginxSource);$fpmUpdated=uploadFpmConfiguration($fpmSource);
    foreach([[$nginx,$nginxUpdated],[$fpm,$fpmUpdated]] as [$file,$contents]){
        if(@file_put_contents($file,$contents,LOCK_EX)!==strlen($contents))throw new RuntimeException('No se pudo configurar el límite de carga en '.$file.'.');
    }
}
