<?php
declare(strict_types=1);

function installBusinessSettings(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS business_company (
        id TINYINT UNSIGNED PRIMARY KEY,
        name VARCHAR(160) NOT NULL DEFAULT '', legal_name VARCHAR(180) NOT NULL DEFAULT '',
        tax_id VARCHAR(40) NOT NULL DEFAULT '', address VARCHAR(1000) NOT NULL DEFAULT '',
        phone VARCHAR(40) NOT NULL DEFAULT '', email VARCHAR(160) NOT NULL DEFAULT '', website VARCHAR(200) NOT NULL DEFAULT '',
        logo_data MEDIUMBLOB NULL, logo_mime VARCHAR(30) NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT company_single_business CHECK (id=1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec('INSERT IGNORE INTO business_company(id) VALUES(1)');
}
