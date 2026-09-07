ALTER TABLE deployments
    ADD COLUMN rollback_path VARCHAR(1024) NULL AFTER backup_ref,
    ADD COLUMN destination_existed TINYINT(1) NOT NULL DEFAULT 0 AFTER rollback_path;

CREATE INDEX idx_deploy_rollback_path ON deployments (account_id, rollback_path(191));
