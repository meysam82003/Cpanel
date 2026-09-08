# INSTALLATION TEST

## Automated result

The database portion of a fresh install and a safe repeated run are executed in GitHub Actions against a real MariaDB service, not SQLite or a mocked query layer.

Verified baseline:

| Item | Result |
|---|---|
| Workflow | CI #6, run `34185610108` |
| Commit | `40a952884baac49f432362dc9075a74edb29f3ba` |
| Database image | MariaDB 11.4.13 |
| Test | `tests/Integration/MariaDbInstallationTest.php` |
| Result | PASS — 1 test, 97 assertions |
| Ordered migrations | 11 applied on fresh schema |
| Repeated migration | 0 newly applied; PASS |
| Plans | 4, with expected backup/deployment flags |
| Help catalog | 78 topics inserted and idempotently refreshed |
| Storage engine | Every application base table InnoDB |
| Character set | Every application table uses an `utf8mb4_*` collation |
| Foreign-key topology | More than 20 constraints present |

The release-readiness commit adds more tests and the package artifact gate. Its final run supersedes this baseline at handoff.

## Web Installer contract verified automatically

- Page renders exactly five user-supplied controls: Bot Token, Super Admin Telegram ID, DB Username, DB Password, DB Name.
- There is no select or textarea that requests hidden configuration.
- Request handling copies only the five allowlisted keys plus CSRF.
- PHP 8.2 and required extension checks are displayed bilingually.
- HTTPS and public host/port validation fail closed.
- Database host is fixed to `localhost`, as specified.
- Token format and numeric administrator identity are validated before network/database mutation.
- Concurrent installation is blocked with a nonblocking filesystem lock.
- Runtime directories and denial rules are created before configuration use.
- `.env` is written through a restricted temporary file and atomic rename.
- Migration execution uses a MySQL advisory lock and resumable duplicate-column/index handling.
- Telegram success requires `getMe`, webhook registration, commands/menu button registration, and matching `getWebhookInfo` URL.
- Persistent install lock is the final step; there is no network unlock path.
- Public errors are safe and bilingual and include a request reference, while raw exceptions go only to protected masked logs.

## What cannot be run without deployment credentials

A literal full Web Installer acceptance run needs:

1. a public HTTPS domain under the deployment owner's control;
2. an actual Telegram Bot Token;
3. the intended super admin's Telegram Numeric ID;
4. a real hosting MySQL/MariaDB database/user/password;
5. outbound Telegram connectivity.

Those values were not committed or injected into public CI. Therefore public automation does not claim that a particular domain received a real Telegram webhook. This is a credential/environment boundary, not a replacement with a mock installer.

## Deployment-owner installation acceptance procedure

Run on a new domain and disposable Telegram bot before production:

1. Download the CI/release ZIP and its `.sha256` file.
2. Run `sha256sum -c telegram-cpanel-manager-<version>.zip.sha256`.
3. Extract and confirm `.htaccess`, `VERSION`, `composer.json`, `public/install.php`, all migrations and `CHECKSUMS.sha256` exist.
4. Ensure direct HTTP requests to `/.env`, `/app/`, `/database/`, `/storage/`, `/tests/` and `/cli/` are denied.
5. Open `/install` over HTTPS and confirm exactly five fields.
6. First submit an invalid Bot Token and verify a bilingual safe error with no token echo.
7. Submit valid values and verify all displayed stages succeed.
8. Confirm `.env` mode is restrictive where the filesystem supports POSIX mode and it is denied over HTTP.
9. Confirm `storage/installed.lock` exists, `/install` reports locked, and no unlock button/API exists.
10. Check Telegram `getWebhookInfo` indirectly through Admin → Health: reachable and URL match must be true.
11. Add the exact one-minute Cron command and wait for a healthy heartbeat.
12. Send `/start`, choose both FA and EN in separate test users, complete onboarding, then open `/panel`.
13. Run the live application matrix in [ACCEPTANCE.md](ACCEPTANCE.md) against a disposable cPanel account.

Record the provider name, cPanel version, PHP version, MariaDB/MySQL version, timezone, test user IDs, start/end time, and safe request IDs. Never paste tokens or `.env` into the record.

## Update acceptance procedure

1. Enable Maintenance and create verified file/database backups.
2. Preserve `.env` and `storage` while extracting the new ZIP.
3. Run `php cli/update.php --secret='<existing-cron-secret>' --check`; exit code 2 means migrations are pending.
4. Run apply mode once. A simultaneous second process must fail with `update_failed` and must not duplicate a migration.
5. Run check mode again; it must return no pending migrations and exit 0.
6. Confirm the installation lock's `app_version` matches `VERSION` and an anonymized `system.update` audit row exists.
7. Verify Admin Health and disable Maintenance.

## Failure recovery expectations

- Before `.env` exists, correcting an input and retrying is supported; migrations are resumable.
- If Telegram setup fails, no install lock is written. Fix HTTPS/network/token and retry.
- If the final lock cannot be written, the installer reports failure; resolve storage permissions and retry. Do not expose an unlocked partially configured installation to users.
- If an update migration fails, keep Maintenance enabled, inspect the protected server log, restore the verified database backup if necessary, and do not delete schema history manually.
