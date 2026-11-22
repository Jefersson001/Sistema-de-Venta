<?php
declare(strict_types=1);

return static function (PDO $db): void {
    if(!$db->query("SHOW COLUMNS FROM company_mail_settings LIKE 'message_template'")->fetch()){
        $db->exec('ALTER TABLE company_mail_settings ADD COLUMN message_template TEXT NULL');
    }
};
