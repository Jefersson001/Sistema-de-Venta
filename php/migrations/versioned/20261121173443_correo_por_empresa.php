<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/company-schema.php';
    require_once dirname(__DIR__,2).'/access-schema.php';
    $db->exec("CREATE TABLE IF NOT EXISTS company_mail_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 0,
        smtp_host VARCHAR(253) NOT NULL DEFAULT 'smtp.gmail.com',smtp_port INT NOT NULL DEFAULT 587,
        encryption VARCHAR(12) NOT NULL DEFAULT 'starttls',smtp_user VARCHAR(254) NOT NULL DEFAULT '',
        password_cipher TEXT NULL,from_email VARCHAR(254) NOT NULL DEFAULT '',from_name VARCHAR(160) NOT NULL DEFAULT '',
        reply_to VARCHAR(254) NOT NULL DEFAULT '',version INT UNSIGNED NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    registerCompanyTable($db,'company_mail_settings');
    if(!$db->query("SHOW INDEX FROM company_mail_settings WHERE Key_name='one_mail_per_company'")->fetch())$db->exec('ALTER TABLE company_mail_settings ADD UNIQUE KEY one_mail_per_company(company_id)');
    $db->exec("CREATE TABLE IF NOT EXISTS document_email_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,invoice_id INT UNSIGNED NOT NULL,
        request_key CHAR(64) NOT NULL UNIQUE,payload_hash CHAR(64) NOT NULL,
        recipient VARCHAR(254) NOT NULL,subject VARCHAR(200) NOT NULL,body TEXT NOT NULL,documents_json TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'sending',sent_at DATETIME NULL,
        from_email VARCHAR(254) NOT NULL,error_message VARCHAR(300) NULL,
        FOREIGN KEY(invoice_id) REFERENCES accounting_invoices(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    registerCompanyTable($db,'document_email_log');
    syncAccessPermissionCatalog($db);
    $group=$db->query("SELECT id FROM access_groups WHERE system_key='administrator'")->fetchColumn();
    if($group){$grant=$db->prepare("INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,'all')");foreach(['read','configure'] as $action)$grant->execute([$group,'ajustes.mail.'.$action]);}
};
