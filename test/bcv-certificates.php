<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/php/bcv-provider.php';

function certificateCheck(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$original=getenv('NEXO_BCV_CA_FILE');$temporary=null;
try {
    putenv('NEXO_BCV_CA_FILE');
    $path=bcvCertificateFile();
    certificateCheck(realpath($path)===realpath(dirname(__DIR__).'/php/certificates/bcv-sectigo.pem'),'No se utiliza el bundle versionado.');
    $pem=file_get_contents($path);
    certificateCheck(!str_contains($pem,'PRIVATE KEY'),'El bundle contiene una clave privada.');
    preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',$pem,$matches);
    certificateCheck(count($matches[0])===2,'El bundle debe contener dos autoridades, sin certificado final del sitio.');
    $names=['Sectigo Public Server Authentication CA DV R36','Sectigo Public Server Authentication Root R46'];
    $hashes=['8c54c334b66ba4e426772af4a3f9136c19a1aec729fdb28c535c07a5a4ef22e0',
        '7bb647a62aeeac88bf257aa522d01ffea395e0ab45c73f93f65654ec38f25a06'];
    foreach ($matches[0] as $index=>$certificate) {
        $parsed=openssl_x509_parse($certificate);
        certificateCheck($parsed!==false && $parsed['subject']['CN']===$names[$index],'Autoridad inesperada.');
        certificateCheck(openssl_x509_fingerprint($certificate,'sha256')===$hashes[$index],'Cambió la huella del certificado público.');
        certificateCheck(str_contains($parsed['extensions']['basicConstraints']??'','CA:TRUE'),'El certificado no es una autoridad CA.');
        certificateCheck($parsed['validFrom_time_t']<=time() && $parsed['validTo_time_t']>time(),'La autoridad no está vigente.');
    }
    $root=openssl_pkey_get_public($matches[0][1]);
    certificateCheck($root!==false && openssl_x509_verify($matches[0][0],$root)===1,'La raíz no verifica la firma del intermedio.');
    certificateCheck(openssl_x509_verify($matches[0][1],$root)===1,'No se verifica la firma de la raíz.');
    $temporary=tempnam(sys_get_temp_dir(),'vent-as-bcv-ca-');
    file_put_contents($temporary,$pem);
    putenv('NEXO_BCV_CA_FILE='.$temporary);
    certificateCheck(bcvCertificateFile()===$temporary,'No se respeta el archivo explícito.');
    putenv('NEXO_BCV_CA_FILE='.$temporary.'.missing');
    $rejected=false;
    try { bcvCertificateFile(); } catch (RuntimeException $e) { $rejected=str_contains($e->getMessage(),'No se puede leer'); }
    certificateCheck($rejected,'Un archivo configurado inexistente debe producir un error claro.');
    echo "PASS: bundle público versionado, huellas SHA-256, autoridades vigentes, firmas, selección explícita y error por archivo inexistente.\n";
} finally {
    putenv($original===false?'NEXO_BCV_CA_FILE':'NEXO_BCV_CA_FILE='.$original);
    if ($temporary && is_file($temporary)) unlink($temporary);
}
