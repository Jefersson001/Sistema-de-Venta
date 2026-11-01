<?php
declare(strict_types=1);
require_once __DIR__.'/company-schema.php';
function installPaymentProofs(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS payment_proofs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_id INT UNSIGNED NULL, treasury_id INT UNSIGNED NULL,
        storage_key CHAR(64) NOT NULL, filename VARCHAR(200) NOT NULL,
        mime_type VARCHAR(100) NOT NULL, byte_size INT UNSIGNED NOT NULL, content_hash CHAR(64) NOT NULL,
        INDEX proof_payment(payment_id), INDEX proof_treasury(treasury_id),
        FOREIGN KEY(payment_id) REFERENCES accounting_payments(id),
        FOREIGN KEY(treasury_id) REFERENCES accounting_treasury_documents(id),
        CHECK ((payment_id IS NOT NULL AND treasury_id IS NULL) OR (payment_id IS NULL AND treasury_id IS NOT NULL))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    installCompanyTable($db,'payment_proofs');
    installCompanyTriggers($db,'payment_proofs',$db->query('SELECT table_name FROM company_schema_tables')->fetchAll(PDO::FETCH_COLUMN));
}
