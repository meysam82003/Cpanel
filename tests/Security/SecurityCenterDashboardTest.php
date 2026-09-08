<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Accounts\AccountRepository;
use App\Accounts\UserRepository;
use App\Security\Crypto;
use App\Security\MiniAppSessionService;
use App\Security\SecurityCenterService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteTestDatabase;

final class SecurityCenterDashboardTest extends TestCase
{
    use SqliteTestDatabase;

    public function testSecuritySummaryAndListsAreTenantBound(): void
    {
        $database = $this->sqlite(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT NOT NULL);
CREATE TABLE cpanel_accounts (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, label TEXT, base_url TEXT, hostname TEXT, port INTEGER,
 cpanel_username TEXT, token_mode TEXT, root_path TEXT, main_domain TEXT, status TEXT, last_error_code TEXT,
 last_checked_at TEXT, capability_checked_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE account_capabilities (
 id INTEGER PRIMARY KEY, account_id INTEGER, capability TEXT, available INTEGER, writable INTEGER,
 details_json TEXT, detected_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE miniapp_sessions (
 id INTEGER PRIMARY KEY, user_id INTEGER, channel TEXT, token_hash TEXT, csrf_hash TEXT, telegram_auth_date INTEGER,
 user_agent_hash TEXT, ip_hash TEXT, last_used_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT, revoked_at TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE security_events (
 id INTEGER PRIMARY KEY, user_id INTEGER, account_id INTEGER, event_type TEXT, severity TEXT, fingerprint TEXT,
 metadata_json TEXT, acknowledged_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE audit_logs (
 id INTEGER PRIMARY KEY, user_id INTEGER, account_id INTEGER, action TEXT, target_type TEXT, target_ref TEXT,
 result TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $database->execute("INSERT INTO users (id, status) VALUES (1, 'active'), (2, 'active')");
        $database->execute("INSERT INTO cpanel_accounts (id, user_id, label, base_url, hostname, port, cpanel_username, token_mode, status, last_error_code) VALUES (10, 1, 'Owned', 'https://203.0.113.1:2083', 'owned.test', 2083, 'owned', 'stored', 'error', 'cpanel_auth_failed'), (20, 2, 'Other', 'https://203.0.113.2:2083', 'other.test', 2083, 'other', 'stored', 'error', 'cpanel_auth_failed')");
        $database->execute("INSERT INTO miniapp_sessions (id, user_id, channel, token_hash, csrf_hash, telegram_auth_date, expires_at) VALUES (100, 1, 'miniapp', 'a', 'b', 1, datetime('now', '+1 hour')), (200, 2, 'miniapp', 'c', 'd', 1, datetime('now', '+1 hour'))");
        $database->execute("INSERT INTO security_events (id, user_id, account_id, event_type, severity, fingerprint, acknowledged_at) VALUES (1, 1, 10, 'ssrf_target_blocked', 'danger', 'one', NULL), (2, 1, 10, 'rate_limit_exceeded', 'warning', 'two', CURRENT_TIMESTAMP), (3, 2, 20, 'ssrf_target_blocked', 'danger', 'three', NULL)");
        $database->execute("INSERT INTO audit_logs (id, user_id, account_id, action, target_type, target_ref, result) VALUES (1, 1, 10, 'file.delete', 'file', '/owned', 'success'), (2, 2, 20, 'database.drop', 'database', 'other', 'success')");

        $accounts = new AccountRepository($database, new Crypto([1 => str_repeat('k', 32)], 1));
        $service = new SecurityCenterService(
            $database,
            new UserRepository($database),
            $accounts,
            new MiniAppSessionService($database, str_repeat('s', 32)),
        );

        $dashboard = $service->dashboard(1);

        self::assertSame([
            'connected_hosts' => 1,
            'failed_tokens' => 1,
            'suspicious_requests' => 2,
            'recent_destructive_actions' => 1,
            'active_sessions' => 1,
            'security_alerts' => 1,
        ], $dashboard['summary']);
        self::assertCount(1, $dashboard['hosts']);
        self::assertSame(10, (int) $dashboard['hosts'][0]['id']);
        self::assertCount(1, $dashboard['sessions']);
        self::assertCount(2, $dashboard['alerts']);
        self::assertCount(1, $dashboard['destructive_actions']);
    }
}
