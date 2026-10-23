<?php
declare(strict_types=1);

const BCV_SOURCE_URL='https://www.bcv.org.ve/';

function bcvParsePublication(string $html): array
{
    if(strlen($html)>2*1024*1024)throw new RuntimeException('La respuesta del BCV supera el tamaño permitido.');
    $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);$xpath=new DOMXPath($dom);
        $value=trim($xpath->query('//*[@id="dolar"]//strong')->item(0)?->textContent??'');
        // BCV publishes Spanish decimal notation, e.g. 1.234,56789000.
        if(!preg_match('/^[0-9.]+,[0-9]{1,8}$/D',$value))throw new RuntimeException('El BCV no devolvió una tasa USD reconocible.');
        $rate=str_replace(['.',','],['','.'],$value);
        if(bccomp($rate,'0',8)<=0||bccomp($rate,'999999999999',8)>0)throw new RuntimeException('La tasa publicada no es válida.');
        $dateNode=$xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," date-display-single ")][@content]')->item(0);
        $date=substr($dateNode?->getAttribute('content')??'',0,10);
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new RuntimeException('El BCV no devolvió una Fecha Valor reconocible.');
        return ['rate'=>bcadd($rate,'0',8),'effective_date'=>$date,'source'=>'bcv','url'=>BCV_SOURCE_URL];
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
}

function bcvFetchPublication(): array
{
    $curl=curl_init(BCV_SOURCE_URL);$html='';
    curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_USERAGENT=>'Nexo/1.0 (consulta de tipo de cambio BCV)',CURLOPT_HTTPHEADER=>['Accept: text/html'],
        CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$html):int{if(strlen($html)+strlen($chunk)>2*1024*1024)return 0;$html.=$chunk;return strlen($chunk);}]);
    $ca=getenv('NEXO_BCV_CA_FILE')?:__DIR__.'/runtime/bcv-ca.pem';if(is_file($ca))curl_setopt($curl,CURLOPT_CAINFO,$ca);
    $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
    if($ok===false||$status!==200)throw new RuntimeException('No se pudo consultar el BCV (HTTP '.$status.'). '.$error);
    return bcvParsePublication($html);
}
