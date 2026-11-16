<?php
declare(strict_types=1);

return static function (PDO $db): void {
    // Access groups are global. Only the standard direct-sales group is updated;
    // custom groups and existing, deliberately configured scopes are preserved.
    $db->exec("INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope)
        SELECT id,'contactos.contacts.create','own' FROM access_groups WHERE system_key='direct_sales'");
};
