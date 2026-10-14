<?php
declare(strict_types=1);
require_once __DIR__.'/access-catalog.php';

function installAccessGroups(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS access_permissions (
        code VARCHAR(110) PRIMARY KEY, module_key VARCHAR(30) NOT NULL, resource_key VARCHAR(90) NOT NULL,
        action_key VARCHAR(30) NOT NULL, label VARCHAR(180) NOT NULL, allows_own TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY resource_action(resource_key,action_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS access_groups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE,
        description VARCHAR(1000) NOT NULL DEFAULT '', active TINYINT(1) NOT NULL DEFAULT 1,
        system_key VARCHAR(40) NULL UNIQUE, creation_key CHAR(64) NULL UNIQUE,
        version INT UNSIGNED NOT NULL DEFAULT 1, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS access_group_permissions (
        group_id INT UNSIGNED NOT NULL, permission_code VARCHAR(110) NOT NULL,
        record_scope ENUM('own','all') NOT NULL DEFAULT 'all', PRIMARY KEY(group_id,permission_code),
        FOREIGN KEY(group_id) REFERENCES access_groups(id), FOREIGN KEY(permission_code) REFERENCES access_permissions(code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->beginTransaction();
    try {
        $query=$db->prepare('INSERT INTO access_permissions(code,module_key,resource_key,action_key,label,allows_own) VALUES(?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE module_key=VALUES(module_key),resource_key=VALUES(resource_key),action_key=VALUES(action_key),label=VALUES(label),allows_own=VALUES(allows_own)');
        foreach(accessPermissionCatalog() as $code=>$p)$query->execute([$code,$p['module'],$p['resource'],$p['action'],$p['label'],(int)$p['allows_own']]);
        $insert=$db->prepare('INSERT IGNORE INTO access_groups(name,description,system_key) VALUES(?,?,?)');
        $find=$db->prepare('SELECT id FROM access_groups WHERE system_key=?');
        $grant=$db->prepare('INSERT INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,?)');
        foreach(accessInitialGroups() as $key=>$group) {
            $find->execute([$key]); if($find->fetchColumn())continue;
            $insert->execute([$group['name'],$group['description'],$key]);
            if($insert->rowCount()!==1) {
                // No sobrescribir un grupo personalizado que ya utilice el nombre inicial.
                $insert->execute([$group['name'].' · '.$key,$group['description'],$key]);
            }
            if($insert->rowCount()!==1)continue;
            $id=(int)$db->lastInsertId();
            foreach($group['permissions'] as $code=>$scope)$grant->execute([$id,$code,$scope]);
        }
        $db->commit();
    } catch(Throwable $exception) { if($db->inTransaction())$db->rollBack(); throw $exception; }
}
