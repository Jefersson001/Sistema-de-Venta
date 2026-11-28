<?php
declare(strict_types=1);

return static function (PDO $db): void {
    // Platform commercial administrators are neither ERP users nor client-company data.
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_admin_accounts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,
        email VARCHAR(254) NOT NULL,password_hash VARCHAR(255) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1,auth_version INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY commercial_admin_email(email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_admin_setup (
        id TINYINT UNSIGNED PRIMARY KEY,token_hash CHAR(64) NULL,expires_at BIGINT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec('INSERT IGNORE INTO commercial_admin_setup(id) VALUES(1)');
    $columns=$db->query('SHOW COLUMNS FROM commercial_demo_requests')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('updated_by_commercial_admin',$columns,true))$db->exec('ALTER TABLE commercial_demo_requests ADD COLUMN updated_by_commercial_admin BIGINT UNSIGNED NULL');
    // Implementar cambios idempotentes: MySQL puede haber confirmado DDL antes de un fallo.
    // Para nuevas tablas de empresa, terminar con registerCompanyTable($db, 'nombre_tabla').
    // Una vez aplicada, conservar esta migración sin editarla; agregar otra para cambios nuevos.
};
