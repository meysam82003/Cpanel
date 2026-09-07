<?php

declare(strict_types=1);

use App\Accounts\AccountRepository;
use App\Accounts\AccountService;
use App\Accounts\UserRepository;
use App\Accounts\UserSettingsService;
use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Help\HelpService;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Plans\PlanGuard;
use App\Queue\QueueService;
use App\Security\ConfirmationService;
use App\Security\MiniAppSessionService;
use App\Security\SecurityCenterService;
use App\Security\TelegramInitDataValidator;

return static function (ApiKernel $api, Container $container): void {
    $database = $container->get(Database::class);
    $users = $container->get(UserRepository::class);
    $accounts = $container->get(AccountRepository::class);
    $accountService = $container->get(AccountService::class);
    $sessions = $container->get(MiniAppSessionService::class);
    $validator = $container->get(TelegramInitDataValidator::class);
    $settings = $container->get(UserSettingsService::class);
    $help = $container->get(HelpService::class);
    $security = $container->get(SecurityCenterService::class);
    $plans = $container->get(PlanGuard::class);
    $queue = $container->get(QueueService::class);
    $confirmations = $container->get(ConfirmationService::class);
    $audit = $container->get(AuditLogger::class);
    $translator = $container->get(\App\Core\Translator::class);

    $api->publicRoute('POST', '/api/v1/auth/telegram', static function (Request $request) use ($validator, $users, $sessions, $audit): array {
        $verified = $validator->validate((string) $request->input('init_data', ''));
        $telegramUser = $verified['user'];
        $language = isset($telegramUser['language_code']) && str_starts_with(strtolower((string) $telegramUser['language_code']), 'en') ? 'en' : null;
        $user = $users->upsertTelegram($telegramUser, $language);
        $session = $sessions->create((int) $user['id'], $verified['auth_date'], $request->header('user-agent'), $request->ip);
        $audit->record((int) $user['id'], null, 'miniapp.login', 'success', 'session', null, ['auth_date' => $verified['auth_date']], $request->ip);
        return ['session' => $session, 'user' => ['id' => (int) $user['id'], 'telegram_id' => (int) $user['telegram_id'], 'first_name' => $user['first_name'], 'username' => $user['username'], 'language' => $user['language'], 'ux_mode' => $user['ux_mode'], 'is_super_admin' => (bool) $user['is_super_admin']], 'api_version' => 'v1', 'app_version' => Config::app('version')];
    }, 12);

    $api->route('GET', '/api/v1/auth/session', static fn (Request $request, array $params, array $session): array => ['user' => ['id' => (int) $session['user_id'], 'telegram_id' => (int) $session['telegram_id'], 'first_name' => $session['first_name'], 'last_name' => $session['last_name'], 'username' => $session['username'], 'language' => $session['language'], 'ux_mode' => $session['ux_mode'], 'is_super_admin' => (bool) $session['is_super_admin']], 'expires_at' => $session['expires_at']], 120, true);

    $api->route('POST', '/api/v1/auth/rotate', static function (Request $request, array $params, array $session) use ($sessions, $audit): array {
        $replacement = $sessions->create((int) $session['user_id'], (int) $session['telegram_auth_date'], $request->header('user-agent'), $request->ip);
        $audit->record((int) $session['user_id'], null, 'miniapp.session_rotate', 'success', 'session', (string) $session['id'], [], $request->ip);
        return ['session' => $replacement];
    }, 12, true, false);

    $api->route('POST', '/api/v1/auth/logout', static function (Request $request, array $params, array $session) use ($sessions, $audit): array {
        $sessions->revoke((int) $session['id'], (int) $session['user_id']);
        $audit->record((int) $session['user_id'], null, 'miniapp.logout', 'success', 'session', (string) $session['id'], [], $request->ip);
        return ['revoked' => true];
    }, 20, true, false);

    $api->route('GET', '/api/v1/dashboard', static function (Request $request, array $params, array $session) use ($accounts, $settings, $plans, $database, $translator): array {
        $userId = (int) $session['user_id'];
        $notifications = $database->all('SELECT id, type, title_key, body_key, parameters_json, sent_at, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$userId]);
        foreach ($notifications as &$notification) {
            $decoded = json_decode((string) ($notification['parameters_json'] ?? '{}'), true);
            $parameters = is_array($decoded) ? array_filter($decoded, 'is_scalar') : [];
            $notification['title'] = $translator->get((string) $notification['title_key'], (string) $session['language'], $parameters);
            $notification['body'] = $translator->get((string) $notification['body_key'], (string) $session['language'], $parameters);
            unset($notification['parameters_json']);
        }
        unset($notification);
        return [
            'user' => ['id' => $userId, 'telegram_id' => (int) $session['telegram_id'], 'first_name' => $session['first_name'], 'last_name' => $session['last_name'], 'username' => $session['username'], 'language' => $session['language'], 'ux_mode' => $session['ux_mode'], 'is_super_admin' => (bool) $session['is_super_admin']],
            'hosts' => $accounts->listOwned($userId),
            'plan' => $plans->plan($userId),
            'favorites' => $settings->favorites($userId),
            'recent' => $settings->recent($userId),
            'notifications' => $notifications,
        ];
    });

    $api->route('GET', '/api/v1/hosts', static fn (Request $request, array $params, array $session): array => ['hosts' => $accounts->listOwned((int) $session['user_id'])]);
    $api->route('POST', '/api/v1/hosts', static function (Request $request, array $params, array $session) use ($accountService): array {
        return $accountService->add((int) $session['user_id'], (string) $request->input('host', ''), (string) $request->input('username', ''), (string) $request->input('token', ''), filter_var($request->input('store_token', true), FILTER_VALIDATE_BOOL), $request->ip);
    }, 10);
    $api->route('POST', '/api/v1/hosts/{account}/health', static fn (Request $request, array $params, array $session): array => $accountService->health((int) $session['user_id'], (int) $params['account'], $request->ip), 20, true);
    $api->route('PATCH', '/api/v1/hosts/{account}', static function (Request $request, array $params, array $session) use ($accountService): array {
        $accountService->rename((int) $session['user_id'], (int) $params['account'], (string) $request->input('label', ''), $request->ip);
        return ['saved' => true];
    }, 30);
    $api->route('PATCH', '/api/v1/hosts/{account}/token', static fn (Request $request, array $params, array $session): array => $accountService->rotateToken((int) $session['user_id'], (int) $params['account'], (string) $request->input('token', ''), filter_var($request->input('store_token', true), FILTER_VALIDATE_BOOL), $request->ip), 10);
    $api->route('DELETE', '/api/v1/hosts/{account}', static function (Request $request, array $params, array $session) use ($accounts, $confirmations, $audit): array {
        $userId = (int) $session['user_id'];
        $accountId = (int) $params['account'];
        $confirmations->consume((string) $request->input('confirmation', ''), $userId, $accountId, 'host.remove', (string) $accountId);
        $accounts->remove($userId, $accountId);
        $audit->record($userId, null, 'host.remove', 'success', 'host', (string) $accountId, [], $request->ip);
        return ['removed' => true];
    }, 10);

    $allowedConfirmationActions = ['host.remove', 'file.delete_permanent', 'file.delete_recursive', 'file.replace_sensitive', 'file.restore_version', 'trash.empty', 'database.delete', 'database_user.delete', 'database.remote_host_delete', 'row.delete', 'row.bulk_delete', 'table.drop', 'table.truncate', 'column.drop', 'index.drop', 'sql.execute', 'domain.delete', 'subdomain.delete', 'redirect.delete', 'dns.edit', 'email.delete', 'email.forwarder_delete', 'email.autoresponder_delete', 'ssl.autossl', 'cron.save', 'cron.delete', 'backup.restore', 'backup.delete', 'deployment.run', 'deployment.rollback', 'php.version', 'php.ini'];
    $api->route('POST', '/api/v1/confirmations', static function (Request $request, array $params, array $session) use ($confirmations, $accounts, $allowedConfirmationActions): array {
        $userId = (int) $session['user_id'];
        $accountId = $request->input('account_id');
        $accountId = $accountId === null ? null : (int) $accountId;
        $action = (string) $request->input('action', '');
        $target = (string) $request->input('target', '');
        $preview = $request->input('preview', []);
        if (!in_array($action, $allowedConfirmationActions, true) || $target === '' || strlen($target) > 2048 || !is_array($preview)) {
            throw new AppException('Confirmation request is invalid.', 422, 'invalid_confirmation_request', [], 'security.confirmations');
        }
        if ($accountId !== null) {
            $accounts->getOwned($userId, $accountId);
        }
        return ['nonce' => $confirmations->issue($userId, $accountId, $action, $target, $preview), 'expires_in' => 300];
    }, 30);

    $api->route('GET', '/api/v1/help', static function (Request $request, array $params, array $session) use ($help): array {
        return ['topics' => $help->search((string) $request->input('q', ''), (string) $session['language'], $request->input('category') === null ? null : (string) $request->input('category'), (int) $request->input('limit', 20))];
    });
    $api->route('GET', '/api/v1/help/{slug}', static fn (Request $request, array $params, array $session): array => ['topic' => $help->topic((string) $params['slug'], (string) $session['language'])]);

    $api->route('PATCH', '/api/v1/settings', static function (Request $request, array $params, array $session) use ($settings): array {
        $userId = (int) $session['user_id'];
        if ($request->input('language') !== null) {
            $settings->language($userId, (string) $request->input('language'));
        }
        if ($request->input('ux_mode') !== null) {
            $settings->uxMode($userId, (string) $request->input('ux_mode'));
        }
        return ['saved' => true];
    });
    $api->route('GET', '/api/v1/favorites', static fn (Request $request, array $params, array $session): array => ['favorites' => $settings->favorites((int) $session['user_id'])]);
    $api->route('POST', '/api/v1/favorites', static fn (Request $request, array $params, array $session): array => ['id' => $settings->addFavorite((int) $session['user_id'], $request->input('account_id') === null ? null : (int) $request->input('account_id'), (string) $request->input('type', ''), (string) $request->input('reference', ''), $request->input('label') === null ? null : (string) $request->input('label'))]);
    $api->route('DELETE', '/api/v1/favorites/{favorite}', static function (Request $request, array $params, array $session) use ($settings): array {
        $settings->removeFavorite((int) $session['user_id'], (int) $params['favorite']);
        return ['removed' => true];
    });
    $api->route('GET', '/api/v1/recent-actions', static fn (Request $request, array $params, array $session): array => ['actions' => $settings->recent((int) $session['user_id'])]);

    $api->route('GET', '/api/v1/security', static fn (Request $request, array $params, array $session): array => $security->dashboard((int) $session['user_id']), 60, true);
    $api->route('DELETE', '/api/v1/security/sessions/{session}', static function (Request $request, array $params, array $session) use ($security): array {
        $security->revokeSession((int) $session['user_id'], (int) $params['session']);
        return ['revoked' => true];
    }, 20, true);
    $api->route('POST', '/api/v1/security/events/{event}/acknowledge', static function (Request $request, array $params, array $session) use ($security): array {
        $security->acknowledge((int) $session['user_id'], (int) $params['event']);
        return ['acknowledged' => true];
    }, 30, true);
    $api->route('GET', '/api/v1/audit', static fn (Request $request, array $params, array $session): array => ['events' => $audit->recentForUser((int) $session['user_id'], (int) $request->input('limit', 50))], 60, true);
    $api->route('GET', '/api/v1/jobs/{job}', static fn (Request $request, array $params, array $session): array => $queue->status((int) $params['job'], (int) $session['user_id']), 180, true);
    $api->route('POST', '/api/v1/notifications/{notification}/read', static function (Request $request, array $params, array $session) use ($database): array {
        $statement = $database->execute('UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE id = ? AND user_id = ?', [(int) $params['notification'], (int) $session['user_id']]);
        if ($statement->rowCount() !== 1 && $database->one('SELECT id FROM notifications WHERE id = ? AND user_id = ?', [(int) $params['notification'], (int) $session['user_id']]) === null) {
            throw new AppException('Notification was not found or does not belong to you.', 404, 'notification_not_found', [], 'security.idor');
        }
        return ['read' => true];
    }, 60, true, false);
};
