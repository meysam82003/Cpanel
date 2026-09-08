# LIMITATIONS

These are real operational limits. None is hidden behind a fake button or a fabricated success response.

## Application-level limitations

1. **No bundled hosting credentials.** A production Telegram bot, public HTTPS domain, application database, and at least one real cPanel API token must be supplied at deployment. Public CI cannot perform those credential-dependent calls.
2. **Full-account restore is not impersonated.** The application can request and reconcile a cPanel full-account backup. Restoring an entire account generally needs WHM/root or provider support unavailable to a cPanel user token. The UI reports this capability limitation; file, directory, database and deployment restores remain operational.
3. **Ambiguous non-idempotent results stop safely.** If a network failure occurs after a provider may have accepted a destructive request, the system does not blindly repeat it. Backup/deployment state becomes pending reconciliation or `reconciliation_required`, which may need an administrator to inspect the account.
4. **Text editing is bounded.** The code editor rejects files over 4 MiB and reads in bounded chunks. Binary or larger content must use secure download/upload tools.
5. **Transfer and archive limits are configured.** Defaults protect shared hosting from memory/disk exhaustion. Administrators may adjust environment limits only after evaluating PHP, disk and provider constraints.
6. **Queue concurrency is cooperative.** Database leases and locks prevent normal duplicate work, but a hosting provider that kills PHP and corrupts its database/filesystem can still require recovery from a verified external backup.
7. **No browser-based remote installer unlock.** Reinstallation requires filesystem access and manual removal of the lock. This is intentional.
8. **Updater applies application migrations, not hosting-platform upgrades.** PHP, extensions, database server and web-server configuration remain the hosting administrator's responsibility.
9. **Audit retention is finite.** Cleanup follows the configured retention. Export important compliance records before expiry.
10. **No promise of zero downtime on every filesystem.** Atomic deployment uses same-account directory renames where supported. Provider filesystem behavior and locks can force safe rollback/reconciliation rather than a proven zero-downtime switch.

# KNOWN HOSTING-SPECIFIC LIMITATIONS

## cPanel API tokens and Feature Manager

- Some resellers disable **Manage API Tokens**, limit token privileges, or hide UAPI modules through Feature Manager. The connection test or capability detector reports unavailable/read-only state.
- A token cannot exceed the privileges and features of its cPanel account. Super-admin status in this application does not elevate cPanel permissions.
- cPanel versions and themes differ. The built-in token tutorial describes common menu names, but a provider may rename or remove the interface.

## UAPI versus API 2 compatibility

The project prefers current official UAPI. cPanel still documents operations without a practical UAPI equivalent on some versions, notably parts of Cron and Fileman archive/file operations. Those calls use one centralized API 2 bridge. A provider may disable API 2 even while related read APIs work; capability/error guidance then reports the limitation. The application never silently substitutes an unrelated API.

## Remote MySQL and Data Manager

- cPanel database creation may work while outbound or inbound TCP 3306 is blocked.
- The database server hostname can differ from the cPanel hostname and may be reachable only inside the hosting network.
- Shared hosts often require the application server's public IP in **Remote MySQL**; dynamic/NAT egress can change that IP.
- `%` allows every source and is deliberately warned against.
- When direct access is unavailable, cPanel-level database/user/privilege management continues, but table browsing, arbitrary SQL and streamed import/export that need PDO cannot operate until connectivity is allowed.

## Backup providers

- Provider systems such as JetBackup are not a single stable cPanel UAPI surface. Native provider snapshots may therefore not appear in the application's full-backup inventory unless exposed through supported cPanel operations.
- `fullbackup_to_homedir` is asynchronous, consumes the user's hosting quota, and completion time is provider-controlled.
- Full-account restore commonly requires provider support or WHM. The project does not claim root capability.
- Providers can disable full backup, retain it elsewhere, rename generated archives, or restrict backup frequency. Reconciliation shows the observed state and safe error.

## DNS, domains and SSL

- DNS editing is possible only when the account controls the zone through cPanel. External DNS providers must be managed externally.
- Reseller limits may block addon domains, subdomains, redirects, or individual DNS record types.
- AutoSSL eligibility and execution depend on the configured cPanel AutoSSL provider, DNS validation, DCV reachability, account policy and rate limits. The application cannot issue a certificate when cPanel itself refuses it.
- Certificate installation outside the exposed UAPI surface may require the hosting provider.

## Email

- Mailbox quotas, domains, forwarders and autoresponders depend on the provider's mail role and Feature Manager.
- External mail hosting or disabled local mail roles can make cPanel email APIs unavailable.
- Deliverability, DNS reputation, spam filtering and outbound message limits are outside this project's control.

## PHP selector and logs

- MultiPHP Manager/LangPHP can be absent or replaced by CloudLinux PHP Selector. Unsupported version/INI operations are capability-reported.
- Providers expose different log names and retention. Log Viewer uses a safe allowlist/discovery strategy and cannot read files outside the cPanel user's home permissions.

## Cron and long-running jobs

- Shared hosts may enforce minimum cron intervals, CPU quotas, execution time, process count and memory/disk limits.
- The one-minute runner uses bounded batches and durable state. Backlogs can take multiple runs to drain.
- Providers can disable shell execution or use a nonstandard PHP binary. Use the exact command detected by the installer and confirm the binary in cPanel.
- VPS deployments should supervise `cli/worker.php`, but must keep the cron runner for reconciliation, notifications, cleanup and heartbeat.

## Filesystem behavior

- Account home paths, Trash support, quota reporting and archive functions vary by cPanel version/provider.
- Very large directories can take multiple pages or queue cycles; the UI intentionally does not retrieve all content at once.
- Atomic rename is guaranteed only within a filesystem that provides atomic same-filesystem directory rename. Cross-mount destinations are rejected or safely fail.
- Antivirus/WAF/mod_security can quarantine uploads or block API request bodies even after application validation.

## Web-server routing

- The distributed `.htaccess` targets Apache/LiteSpeed, the standard cPanel path. If `.htaccess` overrides are disabled, the provider must install equivalent rewrite and denial rules.
- Nginx VPS installations require explicit routing to `public/index.php`, static Mini App routes, and denial for `.env`, `storage`, `app`, `database`, `tests`, `cli` and other internal paths.
- Reverse proxies must provide the correct public HTTPS host. Misconfigured forwarded headers can make installer URL detection fail closed.

## Telegram/network policy

- The hosting network must reach `api.telegram.org` over verified HTTPS. Some jurisdictions/providers block it.
- Telegram controls Bot API and Mini App client limits, including file size and `initData` semantics.
- Webhook delivery requires a publicly reachable trusted TLS certificate; private/self-signed endpoints are not accepted by the normal installation flow.

## Acceptance boundary

Automated CI verifies PHP compatibility, security/state logic, MariaDB schema installation, UI/backend contracts, and release integrity. It cannot certify a particular provider's network, Feature Manager, cPanel license/version, filesystem, Telegram bot, or real user token without access to that environment. A deployment owner must run the live manual matrix in [ACCEPTANCE.md](ACCEPTANCE.md) on a disposable account before allowing production destructive operations.
