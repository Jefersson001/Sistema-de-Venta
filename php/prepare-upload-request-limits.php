<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/upload-request-limits.php';
try{
    if($argc!==3)throw new RuntimeException('Uso: php prepare-upload-request-limits.php nginx.conf php-fpm.conf');
    prepareUploadRequestLimits($argv[1],$argv[2]);
    echo "Cargas: Nginx/PHP 32 MB por solicitud; 10 MB por archivo; máximo 20 archivos.\n";
}catch(Throwable $error){fwrite(STDERR,'Límites de carga: '.$error->getMessage().PHP_EOL);exit(1);}
