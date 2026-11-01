<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';require_once __DIR__.'/geolocation-schema.php';
try{echo 'Respaldo: '.phase15Backup('geolocation').PHP_EOL;installGeolocation(createDatabaseConnection(true));echo "Geolocalización de clientes y ventas instalada.\n";}
catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
