<?php

declare(strict_types=1);

use App\Admin\AdminService;
use App\Core\AppException;
use App\Core\Container;
use App\Http\ApiKernel;
use App\Http\Request;

return static function (ApiKernel $api, Container $container): void {
    $admin = $container->get(AdminService::class);
    $adminId = static fn (array $session): int => (int) $session['user_id'];

    $api->route('GET', '/api/v1/admin/dashboard', static fn (Request $request, array $params, array $session): array => $admin->dashboard($adminId($session)), 60, true);
    $api->route('GET', '/api/v1/admin/users', static fn (Request $request, array $params, array $session): array => $admin->users($adminId($session), (string) $request->input('q', ''), (int) $request->input('page', 1), (int) $request->input('per_page', 50)), 60, true);
    $api->route('PATCH', '/api/v1/admin/users/{user}/status', static function (Request $request, array $params, array $session) use ($admin, $adminId): array {
        $admin->setUserStatus($adminId($session), (int) $params['user'], (string) $request->input('status', ''));
        return ['saved' => true];
    }, 30, true);
    $api->route('PUT', '/api/v1/admin/users/{user}/plan', static function (Request $request, array $params, array $session) use ($admin, $adminId): array {
        $endsAt = $request->input('ends_at');
        $admin->assignPlan($adminId($session), (int) $params['user'], (string) $request->input('plan', ''), $endsAt === null || $endsAt === '' ? null : (string) $endsAt);
        return ['saved' => true];
    }, 30, true);

    $api->route('GET', '/api/v1/admin/hosts', static fn (Request $request, array $params, array $session): array => ['hosts' => $admin->hosts($adminId($session), (int) $request->input('limit', 100))], 60, true);
    $api->route('GET', '/api/v1/admin/plans', static fn (Request $request, array $params, array $session): array => ['plans' => $admin->plans($adminId($session))], 60, true);
    $api->route('PATCH', '/api/v1/admin/plans/{slug}', static function (Request $request, array $params, array $session) use ($admin, $adminId): array {
        $values = $request->body;
        unset($values['_csrf']);
        $admin->updatePlan($adminId($session), (string) $params['slug'], $values);
        return ['saved' => true];
    }, 20, true);

    $api->route('GET', '/api/v1/admin/broadcasts', static fn (Request $request, array $params, array $session): array => ['broadcasts' => $admin->broadcasts($adminId($session), (int) $request->input('limit', 100))], 60, true);
    $api->route('POST', '/api/v1/admin/broadcasts', static function (Request $request, array $params, array $session) use ($admin, $adminId): array {
        $filter = $request->input('filter', []);
        if (!is_array($filter)) {
            throw new AppException('Broadcast filter must be an object.', 422, 'invalid_broadcast');
        }
        return $admin->createBroadcast($adminId($session), (string) $request->input('message_fa', ''), (string) $request->input('message_en', ''), $filter);
    }, 5, true);

    $api->route('GET', '/api/v1/admin/audit', static fn (Request $request, array $params, array $session): array => ['events' => $admin->auditLogs($adminId($session), (int) $request->input('limit', 100))], 60, true);
    $api->route('GET', '/api/v1/admin/security-events', static fn (Request $request, array $params, array $session): array => ['events' => $admin->securityEvents($adminId($session), (int) $request->input('limit', 100))], 60, true);
    $api->route('GET', '/api/v1/admin/queue', static fn (Request $request, array $params, array $session): array => ['jobs' => $admin->jobs($adminId($session), (int) $request->input('limit', 100))], 60, true);
    $api->route('GET', '/api/v1/admin/failed-jobs', static fn (Request $request, array $params, array $session): array => ['jobs' => $admin->failedJobs($adminId($session), (int) $request->input('limit', 100))], 60, true);
    $api->route('POST', '/api/v1/admin/failed-jobs/{job}/retry', static fn (Request $request, array $params, array $session): array => ['job_id' => $admin->retryFailedJob($adminId($session), (int) $params['job'])], 10, true);

    $api->route('GET', '/api/v1/admin/settings', static fn (Request $request, array $params, array $session): array => ['settings' => $admin->systemSettings($adminId($session)), 'maintenance' => $admin->maintenance($adminId($session))], 60, true);
    $api->route('PATCH', '/api/v1/admin/settings', static fn (Request $request, array $params, array $session): array => ['settings' => $admin->updateSystemSettings($adminId($session), $request->body)], 20, true);
    $api->route('PUT', '/api/v1/admin/maintenance', static function (Request $request, array $params, array $session) use ($admin, $adminId): array {
        $admin->setMaintenance($adminId($session), filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOL), (string) $request->input('message_fa', ''), (string) $request->input('message_en', ''));
        return ['maintenance' => $admin->maintenance($adminId($session))];
    }, 10, true);
    $api->route('GET', '/api/v1/admin/health', static fn (Request $request, array $params, array $session): array => $admin->health($adminId($session)), 20, true);
};
