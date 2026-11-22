<?php
declare(strict_types=1);

return static function (PDO $db): void {
    if(!$db->query("SHOW COLUMNS FROM company_mail_settings LIKE 'payment_details'")->fetch())$db->exec('ALTER TABLE company_mail_settings ADD COLUMN payment_details TEXT NULL');
};
