ALTER TABLE deployments
    ADD COLUMN package_id BIGINT UNSIGNED NULL AFTER account_id;

ALTER TABLE deployments
    ADD COLUMN queue_job_id BIGINT UNSIGNED NULL AFTER package_id;

ALTER TABLE deployments
    ADD COLUMN rollback_job_id BIGINT UNSIGNED NULL AFTER queue_job_id;

ALTER TABLE deployments
    ADD COLUMN package_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER package_name;

ALTER TABLE deployments
    ADD COLUMN package_metadata_json LONGTEXT NULL AFTER package_checksum;

ALTER TABLE deployments
    ADD COLUMN stage_path VARCHAR(1024) NULL AFTER destination;

ALTER TABLE deployments
    ADD COLUMN switch_state VARCHAR(40) NOT NULL DEFAULT 'none' AFTER stage_path;

ALTER TABLE deployments
    ADD COLUMN backup_size BIGINT UNSIGNED NULL AFTER backup_ref;

ALTER TABLE deployments
    ADD COLUMN reconciliation_json LONGTEXT NULL AFTER error_code;

ALTER TABLE deployments
    ADD COLUMN notification_id BIGINT UNSIGNED NULL AFTER reconciliation_json;

ALTER TABLE deployments
    ADD COLUMN rollback_verified_at DATETIME NULL AFTER rolled_back_at;

CREATE UNIQUE INDEX uq_deploy_queue_job ON deployments (queue_job_id);
CREATE INDEX idx_deploy_package ON deployments (package_id);
CREATE INDEX idx_deploy_rollback_job ON deployments (rollback_job_id);
