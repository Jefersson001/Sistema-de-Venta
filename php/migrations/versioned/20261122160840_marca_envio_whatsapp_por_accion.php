<?php
declare(strict_types=1);

return static function (PDO $db): void {
    if(!$db->query("SHOW COLUMNS FROM document_share_links LIKE 'marked_sent_at'")->fetch())$db->exec('ALTER TABLE document_share_links ADD COLUMN marked_sent_at DATETIME NULL');
    if(!$db->query("SHOW COLUMNS FROM document_share_links LIKE 'marked_sent_action'")->fetch())$db->exec('ALTER TABLE document_share_links ADD COLUMN marked_sent_action VARCHAR(16) NULL');
};
