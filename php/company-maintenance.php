<?php
declare(strict_types=1);
require_once __DIR__.'/connection.php';

/** Old seed scripts assume company 1. Never let them mix multi-company data. */
function companyGuardLegacyInstaller(): void
{
    $db=createDatabaseConnection(true);
    if(!$db->query("SHOW TABLES LIKE 'company_schema_state'")->fetch())return;
    $ids=$db->query('SELECT id FROM business_company')->fetchAll(PDO::FETCH_COLUMN);
    if(count($ids)!==1||(int)$ids[0]!==1) {
        fwrite(STDERR,"Este instalador anterior a multiempresa solo admite la empresa inicial. Usa una migración explícita de esquema y registerCompanyTable(); no se realizaron cambios.\n");
        exit(1);
    }
}
