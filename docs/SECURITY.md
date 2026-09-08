# SECURITY CHECKS

## Security boundary

The service is multi-tenant. Telegram identity proves the application user; a cPanel API token authorizes actions on one connected hosting account. The application server, its database, `.env`, and runtime storage are trusted infrastructure. User-supplied hostnames, paths, archives, SQL, file content, Telegram updates, Mini App requests, and cPanel responses are untrusted.

The super admin can manage service users/plans/operations but cannot retrieve raw cPanel tokens through UI or API.

## Control matrix

| Threat | Implemented control | Verification |
|---|---|---|
| Cross-tenant ID access | Every resource lookup includes `user_id`; account-scoped operations resolve an owner-bound connection; foreign IDs return safe 404 | Ownership, Security Center, sessions, queue, backup and download tests; route/service review |
| cPanel token disclosure | AES-256-GCM at rest, random nonce/tag, context AAD, key version; raw token omitted from listing/admin responses | Crypto and account-ownership tests; response contracts |
| Temporary token persistence | Encrypted temporary row with expiry; cleanup; no raw token in archive/upload state | Account repository and cleanup contracts |
| Telegram Mini App forgery | Telegram-defined HMAC validation, constant-time comparison, auth-date TTL, exact user JSON validation | `TelegramInitDataValidatorTest` |
| Replay of Telegram auth | HMAC-derived nonce persisted once with expiry | `TelegramInitDataValidatorTest` and migration contract |
| Session theft / CSRF | 256-bit opaque token, HMAC-hashed storage, expiry, user-agent binding, separate CSRF for mutations, rotation/logout/revoke | `MiniAppSessionTest`; API kernel contract |
| Callback tampering | 22-character opaque ID; server-side encrypted payload; owner/action/expiry/one-use validation | `OneTimeStateTest` |
| Destructive replay | One-time confirmation bound to user, account, action, target hash and preview; consumed atomically | `OneTimeStateTest`; route contracts |
| SSRF | HTTPS only, no embedded credentials/query/fragment/path, port allowlist, A/AAAA resolution, private/reserved/loopback/link-local/metadata block, DNS-to-cURL pinning, redirects disabled | `HostValidatorTest`; cPanel client configuration review |
| DNS rebinding | Each request resolves under policy and uses `CURLOPT_RESOLVE` for the validated IP; redirects cannot escape | Host/client tests and static contract |
| Unsafe private-host policy | Opt-in allows only RFC1918/ULA ranges; loopback/link-local/metadata stay blocked | `HostValidatorTest` |
| Path traversal | Canonical account home root, decoded separator/control checks, boundary-aware prefix comparison, filename sanitation | `PathGuardTest`; archive tests |
| Archive traversal / link attack | Central-directory inspection, absolute/drive/traversal/link rejection, file count/expanded size/ratio limits, immutable staged copy, checksum match | `ArchiveSafetyValidatorTest`, `ArchiveServiceTest`, queue handler contracts |
| ZIP deployment bomb | Same archive validator plus deployment top-level and manifest limits; isolated remote staging | Deployment intake/handler tests |
| SQL injection in manager | Identifiers quoted after server metadata/strict validation; row keys parameterized; filter/operator allowlists | Data-manager code contract and SQL safety tests |
| Dangerous arbitrary SQL | One statement, classifier, read-only pagination and byte limits, destructive preview, one-time confirmation, optional/automatic backup; optional history stores no query text | `SqlSafetyAnalyzerTest`; coordinator/routes/static privacy contract |
| Import path escape | Only uploaded or managed backup roots, realpath containment, exclusive staging creation, size and SHA-256 check | `SqlTransferServiceTest` |
| Upload abuse | Count and byte caps, PHP upload error check, random temp name, `finfo` MIME, sanitized destination name, plan quota, cleanup | Upload receiver and Telegram upload tests |
| Download exfiltration | Owner-bound request, managed preparation path, max bytes, checksum, random one-time token, expiry/consume state | `DownloadServiceTest` |
| API flooding | Atomic database buckets for global/user/route/public/webhook/download scopes; safe 429 and `Retry-After` | `RateLimiterTest`; API kernel |
| Duplicate provider mutation | Non-idempotent UAPI/API 2 gets one attempt and the first validated IP only; async full backup persists before dispatch | cPanel compatibility/static tests; backup tests |
| Queue double execution | Atomic reservation token, owner-bound lease, renewal on progress, completion/failure requires same reservation | `QueueServiceTest`; operation lock tests |
| Crash during deploy/restore | Durable stage state, filesystem checkpoints, verified rollback, bounded recovery attempts, reconciliation stop for ambiguity | Deployment handler/recovery/rollback and backup lifecycle tests |
| Secret in logs | Central recursive masking for token/password/key/authorization/cookie patterns; raw exception hidden from public installer/API | `SecretMaskerTest`, installer contract, logger/error response review |
| Secret in release | Clean Git archive, token/private-key scan, explicit `.env`/installed-lock rejection, internal/external SHA-256 | `ReleaseContractTest`; CI package job |
| Webhook spoofing | Secret in both randomized path and Telegram secret header, constant-time equality, JSON-only POST, update ID dedupe | Application webhook code and route/static contracts |
| Clickjacking / browser injection | CSP, Telegram-only frame ancestors, object blocking, HSTS, nosniff, referrer and permissions policies, output escaping | Front controllers, `.htaccess`, Mini App escaping contract |
| Installer attack | HTTPS, same-site secure session cookie, CSRF, exact five-field allowlist, process lock, persistent lock, safe bilingual errors | `InstallerContractTest`; MariaDB install test |
| Super-admin abuse | Explicit role check per admin service call; tokens excluded; actions audited; cannot ban super admin | Admin routes/service tests and review |

## Encryption and key handling

- `ENCRYPTION_KEY_V1` is generated from 32 cryptographically secure random bytes and stays outside the database.
- Envelopes include version, nonce, authentication tag, ciphertext, and context-bound AAD. Moving an encrypted token to another user/account context fails authentication.
- `ENCRYPTION_CURRENT_VERSION` selects the write key; older keys can remain available for reading during controlled rotation.
- `APP_KEY`, webhook, callback, session and cron secrets are independently generated.
- `.env` is written through an application-owned temporary file, atomically renamed, chmodded to `0600`, ignored by Git and denied by web rules.
- Database password Base64 is only transport-safe encoding for `.env`, not encryption. Filesystem and web-server isolation are mandatory.

## SSRF policy details

Default allowed cPanel ports are 2083 and 443. `ALLOW_PRIVATE_CPANEL_HOSTS=false` is generated by the installer. When an administrator explicitly enables private accounts, only RFC1918 IPv4 and ULA IPv6 pass; localhost, `169.254.0.0/16`, cloud metadata, multicast, unspecified, reserved and other link-local targets do not.

The health-check service uses an independent validator restricted to public HTTPS port 443. A cPanel token is never forwarded to a deployment health URL.

## Path and artifact policy

- All cPanel paths are resolved against the account root reported by cPanel and compared on a directory boundary, not a string prefix alone.
- Application-local restore/import paths must resolve below explicitly configured managed roots.
- Staging files use unpredictable names and exclusive creation where collision would be unsafe.
- Download, archive, database dump, package and backup content is size-bounded and checksum-bound at the stages where integrity matters.
- Symlinks are not followed for package/delete cleanup paths.

## Audit and alert behavior

Audit entries contain actor, account, action, target, safe result, request identifier and masked metadata. A fail-safe central hook covers every `/api/v1/` response with its route template, method, status, duration and validated IP; it never records request bodies or query values. Security events record a non-secret fingerprint and safe request metadata. Rate-limit, SSRF, session/initData, callback, ownership and authentication anomalies trigger security-event handling; high-signal notifications to active super admins are deduplicated over a short window.

Security Center exposes only the current tenant's hosts, failed-token count, suspicious-event count, destructive actions, sessions and alerts. Admin Security/Audit offers service-wide inspection without decrypted tokens.

## Security test result

- PHP lint and PHPUnit security/unit suites: successful on PHP 8.2, 8.3 and 8.4 in `CI #8` (134 tests, 520 assertions per matrix run, 3 explicit environment skips).
- Rate limiter, Security Center isolation and MariaDB installation security checks: successful in `CI #8`.
- Official cPanel OpenAPI operation contract: 68 checked operations, zero failures at the recorded check.
- Static secret/unfinished-marker scan: zero failures.
- Release ZIP secret scan, internal/external checksum and required-file verification: successful; CI package SHA-256 `ab69d6f47530a854692d7d805dfbe2e5bd5d6d93a183addc4e6f533dc07fb769`.

See [TESTED.md](TESTED.md) for test counts and [LIMITATIONS.md](LIMITATIONS.md) for environmental controls that application code cannot guarantee.

## Deployment checklist

- Use an HTTPS-only dedicated domain/subdomain and keep `.htaccess` enabled.
- Restrict `.env` and `storage` to the hosting account; verify direct HTTP requests return denial.
- Do not enable private cPanel hosts unless the service intentionally manages a private network.
- Grant each cPanel token only the functions required by that user.
- Configure the one-minute cron, watch Admin Health and failed jobs, and retain protected audit/security logs.
- Rotate a token immediately after suspected disclosure; revoke Mini App sessions and review recent destructive actions.
- Back up `.env` securely and separately. Losing the encryption key makes encrypted cPanel tokens intentionally unrecoverable.
- Never publish a production database dump, `.env`, `storage/installed.lock`, queue payload or log bundle.

## Reporting a vulnerability

Do not open a public issue containing a token, exploit payload, user data or hosting detail. Use the repository's private security advisory channel or contact the repository owner privately. Include the affected version, safe reproduction steps and request IDs with secrets removed.
