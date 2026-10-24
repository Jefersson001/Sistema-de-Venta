<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/connection.php';require_once __DIR__.'/access-schema.php';
try{
    $db=createDatabaseConnection(true);
    $before=$db->query('SELECT group_id,permission_code,record_scope FROM access_group_permissions ORDER BY group_id,permission_code')->fetchAll();
    $result=syncAccessPermissionCatalog($db);
    $after=$db->query('SELECT group_id,permission_code,record_scope FROM access_group_permissions ORDER BY group_id,permission_code')->fetchAll();
    if($before!==$after)throw new RuntimeException('Los permisos de los grupos cambiaron durante la actualización. Revisa si hubo otra edición concurrente.');
    echo 'Catálogo actualizado: '.$result['created'].' permisos registrados, '.$result['updated']." descripciones actualizadas. Los permisos de los grupos se conservaron.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
