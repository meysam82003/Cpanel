ALTER TABLE deployments
    ADD COLUMN recovery_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER queue_job_id;

ALTER TABLE deployments
    ADD COLUMN last_recovery_at DATETIME NULL AFTER recovery_attempts;

ALTER TABLE deployments
    ADD COLUMN rollback_notification_id BIGINT UNSIGNED NULL AFTER notification_id;

ALTER TABLE deployments
    ADD COLUMN backup_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER backup_size;

CREATE INDEX idx_deploy_recovery ON deployments (status, recovery_attempts, updated_at);
