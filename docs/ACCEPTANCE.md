# FINAL ACCEPTANCE — 95 cumulative sections

نتیجهٔ صادقانه: برای هر ۹۵ بخش مسیر اجرایی و Evidence در Repository وجود دارد. تست‌های مستقل، Security، Static contract و MariaDB در CI اجرا می‌شوند. مواردی که ذاتاً به Bot Token، Domain و cPanel Provider واقعی نیاز دارند با `LIVE` مشخص شده‌اند و بدون Credential کاربر به‌عنوان تست زنده ادعا نمی‌شوند.

Honest outcome: all 95 sections have an implemented code path and repository evidence. Unit, security, static-contract, MariaDB-installation and package tests run in CI. Items that inherently require a real Telegram bot/domain/cPanel provider are marked `LIVE`; they are not misreported as executed without owner credentials.

Legend:

- `PASS-A`: implemented and covered by automated/static/integration tests.
- `PASS-C`: implemented with official contract and runtime capability detection; provider-specific success is conditional on the provider.
- `LIVE`: implementation/test harness exists, but a deployment-owner live credential matrix remains required.

## Scope 1

| # | Section | Result | Evidence |
|---:|---|---|---|
| 1 | Installer | PASS-A + LIVE | Exactly five fields, generated keys/config, resumable MariaDB setup, Telegram verification, storage and final lock; `InstallerService`, installer tests, MariaDB test. Literal webhook registration needs a real bot/domain. |
| 2 | Multi-tenant users | PASS-A | Owner-bound repositories/routes for multiple accounts; cross-tenant lookup test and foreign-ID 404 policy. |
| 3 | Token security | PASS-A | AES-256-GCM envelopes, key version/context, masked output, rotate/remove, encrypted expiring temporary mode. |
| 4 | Main Telegram UI | PASS-A + LIVE | Required menus/commands, inline keyboard, short opaque callback state, sessions and Back navigation; Telegram rendering needs live client acceptance. |
| 5 | Complete File Manager | PASS-A/C | Browse/sort/page/metadata/mutations, editor, archive and audit endpoints; cPanel operations capability-dependent. |
| 6 | Telegram file upload | PASS-A + LIVE | Webhook dispatches encrypted owner-bound job; Bot API download, size/MIME/name/collision controls, separate overwrite confirmation callback and cPanel upload handler. Live transfer needs bot/cPanel. |
| 7 | File download | PASS-A + LIVE | Streamed cPanel preparation, max size/SHA-256, Telegram or expiring one-use secure link, cleanup; transfer integration conditional. |
| 8 | Path security | PASS-A | Root-bound canonicalization, encoded traversal/control checks, boundary-safe ownership; unit/security tests. |
| 9 | Trash system | PASS-A/C | Move to Trash, restore, permanent/empty confirmation and audit; provider capability/fallback handling. |
| 10 | cPanel Database Manager | PASS-A/C | Database/user/password/privilege/Remote MySQL UAPI paths with confirmation and capability detection. |
| 11 | Database Data Manager | PASS-A/C | Connection automation; table structure, CRUD, filter/page/order, bulk and CSV. Direct MySQL is provider-network dependent. |
| 12 | SQL Console | PASS-A | Analyze/classify, one statement, read-only result pagination and byte/time limits, secret-safe optional history, encrypted saved queries/EXPLAIN, destructive confirmation and backup coordination. |
| 13 | Database connection automation | PASS-A/C | Generated restricted DB user, privilege/remote-host coordination, encrypted connection, status/revoke; remote network conditional. |
| 14 | SQL import/export | PASS-A/C | Queue-backed SQL/gzip/ZIP import and SQL/CSV export, staging, checksum/size, file-bound one-time import confirmation, artifact download and pre-import backup. |
| 15 | Domains | PASS-C | Domain/subdomain/redirect/DNS CRUD, serial-bound DNS edit, warnings and confirmation; token/Feature Manager dependent. |
| 16 | Email | PASS-C | Mailbox, password, quota, forwarder, autoresponder operations; mail-role dependent. |
| 17 | SSL | PASS-C | Certificate status/inspection, AutoSSL eligibility and confirmed run; provider AutoSSL dependent. |
| 18 | Cron jobs | PASS-C | List/create/edit/enable/disable/delete, expression/command validation; documented compatibility bridge if no UAPI. |
| 19 | Backup Manager | PASS-A/C | Grouped inventory, full/directory/database/deployment backup, persisted state, queue tracking, download/restore/delete. |
| 20 | Host usage/info | PASS-C | Disk, bandwidth, quotas, variables and one-tap status through cPanel capability. |
| 21 | Log Viewer | PASS-A/C | Safe path discovery, bounded tail/search/response and no unrestricted fetch; available logs vary. |
| 22 | PHP settings | PASS-C | Vhost versions and allowlisted INI update with preview/confirmation; MultiPHP provider dependent. |
| 23 | Dangerous action protection | PASS-A | Typed preview/warning, one-time user/account/action/target confirmation including overwrite/import, replay rejection and audit. |
| 24 | Auto backup before dangerous operations | PASS-A/C | Editor versions, destructive SQL/import/deploy/restore pre-backup paths; operation-specific capability errors. |
| 25 | Deploy ZIP | PASS-A/C | Safe upload/manifest/checksum, eight durable stages, backup, isolated extraction, switch, health, rollback/recovery. |
| 26 | Super Admin | PASS-A | Role-gated dashboard, health, users/hosts/plans/broadcast/audit/security/queue/settings/maintenance; no raw tokens. |
| 27 | User management | PASS-A | Search, active/ban/suspend, session revoke, plan assignment and auditing; super-admin self-protection. |
| 28 | Plans | PASS-A | Four seeded plans, editable bilingual names/limits/flags, host/upload/daily/database/SQL/backup/deploy enforcement. |
| 29 | Rate limit | PASS-A | Atomic DB buckets at global/user/route/public/webhook/download scopes; 429/retry guidance and tests. |
| 30 | Audit log | PASS-A | Tenant/global actor/account/action/target/result/request/time/masked metadata with indexed queries. |
| 31 | Queue | PASS-A | Encrypted payloads, atomic reservation, owner-bound lease, progress, retries, failed ledger, job status/download. |
| 32 | Cron/worker fallback | PASS-A + LIVE | Authenticated bounded one-minute runner plus VPS worker; heartbeat/recovery/cleanup. Hosting scheduler setup is live. |
| 33 | Telegram session engine | PASS-A | Encrypted expiring bot state and owner-bound callback state; cancellation and cleanup. |
| 34 | Error handling | PASS-A | Safe codes, request IDs, bilingual causes/actions/help, masked protected logs, provider normalization. |
| 35 | cPanel capability detection | PASS-A/C | Persisted available/writable/detail state for each host/tool; UI/server policy distinguishes unsupported/read-only. |
| 36 | API client | PASS-A/C | TLS/pinned DNS/timeouts/size cap/error normalization, UAPI-first, controlled compatibility and non-idempotent no-replay. |
| 37 | Architecture | PASS-A | PSR-4 modular services/routes/handlers, dependency container, webhook/API/CLI separation. |
| 38 | Database schema | PASS-A | 11 migrations, >40 InnoDB utf8mb4 tables and FK/index/state topology verified on MariaDB. |
| 39 | Security | PASS-A | Tenant auth, encryption, CSRF/session/initData, confirmations, rate limit, safe errors, headers and audit. |
| 40 | SSRF security | PASS-A | Local/private/reserved/metadata/unsafe port block, controlled private opt-in, DNS rebinding defense and tests. |
| 41 | UX | PASS-A + LIVE | Persian-first, concise navigation, pagination/loading/empty states and mobile touch UI; final device review is live. |
| 42 | Health check | PASS-A + LIVE | Telegram, DB/schema, queue, storage/disk, encryption, PHP/extensions, cron heartbeat and recent errors. Telegram reachability is live. |
| 43 | System update preparation | PASS-A | `VERSION`, schema history, migrations, release manifest and authenticated lock/audit updater. |
| 44 | Tests | PASS-A | Encryption, ownership, callback, path, SSRF, file/archive, SQL, installer, cPanel policy, rate limit plus deployment/backup/queue. |
| 45 | Manual test matrix | LIVE | Executable matrix below and live harness exist; destructive provider rows require owner-provided disposable account. |
| 46 | No fake implementations | PASS-A | UI/action/route static parity, unfinished-marker scan and capability-aware failures; no mock provider in production. |
| 47 | Error recovery | PASS-A | Deployment/backup/archive/queue durable states, leases/checkpoints, retry/rollback and reconciliation stop. |
| 48 | Six extra improvements | PASS-A | Favorites, recent actions, one-tap health, file versions, maintenance and security alerts are API/UI backed. |
| 49 | Documentation | PASS-A | Complete English/Persian README plus implementation/test/security/install/limitations/acceptance reports. |
| 50 | Final delivery | PASS-A | Source, Composer, schema, installer, webhook, worker/cron, Mini App/API, tests, reports and CI-built installable ZIP/checksum. |
| 51 | Execution rules | PASS-A/C | Modular E2E paths, ownership/security priority, UAPI contract checker, timeouts/errors; provider-live boundary disclosed. |

## Scope 2

| # | Section | Result | Evidence |
|---:|---|---|---|
| 52 | Mandatory Telegram Mini App | PASS-A + LIVE | Real client/API, Telegram SDK bridge and session bootstrap; final Telegram launch needs live bot. |
| 53 | Mini App authentication | PASS-A | Telegram HMAC/TTL/replay, opaque Bearer/CSRF/session binding/expiry/revoke with tests. |
| 54 | Main Mini App | PASS-A | Dashboard, host selector, navigation, synchronized settings/notifications/favorites/recent actions. |
| 55 | Fullscreen UI | PASS-A + LIVE | Responsive safe-area shell, sticky navigation/dialog/editor fullscreen and Telegram viewport hooks; device matrix live. |
| 56 | Mini App dashboard | PASS-A | User/plan/hosts/health/usage/notifications/favorites/recent activity and teaching empty states. |
| 57 | Host cards | PASS-A/C | Label/domain/user/status/disk/SSL/API/last check plus open/test/health/rotate/remove/favorite actions. |
| 58 | Visual File Manager | PASS-A/C | List/grid, breadcrumb, drag upload, selection tools, file actions, page/sort/hidden controls, and explicit server-verified overwrite UI. |
| 59 | Mini App Code Editor | PASS-A/C | Bundled Ace, syntax modes/themes, chunk loading, save/save-as/find/replace/history and version restore. |
| 60 | Mini App Database Manager | PASS-A/C | Database cards plus users/privileges/Remote MySQL/import/export/connect actions. |
| 61 | Mini App Table Browser | PASS-A/C | Rows/structure tabs, filter/order/pagination/visible columns, row/column/index/table operations. |
| 62 | Mini App SQL Editor | PASS-A/C | Editor, analyze/execute/explain, read-only result pager, optional secret-safe history, encrypted saved query, result rendering and destructive preview. |
| 63 | Mini App Import/Export | PASS-A/C | Real upload/form, queued progress, result download and operation warnings. |
| 64 | Mini App Deployment Center | PASS-A/C | Exact eight-step workflow, active/current/rollback/attention groups, live polling/timeline and confirmed rollback. |
| 65 | Mini App Backup Center | PASS-A/C | File/database/deployment/full groups, type/target/size/date/status and state-valid download/restore/delete. |
| 66 | Mini App Security Center | PASS-A | Six tenant-bound summary metrics, host rotate/remove, session termination, alerts and destructive audit. |
| 67 | Mini App Admin Panel | PASS-A | Required dashboard metrics and Users/Hosts/Plans/Broadcast/Audit/Security/Settings/Maintenance/Health/Queue/Failed pages. |
| 68 | Bot + Mini App synchronization | PASS-A + LIVE | Shared DB/services/audit/notification queue; completion notices persisted and delivered to Bot. Delivery needs live bot. |
| 69 | Bilingual system | PASS-A | Matching FA/EN UI and bot key sets; errors/warnings/help/installer/admin/status translations. |
| 70 | First-start language | PASS-A + LIVE | First `/start` language keyboard, DB persistence, settings change; Telegram rendering live. |
| 71 | Complete onboarding | PASS-A + LIVE | Seven persisted stages with next/previous/skip/replay and host/token security content. |
| 72 | Contextual help every section | PASS-A | Page-to-topic routing, Help control and category coverage in shared catalog. |
| 73 | Help from start | PASS-A | 78 topics include every named start/host/file/database/hosting/deploy/security/admin/install subject. |
| 74 | Contextual warnings | PASS-A | Operation-specific file/database/SQL/cron/SSL/deploy/token/backup warnings and previews. |
| 75 | Warning levels | PASS-A | Info/warning/danger data, icon/text labels and distinct accessible styling. |
| 76 | Bilingual token warning | PASS-A | Required meaning appears in FA/EN Bot/Mini App help and warnings. |
| 77 | Token creation tutorial | PASS-A | Step-by-step FA/EN topic and post-install documentation with provider-disabled guidance. |
| 78 | Smart Help System | PASS-A | DB/translation-driven id/slug/category/FA+EN body/title/level/relations/version/search text. |
| 79 | Help search | PASS-A | Authenticated search endpoint and debounced/cancellable Mini App result page. |
| 80 | Beginner/Advanced mode | PASS-A | Persisted mode changes density/explanation and advanced visibility without weakening backend authorization. |
| 81 | Teaching empty states | PASS-A | Host/file/database/row/backup/deploy/help pages include action and contextual learning, not bare “No data”. |
| 82 | Teaching errors | PASS-A | Safe bilingual title, likely causes, suggested actions, request ID, retry/context help. |
| 83 | Operation preview | PASS-A | Confirmations bind structured target/preview; deploy/SQL/delete/restore/cron/SSL/PHP flows render summaries. |
| 84 | Accessibility | PASS-A + LIVE | Semantic controls/dialogs, labels, focus, contrast variables, touch targets and non-color warning cues; device audit live. |
| 85 | Performance | PASS-A | Server pagination, bounded content/tail/result, lazy views, abort signals, debounced help/search, queue and metadata limits. |
| 86 | Mini App API | PASS-A | 154 versioned routes with session auth, CSRF mutation protection, ownership, plan auth, rate limits, validation/audit. |
| 87 | Installer + Mini App | PASS-A + LIVE | Installer generates public/Mini App/API/Webhook config and registers menu button without extra input. |
| 88 | BotFather post-install guide | PASS-A | `/telegram-setup` bilingual steps and ready-to-copy detected URL. |
| 89 | Bot commands | PASS-A + LIVE | All required commands plus admin role gate; installer registers command list. |
| 90 | Start screen | PASS-A + LIVE | Required post-onboarding menu and one-tap Web App button. |
| 91 | Inline education | PASS-A | Contextual help/info for TRUNCATE, privileges, Remote MySQL, Cron, AutoSSL and Rollback. |
| 92 | Documentation consistency | PASS-A | `resources/help/topics.php` is seeded once and consumed by Bot/Mini App; README links the same semantics. |
| 93 | No fake Mini App | PASS-A | Action-handler/API route parity and real backend calls; no iframe/static fake/coming-soon production path. |
| 94 | Mini App test matrix | PASS-A + LIVE | Auth/session/language/actions/backend contracts automated; Telegram menu launch, themes/viewports and provider mutations remain live rows below. |
| 95 | Final acceptance rule | PASS-A/C + LIVE | All components are wired and build gates pass; final environment certification requires the deployment-owner live rows. |

## Scope 45 live manual matrix

Use a new installation and disposable cPanel account. “Expected” is the acceptance criterion; record PASS/FAIL, safe request ID and provider limitation during deployment acceptance.

| Test | Automated support | Expected live result |
|---|---|---|
| Install | MariaDB + installer contract | Five inputs only; all stages verified; installer locks |
| `/start` | Bot state/translation code | Language → onboarding → start screen |
| Add cPanel | Host/SSRF/account tests | Valid token saves; invalid token gives guidance |
| Multiple users/hosts | Ownership tests | No cross-user/account visibility |
| Browse/upload/download/edit | File/transfer tests | Real cPanel mutation and matching audit/notification |
| Rename/move/copy/ZIP/extract | Path/archive/deploy tests | Correct result; unsafe archive rejected |
| Delete/restore Trash | Confirmation/path tests | One-time preview; Trash and restore verified |
| Create DB/user/privileges | Route/UAPI contract | Provider creates exact resources |
| Browse database | Connection/security tests | Works only when Remote MySQL/network permits |
| Execute SELECT | SQL safety/static contracts | Bounded paginated result and query-text-free optional history |
| Import/export | Queue/transfer tests | Job completes; checksum-bound artifact downloads |
| Domain/DNS | UAPI contract | Provider mutation and refreshed list/zone |
| Email | UAPI contract | Mailbox/forwarder/autoresponder mutation reflected |
| SSL | UAPI contract | Status accurate; AutoSSL eligibility respected |
| Cron | Expression/compat tests | Create/edit/toggle/delete reflected by provider |
| Backup | Tracker/restore tests | Artifact state, download/restore/delete and notification |
| Deploy/Rollback | Extensive state/integrity tests | Health passes or previous release is verified restored |
| Admin/Ban/Plan | Admin ownership code | Role gate, mutation, audit and session revoke |
| Rate limit | RateLimiter test | 429 plus guidance/alert without data leakage |
| Webhook recovery | Update dedupe/state code | Failed update may retry; processed update deduplicates |

## Scope 94 Telegram Mini App live matrix

| Test group | Automated evidence | Live acceptance needed |
|---|---|---|
| Launch | Installer/Bot Web App code | `/start` button and Bot menu open the installed HTTPS URL |
| Authentication | HMAC/replay/session tests | Valid Telegram client enters; altered/expired data rejected |
| FA/EN | Key parity and settings tests | Direction, typography and every exercised dialog read correctly |
| Light/dark | Theme-variable CSS | Telegram Android/iOS/Desktop visual inspection |
| Host/File/Editor | Action-route parity and service tests | Provider data/mutations, upload/download and version restore |
| Database/SQL/import/export | Safety/transfer tests | Disposable DB operations and provider firewall behavior |
| Domain/Email/SSL/Cron | Official contracts | Enabled modules mutate; disabled modules teach why |
| Backup/Deploy/Rollback | Queue/state/integrity tests | Artifacts and notifications finish on provider |
| Security/Admin | Ownership and role tests | Session revoke/logs/ban/queue/health reflect immediately |
| Logout | Session tests | Current session becomes unusable and can reauthenticate from Bot |
| Back Button | Navigation handler | Telegram Back Button follows history without stale mutation |
| Mobile viewport | Responsive/accessibility CSS | 320 px and typical Android/iOS widths; keyboard and safe areas |

## Final decision

- **Implementation acceptance:** PASS for all 95 sections with concrete source evidence.
- **Automated acceptance:** PASS — CI #8 (`34187466831`) completed PHP 8.2/8.3/8.4, MariaDB installation and release-package jobs successfully.
- **Provider-live acceptance:** NOT EXECUTED in this workspace because no real Bot Token/domain/cPanel disposable credentials were provided. Run the tables above before production enrollment.
- **Fake-completion check:** PASS; unsupported provider behavior is surfaced through capability/error state, not fabricated output.
