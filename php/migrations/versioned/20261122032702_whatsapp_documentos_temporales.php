<?php
declare(strict_types=1);

return static function (PDO $db): void {
    require_once dirname(__DIR__,2).'/company-schema.php';require_once dirname(__DIR__,2).'/access-schema.php';
    $db->exec("CREATE TABLE IF NOT EXISTS company_whatsapp_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 0,
        country_code VARCHAR(3) NOT NULL DEFAULT '58',expiry_days INT UNSIGNED NOT NULL DEFAULT 7,
        message_template TEXT NOT NULL,version INT UNSIGNED NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    registerCompanyTable($db,'company_whatsapp_settings');
    if(!$db->query("SHOW INDEX FROM company_whatsapp_settings WHERE Key_name='one_whatsapp_company'")->fetch())$db->exec('ALTER TABLE company_whatsapp_settings ADD UNIQUE KEY one_whatsapp_company(company_id)');
    $db->exec("CREATE TABLE IF NOT EXISTS document_share_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,origin_kind VARCHAR(16) NOT NULL,origin_id INT UNSIGNED NOT NULL,
        invoice_id INT UNSIGNED NULL,sale_id INT UNSIGNED NULL,operation_id INT UNSIGNED NULL,
        request_key CHAR(64) NOT NULL UNIQUE,payload_hash CHAR(64) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,token_cipher TEXT NOT NULL,
        recipient_phone VARCHAR(15) NOT NULL,message_text TEXT NOT NULL,base_url VARCHAR(500) NOT NULL,
        company_name VARCHAR(180) NOT NULL,expires_at DATETIME NOT NULL,revoked_at DATETIME NULL,
        FOREIGN KEY(invoice_id) REFERENCES accounting_invoices(id),FOREIGN KEY(sale_id) REFERENCES sales_quotations(id),
        FOREIGN KEY(operation_id) REFERENCES inventory_operations(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    registerCompanyTable($db,'document_share_links');
    $db->exec("CREATE TABLE IF NOT EXISTS document_share_files (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,share_id INT UNSIGNED NOT NULL,
        document_key VARCHAR(40) NOT NULL,label VARCHAR(240) NOT NULL,filename VARCHAR(240) NOT NULL,
        pdf_data MEDIUMBLOB NOT NULL,UNIQUE KEY share_document(share_id,document_key),
        FOREIGN KEY(share_id) REFERENCES document_share_links(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    registerCompanyTable($db,'document_share_files');syncAccessPermissionCatalog($db);
    $group=$db->query("SELECT id FROM access_groups WHERE system_key='administrator'")->fetchColumn();if($group){$q=$db->prepare("INSERT IGNORE INTO access_group_permissions(group_id,permission_code,record_scope) VALUES(?,?,'all')");foreach(['read','configure'] as $action)$q->execute([$group,'ajustes.whatsapp.'.$action]);}
};
