<?php
declare(strict_types=1);
require_once __DIR__.'/company-schema.php';

function installContactAttachments(PDO $db): void
{
    if (!$db->query("SHOW TABLES LIKE 'company_schema_tables'")->fetch()) throw new RuntimeException('Instala primero la base multiempresa.');
    $db->exec("CREATE TABLE IF NOT EXISTS contact_attachments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        storage_key CHAR(64) NOT NULL,
        filename VARCHAR(200) NOT NULL,
        description VARCHAR(200) NOT NULL DEFAULT '',
        mime_type VARCHAR(100) NOT NULL,
        byte_size INT UNSIGNED NOT NULL,
        INDEX contact_attachment_parent(contact_id),
        FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    installCompanyTable($db, 'contact_attachments');
    $tables = $db->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN);
    installCompanyTriggers($db, 'contact_attachments', $tables);
}
