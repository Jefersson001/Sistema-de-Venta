<?php
declare(strict_types=1);

return static function (PDO $db): void {
    if(!$db->query("SHOW COLUMNS FROM document_share_links LIKE 'message_only'")->fetch())$db->exec('ALTER TABLE document_share_links ADD COLUMN message_only TINYINT NOT NULL DEFAULT 0');
    if(!$db->query("SHOW COLUMNS FROM document_share_links LIKE 'content_json'")->fetch())$db->exec('ALTER TABLE document_share_links ADD COLUMN content_json TEXT NULL');
};
