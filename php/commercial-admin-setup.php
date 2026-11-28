<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/commercial-admin-auth.php';
try{
    $token=commercialAdminIssueSetup(createDatabaseConnection(true));
    echo "Enlace de habilitación inicial, válido durante 24 horas y para un solo uso:\n";
    echo authBasePath().'/admin-comercial.php?setup='.$token.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
