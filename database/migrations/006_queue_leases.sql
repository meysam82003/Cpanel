ALTER TABLE queue_jobs
    ADD COLUMN reservation_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER reserved_at;
