ALTER TABLE download_tokens
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'queued' AFTER filename;

ALTER TABLE download_tokens
    ADD COLUMN job_id BIGINT UNSIGNED NULL AFTER status;

ALTER TABLE download_tokens
    ADD COLUMN preparation_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER job_id;

ALTER TABLE download_tokens
    ADD COLUMN prepared_path VARCHAR(1024) NULL AFTER preparation_token;

ALTER TABLE download_tokens
    ADD COLUMN prepared_size BIGINT UNSIGNED NULL AFTER prepared_path;

ALTER TABLE download_tokens
    ADD COLUMN prepared_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER prepared_size;

ALTER TABLE download_tokens
    ADD COLUMN prepared_content_type VARCHAR(191) NULL AFTER prepared_sha256;

ALTER TABLE download_tokens
    ADD COLUMN prepared_at DATETIME NULL AFTER prepared_content_type;

ALTER TABLE download_tokens
    ADD COLUMN last_error_code VARCHAR(100) NULL AFTER prepared_at;

CREATE INDEX idx_download_job ON download_tokens (job_id);
