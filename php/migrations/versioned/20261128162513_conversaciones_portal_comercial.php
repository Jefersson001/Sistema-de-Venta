<?php
declare(strict_types=1);

return static function (PDO $db): void {
    // Global commercial identities; these are not operational company tables.
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_chat_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id BIGINT UNSIGNED NOT NULL,
        sender_type VARCHAR(12) NOT NULL,
        sender_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sender_name VARCHAR(160) NOT NULL,
        body TEXT NOT NULL,
        client_key CHAR(32) NULL,
        event_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY commercial_chat_retry(request_id,sender_type,sender_id,client_key),
        UNIQUE KEY commercial_chat_event(event_id),
        KEY commercial_chat_request(request_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_chat_reads (
        request_id BIGINT UNSIGNED NOT NULL,
        reader_type VARCHAR(12) NOT NULL,
        reader_id BIGINT UNSIGNED NOT NULL,
        last_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY(request_id,reader_type,reader_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Preserve the previous shared history, never private notes. Safe on retries.
    $db->exec("INSERT IGNORE INTO commercial_chat_messages(request_id,sender_type,sender_id,sender_name,body,event_id,created_at)
        SELECT request_id,'system',0,'Konta',CONCAT(status,IF(message='','',CONCAT(': ',message))),id,created_at
        FROM commercial_demo_events ORDER BY id");
    // Implementar cambios idempotentes: MySQL puede haber confirmado DDL antes de un fallo.
    // Para nuevas tablas de empresa, terminar con registerCompanyTable($db, 'nombre_tabla').
    // Una vez aplicada, conservar esta migración sin editarla; agregar otra para cambios nuevos.
};
