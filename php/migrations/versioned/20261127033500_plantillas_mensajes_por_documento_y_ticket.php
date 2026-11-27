<?php
declare(strict_types=1);

return static function (PDO $db): void {
    foreach(['company_mail_settings','company_whatsapp_settings'] as $table){
        foreach(['order','delivery','invoice'] as $kind){
            $column='message_template_'.$kind;
            if(!$db->query("SHOW COLUMNS FROM $table LIKE '$column'")->fetch())$db->exec("ALTER TABLE $table ADD COLUMN $column TEXT NULL");
        }
    }
    // NULL deliberately inherits the existing company-specific message without overwriting it.
    // Keep the legacy column for older containers during rolling deployments.
};
