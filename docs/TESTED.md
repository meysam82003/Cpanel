# TESTED

This report separates locally deterministic tests, CI integration tests, official API contract checks, and credential-dependent provider tests. A green contract test is not mislabeled as a live cPanel transaction.

## Automated CI gates

The `CI` workflow is required to complete these jobs before its installable artifact is built:

| Job | Runtime | What it executes |
|---|---|---|
| `test (8.2)` | PHP 8.2 | PHP lint, static contracts, all PHPUnit suites; credential-dependent tests skip explicitly |
| `test (8.3)` | PHP 8.3 | Same suite on PHP 8.3 |
| `test (8.4)` | PHP 8.4 | Same suite on PHP 8.4 |
| `installation` | PHP 8.2 + MariaDB 11.4 | Fresh migrations, seeds, help import, engine/collation/FK checks, and idempotent rerun |
| `package` | Ubuntu | Build, secret scan, per-file checksums, ZIP integrity, required-file contract, artifact upload |

Verified release-readiness run:

- GitHub commit: `49d54fcc4cef4cee127751890838a0878e7fec01`
- Workflow: `CI #8`, run `34187466831`.
- PHP suite: 146 PHP files linted; 134 tests, 520 assertions, 3 explicit credential/environment skips.
- MariaDB installation: 1 integration test, 97 assertions on MariaDB 11.4.13.
- Package: secret/integrity build passed; inner release SHA-256 `ab69d6f47530a854692d7d805dfbe2e5bd5d6d93a183addc4e6f533dc07fb769`.
- Uploaded artifact: `telegram-cpanel-manager-49d54fcc4cef4cee127751890838a0878e7fec01`, artifact ID `10041012598`, 691,863 bytes, retained through 2026-10-08.
- All PHP 8.2, 8.3, 8.4, installation, and package jobs: success.

## Unit and security coverage

| Area | Tests / assertions |
|---|---|
| Encryption | AES-GCM round trip, randomized envelopes, context binding, tamper rejection, key versions |
| Tenant ownership / IDOR | Cross-user account lookup and token decryption rejected; session/event/backup/job operations owner-bound |
| Telegram Mini App auth | Correct `initData`, invalid hash, altered data, expiry, replay, opaque session, CSRF, user-agent binding, rotation/revoke |
| Callback/confirmation | Opaque, one-time, expiring state; user/action/target binding; replay rejection |
| SSRF | Localhost, loopback, private/reserved, link-local, metadata and unsafe port/path input blocked; explicit private policy boundaries |
| Paths | Root normalization, encoded traversal, sibling-prefix escape, NUL/control and filename validation |
| Archives | ZIP traversal, absolute path, symlink, excessive expansion/count/size and collision policy; immutable staging and checksum |
| SQL safety | String/comment-aware classification, multi-statement rejection, read/write/destructive modes and confirmation behavior |
| Queue | Dispatch encryption, owner-bound status, atomic reservation tokens, progress lease renewal, retry/final failure and stale recovery |
| Operation locks | Exclusive acquire, owner renewal, expiry/loss behavior |
| Downloads | One-time link, preparation ownership, stream checksum/size, ready/consumed/expired states, cleanup |
| Telegram uploads | File metadata limits, encrypted job payload, collision rules, persistent status and temp cleanup |
| Upload/import confirmations | Browser overwrite nonce bound to canonical path/file set; Telegram double-confirm callback; SQL import nonce bound to database/file metadata; replay rejected |
| Archive queue | Create/extract state, confirmation, handler success/failure and reconciliation |
| Deployment | Intake transaction, package manifest, eight-stage state, backup integrity, atomic switch, health, rollback, lease crash recovery |
| Backup | Inventory classification, file/directory/database/deployment restore contracts, tracker completion/failure/ownership/deduplication |
| Database transfer | Managed-root containment, exclusive staging, checksum mismatch, repeated restore and pre-import backup |
| Rate limiting | Exact limit, safe 429/retry metadata, subject/scope isolation and invalid-policy fail-closed |
| Security Center | Tenant-bound summary and lists for token failure, suspicious requests, sessions, alerts and destructive actions |
| Installer | Exactly five fields, valid input, HTTPS URL detection, safe bilingual errors, concurrent installation lock |
| Migrations | SQL splitting, SQLite transaction behavior, MySQL resumability policy and advisory lock contract |
| Release/update | Semantic version source, installer version consistency, CLI-only authenticated locked updater, audit, secret/package checks |
| cPanel compatibility | Missing-function fallback policy; no fallback for auth/permission/validation; deprecated/nonexistent operation rejection |
| HTTP responses | JSON and download response safety contracts |
| Error guidance | Dedicated bilingual recovery groups including backup and deployment precedence |

## Static end-to-end contracts

`node scripts/verify-static.mjs` verifies relationships that ordinary isolated unit tests can miss:

- Persian and English Mini App keys are identical and every literal `t()` use exists.
- Bot/backend Persian and English key sets are identical.
- Every rendered Mini App `data-action` has a registered handler.
- Every client `hostPath()` candidate matches an actual `/api/v1/` backend route.
- API method/path registrations are unique and meet the expected surface size.
- Every `/api/v1/` response passes through the central audit hook, which records only a route template and safe metadata and excludes body/query data.
- SQL page/per-page inputs reach a bounded read-only result pager; mutating queries cannot request later pages, result bytes remain capped, and later pages do not duplicate history or backups.
- SQL history is optional and contains only fingerprint/type/result/timing metadata; migration 012 scrubs legacy encrypted query text while explicitly saved queries remain encrypted.
- Installer input controls are exactly the required five, with no extra select/textarea.
- Required tables, migrations, queue leases, prepared downloads, upload/archive/deployment/backup recovery state exist.
- Deployment and Backup UI actions connect to their queue/state backend contracts.
- Related help slugs exist, every required category exists, and contextual routes point to real topics.
- Documented cPanel compatibility calls are present; known obsolete/nonexistent UAPI calls are absent.
- Non-idempotent UAPI/API 2 calls cannot iterate multiple resolved IPs.
- Every literal Telegram callback has a handler; web overwrite and SQL import confirmation routes are connected to their exact mutation consumers.
- Production code contains no unfinished marker, Telegram-token-shaped value, or private key block.

The latest pre-documentation result was:

```text
php_files: 108
api_routes: 154
miniapp_actions: 128
action_handlers: 133
translations_per_language: 527
bot_translations_per_language: 116
used_translation_keys: 346
help_topics: 78
migrations: 12
failures: 0
```

## Official cPanel OpenAPI contract

`node scripts/verify-cpanel-openapi.mjs` downloads the current official cPanel OpenAPI specification, enforces HTTPS and an expected response size/content type, calculates its SHA-256, then checks every declared UAPI pair and the explicitly allowed compatibility exceptions.

Most recent execution:

```text
Specification: https://api.docs.cpanel.net/_bundle/specifications/cpanel.openapi.yaml
SHA-256: 632e2f8e6d049a244ac060f6482e39b045fe602647980e7fb041a1863629da85
Bytes: 7,491,133
Checked operations: 68
Failures: 0
Checked at: 2026-09-08T03:34:46Z
```

The specification check confirms API naming/contracts; it cannot prove a particular hosting provider grants a feature.

## MariaDB installation verification

`tests/Integration/MariaDbInstallationTest.php` uses a fresh MariaDB service and verifies:

1. every ordered migration applies;
2. the first and latest migration versions are recorded;
3. all seeds execute;
4. every help topic is imported;
5. four default plans and expected feature flags exist;
6. at least forty application tables exist;
7. all application tables use InnoDB and `utf8mb4` collation;
8. the expected foreign-key topology exists;
9. a second migration run applies nothing;
10. repeated plan/help seeding is idempotent.

See [INSTALLATION_TEST.md](INSTALLATION_TEST.md) for environmental and full-installer boundaries.

## Live cPanel provider suite

`tests/Integration/LiveCpanelTest.php` is intentionally disabled unless all dedicated test-account environment variables are supplied. It validates a connection and selected read-only API capabilities without embedding credentials. Destructive provider operations require an explicitly disposable account and manual acceptance matrix.

Not executed in public CI:

- real Telegram Bot token registration and reception of a webhook;
- real user cPanel token against a named provider;
- destructive live domain/email/cron/database/file mutations;
- provider-generated full backup completion time;
- provider-specific external MySQL firewall behavior.

Those are reported as environmental validation requirements, not silently counted as automated passes.

## Commands

```bash
composer test
composer test:unit
composer test:security
composer test:integration
node scripts/verify-static.mjs
node scripts/verify-cpanel-openapi.mjs
scripts/build-release.sh
```

For a dedicated live account, provide only environment-scoped credentials documented by `LiveCpanelTest`; never commit them or paste them into test fixtures.
