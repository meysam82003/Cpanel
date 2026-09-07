CREATE TABLE IF NOT EXISTS telegram_updates (
    update_id BIGINT NOT NULL PRIMARY KEY,
    status VARCHAR(20) NOT NULL DEFAULT 'processing',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    error_code VARCHAR(100) NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_telegram_update_cleanup (processed_at, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
