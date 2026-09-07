<?php

declare(strict_types=1);

use App\Core\AppException;
use App\Core\Container;
use App\Database\CpanelDatabaseService;
use App\Database\DataManagerService;
use App\Database\DirectDatabaseConnectionService;
use App\Database\SqlConsoleCoordinator;
use App\Database\SqlConsoleService;
use App\Database\SqlSafetyAnalyzer;
use App\Database\SqlTransferService;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\UploadReceiver;
use App\Plans\PlanGuard;
use App\Queue\QueueService;
use App\Security\ConfirmationService;

return static function (ApiKernel $api, Container $container): void {
    $cpanel = $container->get(CpanelDatabaseService::class);
    $connections = $container->get(DirectDatabaseConnectionService::class);
    $data = $container->get(DataManagerService::class);
    $sql = $container->get(SqlConsoleService::class);
    $sqlCoordinator = $container->get(SqlConsoleCoordinator::class);
    $analyzer = $container->get(SqlSafetyAnalyzer::class);
    $transfers = $container->get(SqlTransferService::class);
    $uploads = $container->get(UploadReceiver::class);
    $plans = $container->get(PlanGuard::class);
    $confirmations = $container->get(ConfirmationService::class);
    $queue = $container->get(QueueService::class);

    $api->route('GET', '/api/v1/hosts/{account}/databases', static function (Request $request, array $params, array $session) use ($cpanel, $plans): array {
        $plans->feature((int) $session['user_id'], 'database_manager');
        return $cpanel->listDatabases((int) $session['user_id'], (int) $params['account']);
    });
    $api->route('POST', '/api/v1/hosts/{account}/databases', static function (Request $request, array $params, array $session) use ($cpanel, $plans): array {
        $plans->feature((int) $session['user_id'], 'database_manager');
        return $cpanel->createDatabase((int) $session['user_id'], (int) $params['account'], (string) $request->input('name', ''));
    }, 30);
    $api->route('DELETE', '/api/v1/hosts/{account}/databases/{database}', static function (Request $request, array $params, array $session) use ($cpanel, $plans, $confirmations): array {
        $userId = (int) $session['user_id'];
        $plans->feature($userId, 'database_manager');
        $target = (string) $params['database'];
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, (int) $params['account'], 'database.delete', $target);
        return $cpanel->deleteDatabase($userId, (int) $params['account'], $target);
    }, 15);

    $api->route('GET', '/api/v1/hosts/{account}/database-users', static function (Request $request, array $params, array $session) use ($cpanel, $plans): array {
        $plans->feature((int) $session['user_id'], 'database_manager');
        return $cpanel->listUsers((int) $session['user_id'], (int) $params['account']);
    });
    $api->route('POST', '/api/v1/hosts/{account}/database-users', static function (Request $request, array $params, array $session) use ($cpanel, $plans): array {
        $plans->feature((int) $session['user_id'], 'database_manager');
        return $cpanel->createUser((int) $session['user_id'], (int) $params['account'], (string) $request->input('name', ''), (string) $request->input('password', ''));
    }, 20);
    $api->route('PATCH', '/api/v1/hosts/{account}/database-users/{user}/password', static function (Request $request, array $params, array $session) use ($cpanel, $plans): array {
        $plans->feature((int) $session['user_id'], 'database_manager');
        return $cpanel->changePassword((int) $session['user_id'], (int) $params['account'], (string) $params['user'], (string) $request->input('password', ''));
    }, 15);
    $api->route('DELETE', '/api/v1/hosts/{account}/database-users/{user}', static function (Request $request, array $params, array $session) use ($cpanel, $plans, $confirmations): array {
        $userId = (int) $session['user_id'];
        $plans->feature($userId, 'database_manager');
        $target = (string) $params['user'];
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, (int) $params['account'], 'database_user.delete', $target);
        return $cpanel->deleteUser($userId, (int) $params['account'], $target);
    }, 15);

    $api->route('GET', '/api/v1/hosts/{account}/database-privileges', static fn (Request $request, array $params, array $session): array => $cpanel->privilegesFor((int) $session['user_id'], (int) $params['account'], (string) $request->input('database', ''), (string) $request->input('user', '')));
    $api->route('PUT', '/api/v1/hosts/{account}/database-privileges', static function (Request $request, array $params, array $session) use ($cpanel): array {
        $privileges = $request->input('privileges', []);
        if (!is_array($privileges)) {
            throw new AppException('Privileges must be a list.', 422, 'invalid_database_privilege', [], 'database.privileges');
        }
        return $cpanel->assign((int) $session['user_id'], (int) $params['account'], (string) $request->input('database', ''), (string) $request->input('user', ''), array_values(array_map('strval', $privileges)));
    });
    $api->route('DELETE', '/api/v1/hosts/{account}/database-privileges', static function (Request $request, array $params, array $session) use ($cpanel, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $database = (string) $request->input('database', '');
        $databaseUser = (string) $request->input('user', '');
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'database.privileges_revoke', $database . ':' . $databaseUser);
        return $cpanel->revoke($userId, $accountId, $database, $databaseUser);
    }, 15);

    $api->route('GET', '/api/v1/hosts/{account}/remote-mysql-hosts', static fn (Request $request, array $params, array $session): array => $cpanel->remoteHosts((int) $session['user_id'], (int) $params['account']));
    $api->route('POST', '/api/v1/hosts/{account}/remote-mysql-hosts', static fn (Request $request, array $params, array $session): array => $cpanel->addRemoteHost((int) $session['user_id'], (int) $params['account'], (string) $request->input('host', '')));
    $api->route('DELETE', '/api/v1/hosts/{account}/remote-mysql-hosts', static function (Request $request, array $params, array $session) use ($cpanel, $confirmations): array {
        $host = (string) $request->input('host', '');
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'database.remote_host_delete', $host);
        return $cpanel->removeRemoteHost((int) $session['user_id'], (int) $params['account'], $host);
    });

    $api->route('GET', '/api/v1/hosts/{account}/database-connections', static fn (Request $request, array $params, array $session): array => ['connections' => $connections->status((int) $session['user_id'], (int) $params['account'])]);
    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/connect', static function (Request $request, array $params, array $session) use ($connections, $plans): array {
        $plans->feature((int) $session['user_id'], 'database_manager');
        return $connections->provision((int) $session['user_id'], (int) $params['account'], (string) $params['database']);
    }, 8);
    $api->route('DELETE', '/api/v1/hosts/{account}/databases/{database}/connect', static function (Request $request, array $params, array $session) use ($connections): array {
        $connections->disable((int) $session['user_id'], (int) $params['account'], (string) $params['database'], filter_var($request->input('remove_user', true), FILTER_VALIDATE_BOOL));
        return ['disabled' => true];
    }, 10);

    $api->route('GET', '/api/v1/hosts/{account}/databases/{database}/tables', static function (Request $request, array $params, array $session) use ($data, $plans): array {
        $plans->feature((int) $session['user_id'], 'database_manager');
        return ['tables' => $data->tables((int) $session['user_id'], (int) $params['account'], (string) $params['database'])];
    });
    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/tables', static function (Request $request, array $params, array $session) use ($data): array {
        $columns = $request->input('columns', []);
        if (!is_array($columns)) {
            throw new AppException('Table columns must be a list.', 422, 'invalid_table_definition');
        }
        $data->createTable((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $request->input('name', ''), array_values($columns), (string) $request->input('engine', 'InnoDB'), (string) $request->input('collation', 'utf8mb4_unicode_ci'));
        return ['created' => true];
    });
    $api->route('GET', '/api/v1/hosts/{account}/databases/{database}/tables/{table}', static fn (Request $request, array $params, array $session): array => $data->structure((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table']));
    $api->route('PATCH', '/api/v1/hosts/{account}/databases/{database}/tables/{table}', static function (Request $request, array $params, array $session) use ($data): array {
        $data->renameTable((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], (string) $request->input('name', ''));
        return ['renamed' => true];
    });
    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/operations', static function (Request $request, array $params, array $session) use ($data, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $database = (string) $params['database'];
        $table = (string) $params['table'];
        $operation = strtoupper((string) $request->input('operation', ''));
        if (in_array($operation, ['DROP', 'TRUNCATE'], true)) {
            $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'table.' . strtolower($operation), $database . '.' . $table);
        }
        return ['result' => $data->tableOperation($userId, $accountId, $database, $table, $operation)];
    }, 20);

    $api->route('GET', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/rows', static function (Request $request, array $params, array $session) use ($data): array {
        $filters = $request->input('filters', []);
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }
        return $data->browse((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], (int) $request->input('page', 1), (int) $request->input('per_page', 50), $request->input('sort') === null ? null : (string) $request->input('sort'), (string) $request->input('direction', 'asc'), is_array($filters) ? $filters : []);
    }, 180);
    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/rows', static function (Request $request, array $params, array $session) use ($data): array {
        $values = $request->input('values', []);
        if (!is_array($values)) {
            throw new AppException('Row values must be an object.', 422, 'empty_row_values');
        }
        return $data->insert((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], $values);
    });
    $api->route('PATCH', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/rows', static function (Request $request, array $params, array $session) use ($data): array {
        $key = $request->input('key', []);
        $values = $request->input('values', []);
        if (!is_array($key) || !is_array($values)) {
            throw new AppException('Row key and values must be objects.', 422, 'empty_row_values');
        }
        return ['affected_rows' => $data->update((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], $key, $values)];
    });
    $api->route('DELETE', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/rows', static function (Request $request, array $params, array $session) use ($data, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $targetPrefix = (string) $params['database'] . '.' . (string) $params['table'];
        $keys = $request->input('keys');
        if (is_array($keys)) {
            $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'row.bulk_delete', $targetPrefix . ':bulk');
            return ['affected_rows' => $data->bulkDelete($userId, $accountId, (string) $params['database'], (string) $params['table'], $keys)];
        }
        $key = $request->input('key', []);
        if (!is_array($key)) {
            throw new AppException('Row primary key is invalid.', 422, 'row_primary_key_required');
        }
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'row.delete', $targetPrefix . ':row');
        return ['affected_rows' => $data->delete($userId, $accountId, (string) $params['database'], (string) $params['table'], $key)];
    });

    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/columns', static function (Request $request, array $params, array $session) use ($data): array {
        $definition = $request->input('definition', []);
        if (!is_array($definition)) {
            throw new AppException('Column definition is invalid.', 422, 'invalid_column_definition');
        }
        $data->addColumn((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], $definition);
        return ['created' => true];
    });
    $api->route('PATCH', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/columns/{column}', static function (Request $request, array $params, array $session) use ($data): array {
        $definition = $request->input('definition', []);
        if (!is_array($definition)) {
            throw new AppException('Column definition is invalid.', 422, 'invalid_column_definition');
        }
        $data->changeColumn((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], (string) $params['column'], $definition);
        return ['updated' => true];
    });
    $api->route('DELETE', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/columns/{column}', static function (Request $request, array $params, array $session) use ($data, $confirmations): array {
        $target = $params['database'] . '.' . $params['table'] . '.' . $params['column'];
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'column.drop', $target);
        $data->dropColumn((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], (string) $params['column']);
        return ['removed' => true];
    });
    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/indexes', static function (Request $request, array $params, array $session) use ($data): array {
        $columns = $request->input('columns', []);
        if (!is_array($columns)) {
            throw new AppException('Index columns must be a list.', 422, 'invalid_index_columns');
        }
        $data->addIndex((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], (string) $request->input('name', ''), array_values(array_map('strval', $columns)), filter_var($request->input('unique', false), FILTER_VALIDATE_BOOL), filter_var($request->input('primary', false), FILTER_VALIDATE_BOOL));
        return ['created' => true];
    });
    $api->route('DELETE', '/api/v1/hosts/{account}/databases/{database}/tables/{table}/indexes/{index}', static function (Request $request, array $params, array $session) use ($data, $confirmations): array {
        $target = $params['database'] . '.' . $params['table'] . '.' . $params['index'];
        $confirmations->consume((string) $request->input('confirmation', ''), (int) $session['user_id'], (int) $params['account'], 'index.drop', $target);
        $data->dropIndex((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $params['table'], (string) $params['index']);
        return ['removed' => true];
    });

    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/sql/analyze', static function (Request $request, array $params, array $session) use ($analyzer, $plans): array {
        $plans->feature((int) $session['user_id'], 'sql_console');
        return $analyzer->analyze((string) $request->input('sql', ''));
    }, 60);
    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/sql', static function (Request $request, array $params, array $session) use ($sql, $sqlCoordinator, $analyzer, $plans, $confirmations): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $database = (string) $params['database'];
        $query = (string) $request->input('sql', '');
        $plans->feature($userId, 'sql_console');
        $analysis = $analyzer->analyze($query);
        $confirmed = false;
        if ($analysis['requires_confirmation']) {
            $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'sql.execute', hash('sha256', $query));
            $confirmed = true;
        }
        if (filter_var($request->input('backup_first', false), FILTER_VALIDATE_BOOL)) {
            return $sqlCoordinator->backupAndExecute($userId, $accountId, $database, $query, $confirmed);
        }
        return $sql->execute($userId, $accountId, $database, $query, $confirmed, filter_var($request->input('save_history', true), FILTER_VALIDATE_BOOL));
    }, 30);
    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/sql/explain', static fn (Request $request, array $params, array $session): array => $sql->explain((int) $session['user_id'], (int) $params['account'], (string) $params['database'], (string) $request->input('sql', '')), 30);
    $api->route('GET', '/api/v1/hosts/{account}/sql/history', static fn (Request $request, array $params, array $session): array => ['history' => $sql->history((int) $session['user_id'], (int) $params['account'], (int) $request->input('limit', 50))]);
    $api->route('GET', '/api/v1/hosts/{account}/sql/saved', static fn (Request $request, array $params, array $session): array => ['queries' => $sql->savedQueries((int) $session['user_id'], (int) $params['account'])]);
    $api->route('POST', '/api/v1/hosts/{account}/sql/saved', static fn (Request $request, array $params, array $session): array => ['id' => $sql->saveQuery((int) $session['user_id'], (int) $params['account'], (string) $request->input('database', ''), (string) $request->input('name', ''), (string) $request->input('sql', ''))]);
    $api->route('DELETE', '/api/v1/hosts/{account}/sql/saved/{query}', static function (Request $request, array $params, array $session) use ($sql): array {
        $sql->deleteSavedQuery((int) $session['user_id'], (int) $params['account'], (int) $params['query']);
        return ['removed' => true];
    });

    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/imports', static function (Request $request, array $params, array $session) use ($uploads, $transfers, $plans): array {
        $userId = (int) $session['user_id'];
        $plans->feature($userId, 'database_manager');
        $entry = $request->files['file'] ?? null;
        if (!is_array($entry)) {
            throw new AppException('Select a SQL, SQL.GZ, or ZIP file.', 422, 'upload_missing', [], 'database.import');
        }
        $plan = $plans->plan($userId);
        $file = $uploads->receive($entry, (int) $plan['max_upload_bytes'], ['sql', 'gz', 'zip']);
        try {
            $job = $transfers->import($userId, (int) $params['account'], (string) $params['database'], $file['path'], filter_var($request->input('backup_first', true), FILTER_VALIDATE_BOOL));
            $file = null;
            return ['job_id' => $job];
        } finally {
            if (is_array($file)) {
                @unlink($file['path']);
            }
        }
    }, 8);
    $api->route('POST', '/api/v1/hosts/{account}/databases/{database}/exports', static function (Request $request, array $params, array $session) use ($transfers, $plans): array {
        $plans->feature((int) $session['user_id'], 'database_manager');
        $tables = $request->input('tables', []);
        if (!is_array($tables)) {
            throw new AppException('Export tables must be a list.', 422, 'invalid_export_table');
        }
        return ['job_id' => $transfers->export((int) $session['user_id'], (int) $params['account'], (string) $params['database'], array_values(array_map('strval', $tables)), (string) $request->input('mode', 'full'), (string) $request->input('compression', 'gz'))];
    }, 15);
    $api->route('GET', '/api/v1/jobs/{job}/download', static function (Request $request, array $params, array $session) use ($queue, $container): Response {
        $status = $queue->status((int) $params['job'], (int) $session['user_id']);
        $filename = is_array($status['result'] ?? null) ? (string) ($status['result']['file'] ?? '') : '';
        if ($status['status'] !== 'completed' || $filename === '' || basename($filename) !== $filename) {
            throw new AppException('This job has no completed downloadable result.', 404, 'job_download_unavailable');
        }
        $path = $container->root . '/storage/downloads/' . $filename;
        $root = realpath($container->root . '/storage/downloads');
        $real = realpath($path);
        if ($root === false || $real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new AppException('The temporary export expired or was already downloaded.', 410, 'job_download_expired', [], 'database.export');
        }
        return Response::download($real, $filename, str_ends_with($filename, '.gz') ? 'application/gzip' : 'application/sql', true);
    }, 20, true);
};
