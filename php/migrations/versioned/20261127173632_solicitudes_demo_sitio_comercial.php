<?php
declare(strict_types=1);

return static function (PDO $db): void {
    // These prospects belong to the product's public website, not to a customer company.
    // Keep them global and administrator-only; do not register them as company tables.
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_demo_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_key CHAR(64) NOT NULL,payload_hash CHAR(64) NOT NULL,
        full_name VARCHAR(160) NOT NULL,business_name VARCHAR(180) NOT NULL,
        email VARCHAR(254) NOT NULL,phone VARCHAR(40) NOT NULL,
        sector VARCHAR(40) NOT NULL,city VARCHAR(120) NOT NULL DEFAULT '',
        company_count VARCHAR(10) NOT NULL,user_count VARCHAR(10) NOT NULL,
        plan_key VARCHAR(24) NOT NULL,contact_method VARCHAR(16) NOT NULL,
        requirements TEXT NOT NULL,consented_at DATETIME NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'new',internal_notes TEXT NOT NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY commercial_demo_request_key(request_key),
        KEY commercial_demo_status_date(status,created_at),KEY commercial_demo_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS commercial_demo_rate_limits (
        bucket CHAR(64) PRIMARY KEY,window_started DATETIME NOT NULL,hits INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
