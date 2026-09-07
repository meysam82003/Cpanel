<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\AppException;
use App\Core\Database;
use App\Security\MiniAppSessionService;
use App\Security\RateLimiter;
use App\Plans\PlanGuard;

final class ApiKernel
{
    private Router $router;
    private ?int $currentUserId = null;

    public function __construct(
        private readonly MiniAppSessionService $sessions,
        private readonly RateLimiter $limits,
        private readonly Database $database,
        private readonly PlanGuard $plans,
    ) {
        $this->router = new Router();
    }

    /** @param callable(Request,array<string,string>):(array<string,mixed>|list<mixed>|Response) $handler */
    public function publicRoute(string $method, string $pattern, callable $handler, int $limit = 30): void
    {
        $this->router->add($method, $pattern, function (Request $request, array $params) use ($handler, $limit, $pattern): Response {
            $this->limits->hit('api.global', 'all', 2400, 60);
            $this->limits->hit('api.public.' . $pattern, $request->ip ?? 'unknown', $limit, 60);
            $result = $handler($request, $params);
            return $result instanceof Response ? $result : Response::json(['ok' => true, 'data' => $result]);
        });
    }

    /**
     * @param callable(Request,array<string,string>,array<string,mixed>):(array<string,mixed>|list<mixed>|Response) $handler
     */
    public function route(string $method, string $pattern, callable $handler, int $perMinute = 120, bool $maintenanceAllowed = false, bool $countOperation = true): void
    {
        $this->router->add($method, $pattern, function (Request $request, array $params) use ($handler, $perMinute, $maintenanceAllowed, $countOperation, $pattern): Response {
            $token = $this->bearer($request->header('authorization'));
            $session = $this->sessions->authenticate($token, $request->header('x-csrf-token'), $request->method, $request->header('user-agent'));
            $userId = (int) $session['user_id'];
            $this->currentUserId = $userId;
            $this->limits->hit('api.global', 'all', 2400, 60);
            $this->limits->hit('api.user', $userId, $perMinute, 60);
            $this->limits->hit('api.route.' . $pattern, $userId, max(5, (int) floor($perMinute / 2)), 60);
            if (!$maintenanceAllowed && !(bool) $session['is_super_admin'] && $this->maintenance()) {
                throw new AppException('The service is in maintenance mode. In-flight jobs continue safely; try again later.', 503, 'maintenance_mode', [], 'maintenance');
            }
            $this->enforceFeatureForRoute($userId, $pattern);
            if ($countOperation && !(bool) $session['is_super_admin'] && !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                $this->plans->dailyOperation($userId);
            }
            $result = $handler($request, $params, $session);
            return $result instanceof Response ? $result : Response::json(['ok' => true, 'data' => $result]);
        });
    }

    public function dispatch(Request $request): Response
    {
        $this->currentUserId = null;
        return $this->router->dispatch($request);
    }

    public function currentUserId(): ?int
    {
        return $this->currentUserId;
    }

    public function matchedRoute(): ?string
    {
        return $this->router->lastMatchedPattern();
    }

    private function bearer(?string $authorization): string
    {
        if ($authorization === null || !preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,64})$/i', trim($authorization), $match)) {
            throw new AppException('A valid Bearer session is required.', 401, 'authentication_required');
        }
        return $match[1];
    }

    private function maintenance(): bool
    {
        $row = $this->database->one("SELECT setting_value FROM settings WHERE setting_key = 'maintenance'");
        if ($row === null) {
            return false;
        }
        $value = json_decode((string) $row['setting_value'], true);
        return is_array($value) && ($value['enabled'] ?? false) === true;
    }

    private function enforceFeatureForRoute(int $userId, string $pattern): void
    {
        if (str_contains($pattern, '/backups')) {
            $this->plans->feature($userId, 'backup_enabled');
            return;
        }
        if (str_contains($pattern, '/deployments') || str_contains($pattern, '/deployment-packages')) {
            $this->plans->feature($userId, 'deployment_enabled');
            return;
        }
        if (str_contains($pattern, '/sql')) {
            $this->plans->feature($userId, 'sql_console');
            return;
        }
        foreach (['/databases', '/database-users', '/database-privileges', '/remote-mysql-hosts', '/database-imports', '/database-exports'] as $segment) {
            if (str_contains($pattern, $segment)) {
                $this->plans->feature($userId, 'database_manager');
                return;
            }
        }
    }
}
