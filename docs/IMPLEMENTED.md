# IMPLEMENTED

This inventory names the operational code paths delivered by the project. The one-to-one result for all 95 specification sections is in [ACCEPTANCE.md](ACCEPTANCE.md).

## Installation and lifecycle

| Capability | Implementation evidence |
|---|---|
| Exactly five installer inputs | `public/install.php`; field contract enforced by unit and static tests |
| Server preflight | PHP version, required extensions, project write access, HTTPS and detected public hostname |
| Telegram verification | `getMe`, `setWebhook`, `getWebhookInfo`, `setMyCommands`, `setChatMenuButton` |
| Automatic configuration | Secure random app/encryption/webhook/callback/session/cron keys; atomic `.env`; no user-supplied extra settings |
| Database setup | Ordered, resumable, advisory-locked migrations; idempotent plan and help seeds |
| Runtime storage | Cache, logs, temp, sessions, locks, backups, downloads; restrictive permissions and web denial |
| Installer lock | Filesystem process lock during install and persistent `storage/installed.lock`; no web unlock |
| Version/update | Root `VERSION`, schema history, authenticated CLI check/apply updater, exclusive update lock, atomic metadata and audit |
| Installable package | Clean-commit release builder, internal checksum manifest, external SHA-256, secret scan, ZIP integrity verification, CI artifact |

## Identity, tenants, plans, and sessions

- Public Telegram-user registration keyed by numeric Telegram identity.
- Multiple owner-bound cPanel accounts per user and plan-specific host limits.
- Stored-token mode and encrypted expiring temporary-token mode.
- Token test, rotation, revocation through host removal, and masked administrative presentation.
- Database ownership predicates for accounts, queue jobs, downloads, backups, deployments, sessions, callbacks, confirmations, favorites, notifications, SQL history, and saved queries.
- Language and Beginner/Advanced UX preferences synchronized between Bot and Mini App.
- Plan assignment and limits for hosts, upload size, daily mutations, Database Manager, SQL, backup, and deployment.
- Maintenance mode that exempts super admins and lets in-flight queue work finish.

## Telegram Bot

- `/start`, `/panel`, `/hosts`, `/help`, `/security`, `/settings`, `/cancel`, and super-admin `/admin`.
- First-start Persian/English language choice persisted in the database.
- Seven-stage onboarding with next, previous, skip, and replay.
- Required start menu and one-tap Mini App button.
- Inline keyboards with short opaque callbacks; payloads stay in owner-bound, expiring, one-time server-side callback state.
- Bot session state encrypted at rest and cleaned after expiry.
- Host-add flow accepts only cPanel HTTPS host, username, and API token; the main password is explicitly rejected by design.
- Contextual help, settings, host selection, host health, security overview, administrative entry, file browsing/download, and Telegram document upload flows.
- Asynchronous completion/failure notifications for queued uploads, downloads, archives, SQL transfer, backup, deployment, rollback, and admin broadcast.

## Telegram Mini App

- Mobile-first responsive shell with Telegram theme variables, safe-area support, light/dark modes, large touch targets, focus states, semantic dialogs, keyboard interaction, Back Button, Main Button, haptics, and request cancellation.
- Auth bootstrap from signed Telegram `initData`; opaque Bearer session and separate CSRF token for mutations.
- Host selector and synchronized dashboard with plan, health, notifications, favorites, recent actions, and teaching empty states.
- Real pages for File Manager, Code Editor, Database Manager, Table Browser, SQL Console, Domains/DNS, Email, SSL, Cron, Backup, Deployment, Usage, Logs, PHP, Security, Settings, Help, and Admin.
- All rendered `data-action` controls map to a registered client handler; client host API paths are checked against backend routes.
- 517 matching Persian/English UI keys, localized status and deployment events, three warning levels, contextual descriptions, previews, and teachable API errors.
- 78 database-seeded bilingual help topics with categories, warning level, relations, versioning, search, and shared Bot/Mini App source.

## File Manager and transfers

- Server-side browse pagination, hidden files, name/size/time sorting, parent navigation, metadata, MIME and permissions display.
- Create file/folder, text read in bounded chunks, syntax-aware Ace editor, find/replace/undo/redo/wrap/fullscreen, Save and Save As.
- Move/rename, copy, recursive/danger confirmations, Trash, restore, empty Trash, and search.
- Multi-file browser upload with size/count limits, verified MIME, collision policies, sanitized filenames, and secure temp cleanup.
- Telegram document upload dispatched from the webhook to an encrypted owner-bound queue payload, official Bot API download cap, deterministic collision handling, and cPanel upload.
- Queued cPanel download streamed to managed storage with maximum size and SHA-256; Telegram delivery when size permits and an expiring single-use secure link otherwise.
- File version creation before editor and sensitive writes, history, download, restore with a new pre-restore version, and cleanup.
- Queue-backed archive create/extract. Extraction uses immutable staging, link/path/size/count/ratio validation, checksum comparison, collision policy, one-time overwrite confirmation, non-replayed provider mutation, and reconciliation state.

## Database and SQL

- cPanel database list/create/delete, database users, generated passwords, password rotation/delete, privilege discovery/grant/revoke, and Remote MySQL allowlist management.
- Provider-aware direct MySQL connection automation using a generated least-scope database user and encrypted connection material.
- Table listing and structure; create/rename table; add/change/drop columns; add/drop indexes; optimize, repair, truncate, and drop operations.
- Row browsing with selected columns, stable pagination, filters/operators, order, row details, insert/update/delete, bulk delete, and CSV export.
- SQL classification for read/write/destructive/admin statements; one-statement enforcement, comment/string-aware parsing, result and timeout limits.
- SQL preview/analyze, execution, `EXPLAIN`, optional automatic backup for dangerous statements, target-bound confirmation, history, and saved queries.
- Queue-backed `.sql`/`.sql.gz` import and SQL/CSV export, managed staging, size constraints, checksum validation, streaming parser/writer, downloadable result, and pre-import backup.

## cPanel hosting tools

- Add/delete domains and subdomains, redirects, DNS zone read and serial-bound mass editing.
- Mail accounts, password/quota changes, delete, forwarders, and autoresponders.
- Certificate status/expiry inspection, AutoSSL eligibility and confirmed execution.
- Cron list/create/edit/enable/disable/delete with expression and command validation plus operation-specific warning.
- Disk/bandwidth/resource usage and host/account information.
- Bounded log discovery/tail/search with path and response controls.
- PHP vhost versions and allowed INI directive updates with destructive preview.
- One-tap host health and capability refresh.

## Backup and deployment

- Backup inventory grouped as file/directory versions, database dumps, deployment rollback points, and provider full-account backups.
- Directory and database backups use durable queue jobs linked to persisted backup records; a completion/final-failure tracker updates state, audit, size/checksum metadata, and notifications.
- Full-account backup uses official `Backup/fullbackup_to_homedir`, blocks duplicate active requests, persists before mutation, prevents replay after ambiguous transport, and reconciles provider inventory.
- Owner-bound download, restore, record delete, optional artifact delete, active-job conflict checks, and typed operation previews.
- File, directory, database, and deployment restore engines are real and distinct; unsupported WHM-only full-account restore is explicitly capability-reported instead of simulated.
- Deployment package upload with MIME/ZIP checks, immutable metadata, checksum and safe manifest.
- Eight-stage deployment UI backed by persisted state/events: package, destination, validate, backup, extract, deploy, health check, complete.
- Verified backup before replacement, isolated staging, atomic directory switches, output verification, SSRF-protected health checks, failure rollback, manual rollback, rollback integrity checks, job leasing, operation-lock renewal, recovery attempts, and reconciliation-required stop state.

## Administration and operations

- Dashboard metrics: total/active users, connected hosts, API requests, errors, queue, failed jobs, storage free space, and security alerts.
- Users search/status/ban/suspend/activate; active sessions revoked when access is removed.
- Host inventory without raw tokens, plan editing/assignment, bilingual broadcast queue with per-recipient delivery ledger.
- Tenant and global audit logs, security events, queue and failed-job screens, owner-preserving retry.
- Bounded worker/notification/retention settings and bilingual maintenance messages.
- Health checks for application version, PHP/extensions, database latency/schema, Telegram webhook, encryption round trip, storage, disk, queue, failed jobs, cron heartbeat and recent errors.
- Shared-host cron orchestration and VPS worker mode.

## Security controls

- AES-256-GCM envelopes with random nonce, tag, context AAD, key version, and rotation-ready key ring.
- Telegram `initData` HMAC, time-to-live, replay protection and exact Telegram user binding.
- Opaque Mini App sessions, HMAC-hashed server storage, user-agent binding, CSRF, rotation, expiry, logout, and owner-bound revoke.
- SSRF prevention with scheme/credentials/path/port validation, DNS A/AAAA checking, private/reserved/loopback/metadata blocking, controlled private opt-in, and cURL IP pinning.
- Root-bound path canonicalization, filename sanitation, archive traversal/link and decompression-bomb defenses.
- Atomic database-backed rate limiting and 429 `Retry-After` guidance.
- One-time callback and destructive-confirmation nonces bound to user, account, action, target and expiry.
- TLS peer/hostname verification, HTTPS-only protocol policy, redirects disabled, bounded cPanel/Telegram responses, and non-idempotent no-replay behavior.
- Secret masking in audit/log metadata, safe error codes and bilingual recovery guidance, security event capture, deduplicated super-admin alerting.
- CSP, HSTS, Referrer/Permissions/X-Content-Type policies, installer CSRF/cookie hardening, and denial of web access to internal files.

## Measured repository contracts

The current static verifier checks at least 152 API routes, 128 rendered Mini App action names, 133 handlers, translation parity, 78 contextual topics, 11 migrations, required tables, queue/deployment/backup state contracts, official API compatibility policy, and absence of unfinished markers or token/private-key-shaped values in production files. Exact executed results are recorded in [TESTED.md](TESTED.md).
