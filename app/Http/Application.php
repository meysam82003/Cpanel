<?php

declare(strict_types=1);

namespace App\Http;

use App\Audit\AuditLogger;
use App\Core\AppException;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Env;
use App\Core\Logger;
use App\FileManager\DownloadService;
use App\Plans\PlanGuard;
use App\Security\MiniAppSessionService;
use App\Security\RateLimiter;
use App\Support\ErrorGuidanceService;
use App\Telegram\BotHandler;

final class Application
{
    private readonly ApiKernel $api;
    private readonly AuditLogger $audit;
    private readonly Database $database;
    private readonly Logger $logger;
    private readonly ErrorGuidanceService $guidance;

    public function __construct(private readonly Container $container)
    {
        $this->database = $container->get(Database::class);
        $this->audit = $container->get(AuditLogger::class);
        $this->logger = $container->get(Logger::class);
        $this->guidance = $container->get(ErrorGuidanceService::class);
        $this->api = new ApiKernel(
            $container->get(MiniAppSessionService::class),
            $container->get(RateLimiter::class),
            $this->database,
            $container->get(PlanGuard::class),
        );
        $register = require $container->root . '/routes/api.php';
        $register($this->api, $container);
    }

    public function handle(Request $request): Response
    {
        $started = microtime(true);
        $requestId = bin2hex(random_bytes(16));
        $response = null;
        try {
            $response = $this->dispatch($request);
        } catch (AppException $exception) {
            $response = $this->errorResponse($exception, $requestId);
            if ($exception->httpStatus >= 500) {
                $this->logger->error($exception, ['request_id' => $requestId, 'route' => $this->safeRoute($request)]);
            }
            if ($this->isSecurityException($exception)) {
                $this->recordSecurityEvent($request, $exception);
            }
        } catch (\Throwable $exception) {
            $this->logger->error($exception, ['request_id' => $requestId, 'route' => $this->safeRoute($request)]);
            $response = $this->errorResponse(new AppException('The server could not complete this request.', 500, 'internal_error'), $requestId);
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        $this->recordMetric($request, $response->status, $duration);
        $this->recordApiAudit($request, $response, $duration, $requestId);
        return $response;
    }

    private function dispatch(Request $request): Response
    {
        if (($request->method === 'GET' || $request->method === 'HEAD') && $request->path === '/health') {
            $this->container->get(RateLimiter::class)->hit('health.public', $request->ip ?? 'unknown', 30, 60);
            $this->database->one('SELECT 1 AS ok');
            return Response::json(['ok' => true, 'data' => ['status' => 'healthy', 'version' => Config::app('version'), 'installed' => is_file($this->container->root . '/storage/installed.lock'), 'time' => gmdate('c')]]);
        }

        if (preg_match('#^/webhook/([A-Za-z0-9_-]{40,64})$#', $request->path, $match)) {
            return $this->webhook($request, $match[1]);
        }

        if ($request->method === 'GET' && preg_match('#^/download/([A-Za-z0-9_-]{40,64})$#', $request->path, $match)) {
            $this->container->get(RateLimiter::class)->hit('download.public', $request->ip ?? 'unknown', 20, 60);
            $file = $this->container->get(DownloadService::class)->materialize($match[1]);
            return Response::download($file['path'], $file['filename'], $file['content_type'], $file['cleanup']);
        }

        if (str_starts_with($request->path, '/api/')) {
            return $this->api->dispatch($request);
        }
        throw new AppException('The requested endpoint was not found.', 404, 'not_found', [], 'errors.not-found');
    }

    private function webhook(Request $request, string $pathSecret): Response
    {
        if ($request->method !== 'POST') {
            throw new AppException('Telegram webhook accepts POST only.', 405, 'method_not_allowed');
        }
        $configured = Env::require('WEBHOOK_SECRET');
        $headerSecret = $request->header('x-telegram-bot-api-secret-token') ?? '';
        if (!hash_equals($configured, $pathSecret) || !hash_equals($configured, $headerSecret)) {
            throw new AppException('Webhook authentication failed.', 404, 'webhook_auth_failed');
        }
        if (!str_contains(strtolower($request->header('content-type') ?? ''), 'application/json')) {
            throw new AppException('Telegram webhook requires JSON.', 415, 'webhook_content_type');
        }
        $this->container->get(RateLimiter::class)->hit('telegram.webhook.ip', $request->ip ?? 'unknown', 600, 60);
        $updateId = filter_var($request->body['update_id'] ?? null, FILTER_VALIDATE_INT);
        if ($updateId === false || $updateId < 0) {
            throw new AppException('Telegram update ID is invalid.', 400, 'invalid_telegram_update');
        }
        $inserted = $this->database->execute("INSERT IGNORE INTO telegram_updates (update_id, status) VALUES (?, 'processing')", [(int) $updateId])->rowCount();
        if ($inserted === 0) {
            return Response::json(['ok' => true, 'duplicate' => true]);
        }
        try {
            $this->container->get(BotHandler::class)->handle($request->body);
            $this->database->execute("UPDATE telegram_updates SET status = 'processed', processed_at = CURRENT_TIMESTAMP WHERE update_id = ?", [(int) $updateId]);
        } catch (\Throwable $exception) {
            $code = $exception instanceof AppException ? $exception->safeCode : 'bot_update_failed';
            $this->database->execute('DELETE FROM telegram_updates WHERE update_id = ?', [(int) $updateId]);
            $this->logger->error($exception, ['update_id' => (int) $updateId, 'error_code' => $code]);
            throw $exception;
        }
        return Response::json(['ok' => true]);
    }

    private function errorResponse(AppException $exception, string $requestId): Response
    {
        $fa = $this->guidance->for($exception->safeCode, 'fa');
        $en = $this->guidance->for($exception->safeCode, 'en');
        $safeContext = array_intersect_key($exception->context, array_flip(['limit', 'retry_after', 'feature', 'minimum', 'maximum', 'setting', 'allowed']));
        $headers = [];
        if ($exception->httpStatus === 429 && isset($safeContext['retry_after'])) {
            $headers['Retry-After'] = (string) max(1, (int) $safeContext['retry_after']);
        }
        $response = Response::json([
            'ok' => false,
            'error' => [
                'code' => $exception->safeCode,
                'message' => $exception->getMessage(),
                'message_fa' => $fa['title'],
                'message_en' => $en['title'],
                'request_id' => $requestId,
                'help_slug' => $exception->helpSlug,
                'context' => $safeContext,
                'guidance' => ['fa' => $fa, 'en' => $en],
            ],
        ], $exception->httpStatus);
        if ($headers === []) {
            return $response;
        }
        return new Response($response->body, $response->status, $response->headers + $headers);
    }

    private function recordMetric(Request $request, int $status, int $duration): void
    {
        try {
            $this->database->execute('INSERT INTO api_request_metrics (route, method, status_code, duration_ms, user_id) VALUES (?, ?, ?, ?, ?)', [$this->safeRoute($request), $request->method, $status, max(0, $duration), $this->api->currentUserId()]);
        } catch (\Throwable) {
        }
    }

    private function recordApiAudit(Request $request, Response $response, int $duration, string $requestId): void
    {
        if (!str_starts_with($request->path, '/api/v1/')) {
            return;
        }
        try {
            $this->audit->record(
                $this->api->currentUserId(),
                null,
                'api.request',
                $response->status < 400 ? 'success' : 'failed',
                'route',
                $this->safeRoute($request),
                [
                    'method' => $request->method,
                    'status_code' => $response->status,
                    'duration_ms' => max(0, $duration),
                ],
                $request->ip,
                $requestId,
            );
        } catch (\Throwable) {
            // Audit availability must never replace the endpoint's safe response.
        }
    }

    private function recordSecurityEvent(Request $request, AppException $exception): void
    {
        try {
            $fingerprint = hash_hmac('sha256', ($request->ip ?? 'unknown') . '|' . ($request->header('user-agent') ?? ''), Env::require('SESSION_SECRET'));
            $metadata = ['route' => $this->safeRoute($request), 'request_method' => $request->method];
            $this->database->execute('INSERT INTO security_events (user_id, event_type, severity, fingerprint, metadata_json) VALUES (?, ?, ?, ?, ?)', [$this->api->currentUserId(), $exception->safeCode, $exception->httpStatus === 429 ? 'warning' : 'danger', $fingerprint, json_encode($metadata, JSON_THROW_ON_ERROR)]);
            $parameters = json_encode(['code' => $exception->safeCode], JSON_THROW_ON_ERROR);
            $this->database->execute(
                "INSERT INTO notifications (user_id, type, title_key, body_key, parameters_json) SELECT u.id, 'security_alert', 'notification.security_title', 'notification.security_event', ? FROM users u WHERE u.is_super_admin = 1 AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.user_id = u.id AND n.type = 'security_alert' AND n.parameters_json = ? AND n.created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE))",
                [$parameters, $parameters]
            );
        } catch (\Throwable) {
        }
    }

    private function isSecurityException(AppException $exception): bool
    {
        return in_array($exception->safeCode, [
            'csrf_failed', 'session_binding_failed', 'invalid_init_data', 'init_data_replay', 'expired_init_data',
            'rate_limit_exceeded', 'webhook_auth_failed', 'ssrf_target_blocked',
            'path_outside_root', 'path_traversal_blocked', 'host_not_found', 'cpanel_auth_failed',
            'callback_invalid', 'callback_expired',
        ], true);
    }

    private function safeRoute(Request $request): string
    {
        if ($this->api->matchedRoute() !== null) {
            return $this->api->matchedRoute();
        }
        if (str_starts_with($request->path, '/webhook/')) {
            return '/webhook/{secret}';
        }
        if (str_starts_with($request->path, '/download/')) {
            return '/download/{token}';
        }
        return $request->path === '/health' ? '/health' : 'unmatched';
    }
}
