<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';
require_once __DIR__.'/auth-repository.php';
try {
    $token=prepareFirstAdministrator(createDatabaseConnection());
    echo "Abre este enlace en el navegador del negocio para elegir tu usuario y contraseña. Vence en 30 minutos y solo se utiliza una vez:\n";
    echo 'http://localhost/project/activar.php?token='.$token."\n";
}catch(Throwable $exception){fwrite(STDERR,$exception->getMessage()."\n");exit(1);}
