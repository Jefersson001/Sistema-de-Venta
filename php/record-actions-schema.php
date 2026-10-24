<?php
declare(strict_types=1);
require_once __DIR__.'/company-schema.php';
require_once __DIR__.'/access-schema.php';
function installRecordActions(PDO $db): void
{
    $existing=array_flip($db->query('SELECT code FROM access_permissions')->fetchAll(PDO::FETCH_COLUMN));
    $db->exec("CREATE TABLE IF NOT EXISTS business_record_actions(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,model_key VARCHAR(90) NOT NULL,action_key VARCHAR(20) NOT NULL,request_key CHAR(64) NOT NULL,payload_hash CHAR(64) NOT NULL,result_json LONGTEXT NOT NULL,details LONGTEXT NOT NULL,UNIQUE KEY record_action_request(request_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    registerCompanyTable($db,'business_record_actions');syncAccessPermissionCatalog($db);
    // Only extend the system administrator; other groups explicitly opt in.
    $q=$db->prepare("INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope) SELECT id,?,'all' FROM access_groups WHERE system_key='administrator'");
    foreach(accessPermissionCatalog() as $code=>$permission)if(!isset($existing[$code])&&in_array($permission['action'],['delete','duplicate'],true))$q->execute([$code]);
}
