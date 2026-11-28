<?php
declare(strict_types=1);

return static function (PDO $db): void {
    // Platform prospect accounts are independent of ERP users and operating companies.
    // They grant no company access and must not be registered as company tables.
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_portal_accounts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(160) NOT NULL,business_name VARCHAR(180) NOT NULL,
        email VARCHAR(254) NOT NULL,phone VARCHAR(40) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,active TINYINT NOT NULL DEFAULT 1,
        auth_version INT UNSIGNED NOT NULL DEFAULT 1,consented_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY commercial_portal_email(email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $columns=$db->query('SHOW COLUMNS FROM commercial_demo_requests')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('portal_account_id',$columns,true))$db->exec('ALTER TABLE commercial_demo_requests ADD COLUMN portal_account_id BIGINT UNSIGNED NULL');
    if(!in_array('public_message',$columns,true))$db->exec("ALTER TABLE commercial_demo_requests ADD COLUMN public_message TEXT NULL");
    $indexes=$db->query('SHOW INDEX FROM commercial_demo_requests')->fetchAll(PDO::FETCH_ASSOC);
    if(!in_array('commercial_demo_owner',array_column($indexes,'Key_name'),true))$db->exec('ALTER TABLE commercial_demo_requests ADD KEY commercial_demo_owner(portal_account_id,created_at)');
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_demo_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,request_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL,message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY commercial_demo_event_request(request_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Implementar cambios idempotentes: MySQL puede haber confirmado DDL antes de un fallo.
    // Para nuevas tablas de empresa, terminar con registerCompanyTable($db, 'nombre_tabla').
    // Una vez aplicada, conservar esta migración sin editarla; agregar otra para cambios nuevos.
};
