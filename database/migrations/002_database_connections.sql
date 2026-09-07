CREATE TABLE IF NOT EXISTS database_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    database_name VARCHAR(191) NOT NULL,
    db_host VARCHAR(253) NOT NULL,
    db_port SMALLINT UNSIGNED NOT NULL DEFAULT 3306,
    db_username VARCHAR(64) NOT NULL,
    encrypted_password LONGTEXT NOT NULL,
    key_version INT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    last_error_code VARCHAR(100) NULL,
    tested_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_database_connection (user_id, account_id, database_name),
    INDEX idx_database_connections_status (account_id, status),
    CONSTRAINT fk_database_connection_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_database_connection_account FOREIGN KEY (account_id) REFERENCES cpanel_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

