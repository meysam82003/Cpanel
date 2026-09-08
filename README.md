# Telegram cPanel Manager

Production-oriented, multi-tenant cPanel management through a Telegram Bot and a mobile-first Telegram Mini App.

[راهنمای کامل فارسی](README.fa.md) · [Implementation inventory](docs/IMPLEMENTED.md) · [Test report](docs/TESTED.md) · [Security report](docs/SECURITY.md) · [Acceptance matrix](docs/ACCEPTANCE.md) · [Real limitations](docs/LIMITATIONS.md)

## What is included

- Telegram Bot with first-start language selection, seven-step onboarding, contextual help, server-side callback state, session-aware navigation, and the required commands.
- Telegram Mini App with real versioned APIs for hosts, files, code editing, databases, SQL, domains, email, SSL, cron, backup, deployment, security, settings, and super-admin operations.
- Five-field Web Installer that creates all application secrets and configuration, migrates and seeds MariaDB/MySQL, verifies Telegram, registers the webhook, commands, and menu button, then locks itself.
- Multi-tenant backend with cPanel UAPI, documented compatibility bridges where no UAPI equivalent exists, capability detection, queue/cron processing, audit logs, plans, notifications, and bilingual error guidance.
- Secure file transfer, archive validation, database import/export, backup/restore, atomic deployment, verified rollback, and crash recovery.

No cPanel account password is requested. Users connect with a cPanel API token, either encrypted at rest or held in an encrypted, expiring temporary connection.

## Requirements

| Requirement | Minimum / note |
|---|---|
| PHP | 8.2 or newer |
| Database | MySQL 8+ or MariaDB 10.6+; InnoDB and `utf8mb4` |
| PHP extensions | `curl`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `zip`, `zlib` |
| Web server | Apache or LiteSpeed with `.htaccess`; Nginx works with equivalent routing rules |
| TLS | A publicly trusted HTTPS certificate is mandatory for Telegram webhooks and Mini Apps |
| Outbound network | HTTPS access to Telegram and the users' cPanel endpoints |
| Telegram | A bot token from `@BotFather` and the numeric Telegram ID of the super admin |
| Scheduler | One-minute cPanel Cron Job, or a continuously supervised worker on a VPS |

The release ZIP contains the runtime code and does not require Composer on the hosting account. `composer.json` remains the canonical dependency and PSR-4 configuration for development and CI.

## Install on shared cPanel hosting

1. In cPanel, create a database and database user, assign the user to the database, and grant all privileges on that application database.
2. Point an HTTPS domain or subdomain to an empty directory. Extract the release ZIP into that directory. Keep `.htaccess` intact.
3. Make the project directory writable by its PHP process for installation. Normal files should be `0644`, directories `0755`; runtime storage is changed to restrictive permissions by the installer.
4. Open `https://your-domain.example/install`.
5. Enter exactly these five values:

   | # | Installer field |
   |---|---|
   | 1 | Telegram Bot Token |
   | 2 | Super Admin Telegram Numeric ID |
   | 3 | Database Username |
   | 4 | Database Password |
   | 5 | Database Name |

6. Let the installer finish every displayed verification step. It automatically uses `localhost:3306`, detects the public HTTPS URL, generates all security keys, creates `.env`, runs all migrations and seeds, registers the super admin, configures Telegram, and writes `storage/installed.lock`.
7. Copy the exact Cron command shown on the success screen into **cPanel → Cron Jobs**, scheduled once per minute.
8. Open `/telegram-setup` and follow the bilingual BotFather checklist. The installer already registers the webhook, commands, and menu button; the page also provides the detected Mini App URL for BotFather interfaces that require manual Main Mini App configuration.
9. Open the bot, send `/start`, select a language, and complete or intentionally skip the onboarding wizard.

The installer has no remote unlock control. Reinstallation requires the hosting owner to remove `storage/installed.lock` manually. Do not remove it from an active installation.

## What the installer creates

- `APP_KEY`, AES-256-GCM master encryption key and key version.
- Webhook, callback, Mini App session, and cron secrets.
- Secure `.env` with the database password encoded for line-safe storage; file access is blocked by web-server rules.
- `storage/cache`, `logs`, `temp`, `sessions`, `locks`, `backups`, and `downloads` with restrictive access.
- Every schema migration, default plan, and the bilingual help catalog.
- Telegram webhook with a secret header/path, bot commands, and Mini App menu button.
- Super-admin user and an installer lock.

Secrets are never committed, returned by APIs, or written to application logs. The database password encoding in `.env` is not presented as encryption; the `.env` file itself must remain outside web access and readable only by the hosting account.

## cPanel API token creation

Menu names vary slightly by provider and cPanel theme:

1. Sign in to cPanel over HTTPS.
2. Open **Security → Manage API Tokens** (sometimes named **Manage API Tokens** directly).
3. Select **Create**.
4. Give the token a recognizable name and, where offered, the narrowest privileges and an expiry suitable for the account.
5. Create and copy the token once.
6. In the bot or Mini App, add only the cPanel HTTPS host, cPanel username, and API token. Never enter the main cPanel password.
7. Choose encrypted storage or the 30-minute encrypted temporary mode.

If the provider disables API tokens or a specific cPanel module, the host connection or capability probe reports that fact with bilingual guidance. The feature remains in the application and becomes available after the provider enables the corresponding capability.

## Bot commands

| Command | Purpose |
|---|---|
| `/start` | Language selection, onboarding, and synchronized start screen |
| `/panel` | Open the Telegram Mini App |
| `/hosts` | List or select the user's cPanel accounts |
| `/help` | Contextual, searchable bilingual help |
| `/security` | Security status and alerts |
| `/settings` | Language and UX mode |
| `/cancel` | Cancel the current conversational flow |
| `/admin` | Super-admin panel; rejected for ordinary users |

## Queue and cron

The shared-hosting path is the exact command generated by the installer, run every minute. It authenticates with the generated cron secret and performs a bounded batch of:

- queued jobs and lease recovery;
- pending full-backup reconciliation;
- Telegram notification delivery;
- expired sessions, tokens, temporary files, and retained-log cleanup;
- heartbeat storage used by Admin Health.

For a VPS, a process supervisor may run:

```bash
php cli/worker.php --queue=default --max-jobs=100 --max-seconds=300
```

Keep the one-minute cron runner enabled even with a continuous worker because reconciliation, notifications, cleanup, and heartbeat are cron responsibilities. Never expose `cli/` through the web server; the repository `.htaccess` blocks it and every CLI entry point also verifies `PHP_SAPI`.

## File Manager

The File Manager operates inside the selected account's detected home root. It supports server-side pagination and sorting, hidden files, metadata, upload, secure queued download, create/edit/save-as, rename/move/copy, Trash, restore, permanent actions with one-time confirmation, version history, archive creation, and validated extraction.

Archive extraction downloads an immutable inspection copy, blocks traversal, links, absolute paths, unsafe expansion ratios, excessive file counts and size, verifies integrity, and then submits one non-replayed provider mutation. Large work runs through durable owner-bound queue jobs.

## Database Manager and SQL

- cPanel database, user, password, privilege, and Remote MySQL management.
- Automated limited direct database connection for table browsing when the provider allows remote MySQL.
- Table structure, columns, indexes, rows, filtering, stable pagination, CRUD, bulk delete, and CSV export.
- SQL editor with statement classification, one-statement policy, result limits, history, saved queries, `EXPLAIN`, destructive preview, one-time confirmation, and optional pre-operation backup.
- Queued SQL import/export with upload limits, managed staging, checksums, gzip support, and downloadable artifacts.

Remote data browsing is inherently provider-dependent. cPanel may manage databases while its firewall blocks external MySQL, or the database hostname may differ from the cPanel hostname. In that case cPanel-level database management remains available, while direct table/SQL features are reported as unavailable until the provider permits the connection.

## Backup and deployment

The Backup Center groups file versions, directory archives, database dumps, deployment rollback points, and provider full-account backups. Actions are derived from persisted state: download, restore, and delete are displayed only when valid for that artifact.

- File restore creates a new pre-restore version.
- Directory restore validates the archive and runs through the durable extraction queue.
- Database restore copies only from managed roots into exclusive staging, checks SHA-256, and creates a fresh pre-restore backup.
- Deployment restore invokes the same verified rollback engine as Deployment Center.
- Full backup uses the official asynchronous cPanel operation and reconciles provider state without replaying an ambiguous request.

Deployment validates ZIP structure and checksum, creates a verified backup, transfers to isolated staging, extracts safely, atomically switches releases where the cPanel filesystem permits it, runs an SSRF-protected health check, and records every state transition. Failures trigger verified rollback or stop in `reconciliation_required` rather than guessing the provider outcome.

## Security model

- AES-256-GCM token encryption with random nonces, authentication tags, associated context, and key versioning.
- Ownership predicates on tenant resources and indistinguishable 404 responses for cross-tenant IDs.
- Telegram Mini App `initData` HMAC validation, age limit, replay nonce, server-issued opaque session, device binding, CSRF, expiry, and revocation.
- cPanel SSRF defense: HTTPS only, allowed ports, DNS resolution checks, private/reserved/loopback/metadata blocking, and DNS-to-cURL IP pinning. Private cPanel ranges are opt-in administrative policy and still exclude loopback/link-local/metadata space.
- Root-bound path normalization; archive and deployment traversal/link controls.
- Global, user, route, webhook, installer, and download rate limits.
- One-time, target-bound destructive confirmations and non-idempotent cPanel request replay prevention.
- Secret masking, response-size and transfer-size limits, TLS verification, sanitized errors, security events, super-admin alerts, and audit records.
- CSP, frame policy for Telegram, HSTS on HTTPS, no directory listing, and web denial for private directories/files.

See [docs/SECURITY.md](docs/SECURITY.md) for the control/evidence matrix and reporting guidance.

## Capability detection

After a connection test, the backend probes files, MySQL, domains/DNS, email, SSL, cron, backup, usage, and PHP capabilities. The Mini App consumes the persisted result and disables only unsupported mutations. Read-only and unavailable states are different. Provider limitations do not remove code paths from the project.

Some documented cPanel tasks still have no UAPI equivalent. Those operations use a centralized API 2 compatibility bridge with the same TLS, SSRF, timeout, response-limit, error, and no-replay controls. Exact calls are guarded by the cPanel contract verification script.

## Updating

1. Enable Maintenance in the super-admin panel.
2. Create and verify an application-file backup and a database backup.
3. Verify the release ZIP checksum.
4. Extract the new release over the application while preserving `.env`, all of `storage/`, and `storage/installed.lock`.
5. From cPanel Terminal or SSH, run the updater with the existing cron secret:

   ```bash
   php cli/update.php --secret='<existing-cron-secret>' --check
   php cli/update.php --secret='<existing-cron-secret>'
   ```

   Check mode exits `0` when no migration is pending and `2` when an update has pending migrations. Apply mode takes an exclusive lock, runs resumable migrations, refreshes plans and help, atomically updates installation metadata, and writes an audit event.
6. Open Admin → Health, verify database schema versions, storage, encryption, queue, cron heartbeat, Telegram webhook, and PHP extensions.
7. Disable Maintenance and run a host health check.

Do not replace `.env` with `.env.example`, do not copy secrets between installations, and do not delete active deployment/backup state during an update.

## Build and verify an installable ZIP

From a clean Git checkout:

```bash
scripts/build-release.sh
sha256sum -c dist/telegram-cpanel-manager-1.0.0.zip.sha256
```

The builder archives only committed source, removes CI/dev-only files, creates protected runtime directories, adds a per-file checksum manifest, normalizes timestamps, scans for token/private-key patterns, rejects `.env` and install locks, tests the ZIP, and writes an external SHA-256 file. CI performs the same build after all PHP and MariaDB jobs pass and publishes it as a workflow artifact.

## Development and tests

```bash
composer install
composer test
composer test:security
composer test:integration
composer test:cpanel-contract
```

CI executes lint, static UI/API/i18n/help/schema contracts, PHPUnit on PHP 8.2/8.3/8.4, a fresh-and-repeated installation on MariaDB 11.4, and the release-package verifier. The live cPanel integration suite is opt-in and requires a dedicated disposable test account; it never runs with production credentials.

See [docs/TESTED.md](docs/TESTED.md) and [docs/INSTALLATION_TEST.md](docs/INSTALLATION_TEST.md) for exact coverage and the distinction between automated, contract, and provider-live tests.

## Troubleshooting

### Installer cannot connect to the database

Confirm that the database and user already exist, the user is assigned to that database, privileges are granted, and the hosting account accepts `localhost:3306`. The installer intentionally does not ask for a database host.

### Telegram setup fails

Verify public HTTPS, a trusted certificate, outbound TCP 443, the bot token, and that a proxy preserves the detected host. Retry installation before it locks. After installation, Admin → Health compares Telegram's registered webhook URL with the generated one.

### Mini App says authentication expired

Close it and reopen from the bot's `/panel` or menu button. Sessions expire, bind to the browser user agent, require CSRF for mutations, and can be revoked from Security Center.

### Host connects but a section is unavailable

Run Host Health again and inspect capability details. Confirm the token privilege and ask the provider whether the cPanel module/API is enabled. The UI does not fabricate provider support.

### Queue is not moving

Check the one-minute cron entry and Admin → Health heartbeat. Use Admin → Queue / Failed Jobs for safe errors and retry eligible failed jobs. On shared hosting, keep each run bounded; on VPS, add the supervised worker while retaining cron.

### Direct database browsing fails

Use cPanel-level database management first. Then allow the application server's public IP in Remote MySQL, confirm the database hostname/port with the provider, and avoid `%` unless the security exposure is explicitly accepted.

### Deployment needs attention

Do not manually repeat a provider mutation. Open its persisted timeline. `reconciliation_required` means the backend could not prove the outcome and deliberately stopped; use only a verified rollback point or inspect the account before proceeding.

## Repository layout

| Path | Responsibility |
|---|---|
| `app/` | Domain services, security, cPanel client, queue handlers, bot/backend logic |
| `routes/api/` | Versioned authenticated Mini App API |
| `public/` | Web front controller, installer, Telegram setup, Mini App assets |
| `database/` | Ordered migrations and idempotent seeds |
| `resources/help/` | Shared bilingual contextual-help source of truth |
| `resources/lang/` | Bot/backend translations |
| `cli/` | Queue worker, cron orchestration, authenticated updater |
| `tests/` | Unit, security, integration, and conditional live-provider tests |
| `scripts/` | Lint, static contracts, official cPanel contract verification, release builder |
| `docs/` | Implementation, testing, installation, security, limitation, and acceptance reports |

## Operational limitations

Provider APIs and shared-hosting policies differ. Full-account restore commonly requires WHM/root and is therefore detected and reported rather than simulated; Remote MySQL may be blocked; AutoSSL, DNS, PHP selector, cron, and backup modules may be restricted; shared-hosting process/runtime limits bound job batch sizes. These constraints and safe fallbacks are listed precisely in [docs/LIMITATIONS.md](docs/LIMITATIONS.md).

This repository contains no real token, password, encryption key, or installed configuration.
