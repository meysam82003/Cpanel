INSERT INTO plans (slug, name_fa, name_en, host_limit, max_upload_bytes, database_manager, sql_console, backup_enabled, deployment_enabled, daily_operation_limit)
VALUES
    ('free', 'رایگان', 'Free', 1, 10485760, 0, 0, 0, 0, 100),
    ('basic', 'پایه', 'Basic', 3, 26214400, 1, 0, 1, 0, 500),
    ('pro', 'حرفه‌ای', 'Pro', 10, 104857600, 1, 1, 1, 1, 2000),
    ('business', 'کسب‌وکار', 'Business', 50, 536870912, 1, 1, 1, 1, 10000)
ON DUPLICATE KEY UPDATE
    name_fa = VALUES(name_fa),
    name_en = VALUES(name_en),
    host_limit = VALUES(host_limit),
    max_upload_bytes = VALUES(max_upload_bytes),
    database_manager = VALUES(database_manager),
    sql_console = VALUES(sql_console),
    backup_enabled = VALUES(backup_enabled),
    deployment_enabled = VALUES(deployment_enabled),
    daily_operation_limit = VALUES(daily_operation_limit);

