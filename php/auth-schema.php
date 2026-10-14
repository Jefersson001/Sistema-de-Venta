<?php
declare(strict_types=1);

function installAuthentication(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS auth_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL,
        login VARCHAR(120) NOT NULL UNIQUE, email VARCHAR(160) NOT NULL DEFAULT '',
        contact_id INT UNSIGNED NULL UNIQUE, active TINYINT(1) NOT NULL DEFAULT 1,
        password_hash VARCHAR(255) NOT NULL, must_change_password TINYINT(1) NOT NULL DEFAULT 1,
        auth_version INT UNSIGNED NOT NULL DEFAULT 1, version INT UNSIGNED NOT NULL DEFAULT 1,
        creation_key CHAR(64) NULL UNIQUE, last_login_at BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(contact_id) REFERENCES contacts(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS auth_user_groups (
        user_id INT UNSIGNED NOT NULL, group_id INT UNSIGNED NOT NULL,
        PRIMARY KEY(user_id,group_id), FOREIGN KEY(user_id) REFERENCES auth_users(id),
        FOREIGN KEY(group_id) REFERENCES access_groups(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS auth_state (
        id TINYINT UNSIGNED PRIMARY KEY, bootstrap_hash CHAR(64) NULL, bootstrap_expires BIGINT UNSIGNED NULL,
        bootstrapped_at BIGINT UNSIGNED NULL, rate_secret CHAR(64) NOT NULL, dummy_password_hash VARCHAR(255) NOT NULL,
        CONSTRAINT auth_single_state CHECK(id=1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if(!$db->query('SELECT id FROM auth_state WHERE id=1')->fetchColumn()) {
        $db->prepare('INSERT IGNORE INTO auth_state(id,rate_secret,dummy_password_hash) VALUES(1,?,?)')
            ->execute([bin2hex(random_bytes(32)),password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);
    }
    $db->exec("CREATE TABLE IF NOT EXISTS auth_login_limits (
        key_hash CHAR(64) PRIMARY KEY, failed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        window_started BIGINT UNSIGNED NOT NULL DEFAULT 0, blocked_until BIGINT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
