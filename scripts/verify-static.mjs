import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const walk = directory => fs.readdirSync(path.join(root, directory), {withFileTypes: true}).flatMap(entry => {
  const relative = path.join(directory, entry.name);
  return entry.isDirectory() ? walk(relative) : [relative];
});
const failures = [];
const assert = (condition, message) => { if (!condition) failures.push(message); };

const i18nSource = read('public/miniapp/i18n.js');
const i18nModule = await import(`data:text/javascript;base64,${Buffer.from(i18nSource).toString('base64')}`);
const dictionaries = i18nModule.allMessages();
const faKeys = Object.keys(dictionaries.fa).sort();
const enKeys = Object.keys(dictionaries.en).sort();
assert(JSON.stringify(faKeys) === JSON.stringify(enKeys), 'Mini App Persian and English dictionaries have different keys.');

const phpDictionaryKeys = language => [...read(`resources/lang/${language}/messages.php`).matchAll(/^\s*'([^']+)'\s*=>/gm)].map(match => match[1]).sort();
const phpFaKeys = phpDictionaryKeys('fa');
const phpEnKeys = phpDictionaryKeys('en');
assert(JSON.stringify(phpFaKeys) === JSON.stringify(phpEnKeys), 'Bot/API Persian and English dictionaries have different keys.');

const appSource = read('public/miniapp/app.js');
const usedTranslationKeys = new Set([...appSource.matchAll(/\bt\(\s*['"]([A-Za-z0-9_]+)['"]/g)].map(match => match[1]));
for (const key of usedTranslationKeys) {
  assert(Object.hasOwn(dictionaries.fa, key), `Missing Persian Mini App translation: ${key}`);
  assert(Object.hasOwn(dictionaries.en, key), `Missing English Mini App translation: ${key}`);
}

const actions = new Set([...appSource.matchAll(/data-action=["'`]([a-z][a-z0-9-]*)/g)].map(match => match[1]));
actions.add('navigate');
actions.add('more-menu');
const handlersStart = appSource.indexOf('const handlers = {');
const handlersEnd = appSource.indexOf('\n    };\n    if (!handlers[action])', handlersStart);
assert(handlersStart >= 0 && handlersEnd > handlersStart, 'Mini App action-handler registry was not found.');
const handlerBlock = appSource.slice(handlersStart, handlersEnd);
const handlers = new Set([...handlerBlock.matchAll(/(?:^|,\s*|\n\s*)(?:'([^']+)'|([a-z][a-z0-9-]*)):\s*\(\)\s*=>/gm)].map(match => match[1] || match[2]));
for (const action of actions) assert(handlers.has(action), `Mini App action has no handler: ${action}`);

const routeSources = ['routes/api.php', ...walk('routes/api').filter(file => file.endsWith('.php'))].map(read).join('\n');
const routes = [...routeSources.matchAll(/\$api->(?:route|publicRoute)\(\s*'([A-Z]+)'\s*,\s*'([^']+)'/g)].map(match => ({method: match[1], path: match[2]}));
const routeKeys = routes.map(route => `${route.method} ${route.path}`);
assert(routes.length >= 140, `Expected at least 140 API routes, found ${routes.length}.`);
assert(new Set(routeKeys).size === routeKeys.length, 'Duplicate API method/path registrations exist.');
const routeRegexes = routes.map(route => new RegExp(`^${route.path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\\{[^}]+\\\}/g, '[^/]+')}/?$`));
const hasRoute = candidate => routeRegexes.some(regex => regex.test(candidate.replace(/\?.*$/, '')));
const hostSuffixes = new Set([...appSource.matchAll(/hostPath\(\s*['"]([^'"]*)['"]/g)].map(match => `/api/v1/hosts/1${match[1]}`));
for (const candidate of hostSuffixes) assert(hasRoute(candidate), `Mini App host API path has no backend route: ${candidate}`);

const installer = read('public/install.php');
const installerFields = [...installer.matchAll(/<input\b[^>]*\bname="([^"]+)"/g)].map(match => match[1]).filter(name => name !== '_csrf').sort();
assert(JSON.stringify(installerFields) === JSON.stringify(['bot_token', 'db_name', 'db_password', 'db_username', 'super_admin_id']), `Installer fields are not exactly the required five: ${installerFields.join(', ')}`);
assert(!/<select\b|<textarea\b/i.test(installer.match(/<form[\s\S]*?<\/form>/i)?.[0] || ''), 'Installer asks for values outside its five input fields.');
assert(!installer.includes('$error = $exception->getMessage()'), 'Installer exposes raw exception messages to unauthenticated visitors.');
assert(installer.includes('InstallerException') && installer.includes("'message_fa'") && installer.includes("'message_en'"), 'Installer errors are not safe and bilingual.');
const installerService = read('app/Installer/InstallerService.php');
assert(installerService.includes('LOCK_EX | LOCK_NB') && installerService.includes('installer_busy'), 'Installer does not prevent concurrent execution.');

const migrations = walk('database/migrations').filter(file => file.endsWith('.sql')).sort();
assert(migrations.length >= 4, `Expected at least four migrations, found ${migrations.length}.`);
const schema = migrations.map(read).join('\n');
const requiredTables = ['users', 'user_plans', 'cpanel_accounts', 'account_capabilities', 'miniapp_sessions', 'replay_nonces', 'confirmation_nonces', 'rate_limits', 'audit_logs', 'security_events', 'queue_jobs', 'file_archive_jobs', 'backups', 'deployments', 'database_connections', 'broadcast_deliveries'];
for (const table of requiredTables) assert(new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`).test(schema), `Required table is missing: ${table}`);
const migrationRunner = read('app/Core/MigrationRunner.php');
assert(migrationRunner.includes('GET_LOCK') && migrationRunner.includes('RELEASE_LOCK'), 'MySQL migrations are not protected by an advisory lock.');
assert(migrationRunner.includes("$this->driver === 'mysql'") && migrationRunner.includes('implicitly commit DDL'), 'MySQL DDL transaction semantics are not handled explicitly.');
assert(!/ALTER\s+TABLE\s+[^;]+ADD\s+COLUMN[^;]+,\s*ADD\s+COLUMN/is.test(schema), 'A migration contains multiple ADD COLUMN operations in one non-resumable statement.');
assert(schema.includes('reservation_token CHAR(64)'), 'Queue jobs do not persist an owner-bound lease token.');
for (const column of ['prepared_path', 'prepared_sha256', 'prepared_at', 'preparation_token']) assert(schema.includes(`ADD COLUMN ${column}`), `Prepared download schema is missing ${column}.`);

const queueSource = read('app/Queue/QueueService.php');
assert(queueSource.includes('QUEUE_STALE_AFTER_SECONDS') || read('app/Core/Container.php').includes("Env::int('QUEUE_STALE_AFTER_SECONDS'"), 'Queue lease duration is not configurable.');
assert(queueSource.includes('reserved_at = CURRENT_TIMESTAMP') && queueSource.includes('reservation_token = ?'), 'Queue progress does not renew an owner-bound lease.');
assert(!queueSource.includes('DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 20 MINUTE)'), 'Queue recovery still uses the old hard-coded MySQL lease timeout.');
const operationLocks = read('app/Security/OperationLockService.php');
assert(operationLocks.includes('function renew(') && operationLocks.includes('expires_at >= CURRENT_TIMESTAMP') && operationLocks.includes('operation_lock_lost'), 'Long-running operation locks cannot be owner-renewed safely.');
assert(read('app/Queue/CleanupService.php').includes("'user_sessions'"), 'Expired bot sessions are not cleaned up.');
assert(read('app/Core/Container.php').includes("'file.download' => new FileDownloadJobHandler"), 'Prepared downloads are not connected to the queue worker.');
assert(read('app/FileManager/DownloadService.php').includes("status = 'ready'") && read('app/FileManager/DownloadService.php').includes('download_integrity_failed'), 'Secure downloads are not prepared and integrity-checked before serving.');
assert(read('app/FileManager/FileDownloadJobHandler.php').includes("'sendDocument'") && read('app/FileManager/FileDownloadJobHandler.php').includes("'secure_link'"), 'Queued downloads do not provide real Telegram and secure-link delivery paths.');
assert(schema.includes('CREATE TABLE IF NOT EXISTS telegram_file_uploads'), 'Telegram upload retry state is not persisted.');
assert(read('app/Core/Container.php').includes("'telegram.file_upload' => new TelegramFileUploadJobHandler"), 'Telegram uploads are not connected to the queue worker.');
const telegramUpload = read('app/FileManager/TelegramFileUploadJobHandler.php');
assert(telegramUpload.includes('downloadFile(') && telegramUpload.includes('resolveUploadName(') && telegramUpload.includes("'overwrite'"), 'Telegram upload worker is missing real download, deterministic target reservation, or cPanel upload.');
assert(telegramUpload.includes('@unlink($temporary)') && telegramUpload.includes("$success ? 'completed' : 'failed'"), 'Telegram upload worker does not clean temporary files or persist completion.');
assert(!read('app/Telegram/BotHandler.php').includes('downloadFile($fileId'), 'Telegram file download still blocks the webhook request instead of using the queue.');
assert(read('app/FileManager/TelegramUploadService.php').includes('OFFICIAL_BOT_API_DOWNLOAD_LIMIT = 20_000_000'), 'Telegram Bot API download cap is not enforced.');
assert(!read('database/migrations/008_telegram_file_uploads.sql').includes('file_id'), 'Raw Telegram file identifiers must remain only inside encrypted queue payloads.');
const containerSource = read('app/Core/Container.php');
assert(containerSource.includes("'file.archive_create' => new ArchiveCreateJobHandler") && containerSource.includes("'file.archive_extract' => new ArchiveExtractJobHandler"), 'Archive operations are not connected to both queue handlers.');
const archiveRoutes = read('routes/api/files.php');
assert(archiveRoutes.includes('enqueueCreate(') && archiveRoutes.includes('enqueueExtract(') && !archiveRoutes.includes('return $files->compress(') && !archiveRoutes.includes('=> $files->extract('), 'Archive API routes do not exclusively enqueue the durable workflow.');
const archiveService = read('app/FileManager/ArchiveService.php');
assert(archiveService.includes('getOwned($userId, $accountId)') && archiveService.includes('safeApi2Path(') && archiveService.includes("'default', 1"), 'Archive queueing lacks tenant ownership, API-path validation, or single-attempt safety.');
assert(archiveService.includes("['reject', 'overwrite']") && archiveRoutes.includes("'archive.extract_overwrite'"), 'Archive extraction collision policy or one-time overwrite confirmation is missing.');
const archiveExtract = read('app/FileManager/ArchiveExtractJobHandler.php');
assert(archiveExtract.includes('downloadTo(') && archiveExtract.includes('->validate(') && archiveExtract.includes('staging_archive'), 'Archive extraction is not streamed, validated, and bound to an immutable staging copy.');
assert(archiveExtract.includes("hash_equals((string) $summary['sha256'], (string) $stagedDownload['sha256'])") && archiveExtract.includes('archive_reconciliation_required'), 'Archive staging integrity or ambiguous-outcome reconciliation is missing.');
assert(archiveExtract.includes('@unlink($temporary)') && archiveExtract.includes('@unlink($verificationTemporary)'), 'Archive inspection temporary files are not cleaned.');

for (const column of ['package_id', 'queue_job_id', 'rollback_job_id', 'package_checksum', 'package_metadata_json', 'stage_path', 'switch_state', 'backup_size', 'backup_checksum', 'reconciliation_json', 'notification_id', 'rollback_notification_id', 'recovery_attempts', 'last_recovery_at', 'rollback_verified_at']) {
  assert(schema.includes(`ADD COLUMN ${column}`), `Deployment state schema is missing ${column}.`);
}
const deploymentService = read('app/Deployment/DeploymentService.php');
const deploymentRoutes = read('routes/api/hosting.php');
const deploymentPackages = read('app/Deployment/DeploymentPackageService.php');
assert(deploymentRoutes.includes('deployPackage(') && !deploymentRoutes.includes('$deploymentPackages->consume('), 'Deployment package consumption is still outside the atomic intake transaction.');
assert(deploymentRoutes.includes('->overview(') && deploymentService.includes("'current_versions'") && deploymentService.includes("'rollback_points'") && deploymentService.includes("'attention_required'"), 'Deployment Center does not receive backend-classified current versions, rollback points, and attention states.');
assert(deploymentService.includes('SELECT * FROM deployment_packages WHERE id = ? AND user_id = ? AND account_id = ? FOR UPDATE') && deploymentService.includes('UPDATE deployment_packages SET consumed_at = CURRENT_TIMESTAMP'), 'Deployment intake does not lock and consume its owner-bound package transactionally.');
assert(deploymentService.includes("'default',\n                1,") && deploymentService.includes('rollback_job_id = ?'), 'Deployment and rollback jobs are not persisted as single-attempt operations.');
assert(deploymentService.includes('deployment_reserved_path') && deploymentService.includes('invalid_health_check_url'), 'Deployment destination or health-check input policy is missing.');
assert(deploymentPackages.includes("'name' => $name") && deploymentPackages.includes("'metadata' => $metadata") && deploymentPackages.includes('is_link($path)'), 'Deployment upload response contract or symlink protection is incomplete.');
const deploymentHandler = read('app/Deployment/DeploymentJobHandler.php');
const deploymentRollback = read('app/Deployment/DeploymentRollbackExecutor.php');
const deploymentRecovery = read('app/Deployment/DeploymentRecoveryService.php');
const deploymentBackupVerifier = read('app/Deployment/DeploymentBackupVerifier.php');
const deploymentFilesystem = read('app/FileManager/FileManagerService.php');
assert(deploymentFilesystem.includes('renameDirectoryAtomically(') && deploymentFilesystem.includes("'op' => 'rename'") && deploymentFilesystem.includes("'api2_no_uapi_equivalent'"), 'Atomic same-account directory switching is not connected to the documented cPanel compatibility operation.');
assert(deploymentBackupVerifier.includes('->download(') && deploymentBackupVerifier.includes('->validate(') && deploymentBackupVerifier.includes("['size' => $size, 'sha256' => $checksum"), 'Deployment backups are not streamed, checksum-bound, and structurally verified before use.');
assert(deploymentHandler.includes('validatedPackageContract(') && deploymentHandler.includes('requiresLocalPackage(') && deploymentHandler.includes("'health_check'"), 'Late deployment recovery is still incorrectly dependent on the local upload package.');
assert(deploymentHandler.includes('rollbackCheckpoint(') && deploymentRollback.includes("'rollback_dir'") && deploymentRollback.includes("'_restore_pending'") && deploymentRollback.includes('rollback_archive_extract_pending') && deploymentRollback.includes('rollback_remove_pending'), 'Deployment rollback does not persist all destructive filesystem checkpoints.');
assert(deploymentRecovery.includes("'queue_lease_expired', 'queue_lease_lost', 'operation_locked', 'operation_lock_lost'") && deploymentRecovery.includes("'default', 1"), 'Deployment crash recovery does not safely requeue expired single-attempt operations.');
assert(read('app/Queue/QueueWorker.php').includes('deploymentRecovery?->recover(3)'), 'The queue worker does not run deployment crash recovery.');
assert(read('app/Queue/CleanupService.php').includes('protectedDeploymentPackages') && read('app/Queue/CleanupService.php').includes("d.status IN ('queued','validating'"), 'Cleanup can delete an active deployment package before recovery.');
const deploymentEventSources = `${deploymentHandler}\n${deploymentRollback}\n${read('app/Deployment/RollbackJobHandler.php')}`;
const deploymentEventKeys = new Set(['deployment.queued', ...[...deploymentEventSources.matchAll(/eventOnce\([^\n]+?'(deployment\.[a-z_]+)'/g)].map(match => match[1])]);
for (const key of deploymentEventKeys) {
  assert(Object.hasOwn(dictionaries.fa, key), `Missing Persian deployment-event translation: ${key}`);
  assert(Object.hasOwn(dictionaries.en, key), `Missing English deployment-event translation: ${key}`);
}
for (const key of ['deployStepPackage', 'deployStepDestination', 'deployStepValidate', 'deployStepBackup', 'deployStepExtract', 'deployStepDeploy', 'deployStepHealth', 'deployStepComplete']) {
  assert(Object.hasOwn(dictionaries.fa, key) && Object.hasOwn(dictionaries.en, key), `Deployment wizard step is not bilingual: ${key}`);
}
assert(appSource.includes('deploymentWizardMarkup(') && appSource.includes('pollDeployment(') && appSource.includes('result.current_versions') && appSource.includes('result.rollback_points'), 'Mini App Deployment Center is missing its real eight-step state timeline or release overview.');
assert(!appSource.includes("requireCapability(['files', 'backup'], 'deployCenter')"), 'Deployment Center is incorrectly disabled when the unrelated Full Backup capability is absent.');
assert(!appSource.includes('escapeHtml(event.message_key)'), 'Mini App renders an untranslated deployment event key.');
assert(read('public/miniapp/index.html').includes('/miniapp/deployment.css'), 'Deployment Center responsive styling is not loaded.');

const backupService = read('app/Backup/BackupService.php');
const backupTracker = read('app/Backup/BackupJobTracker.php');
const sqlTransfers = read('app/Database/SqlTransferService.php');
assert(containerSource.includes('BackupJobTracker::class') && read('app/Queue/QueueWorker.php').includes('trackBackupCompletion(') && read('app/Queue/QueueWorker.php').includes('trackBackupFailure('), 'Backup records are not reconciled with durable queue completion and failure.');
assert(backupService.includes("'file_backups'") && backupService.includes("'database_backups'") && backupService.includes("'deployment_backups'") && backupService.includes("'full_backups'"), 'Backup inventory is not grouped by recoverable artifact type.');
assert(backupService.includes('restoreFileVersion(') && backupService.includes('importBackup(') && backupService.includes('->enqueueExtract(') && backupService.includes('->rollback('), 'One or more file, database, directory, or deployment restore paths are not operational.');
assert(backupService.includes("in_array((string) $backup['status'], ['queued', 'processing', 'requested'], true)") && backupService.includes('assertBackupNotRestoring('), 'Active backup or restore artifacts can be deleted without conflict protection.');
assert(backupTracker.includes("provider_ref LIKE 'job:%'") && backupTracker.includes('user_id = ? AND account_id = ?'), 'Backup queue reconciliation is not owner-bound.');
assert(sqlTransfers.includes('storedBackupPath(') && sqlTransfers.includes('hash_equals($expectedSha256, $actualSha256)') && sqlTransfers.includes("fopen($temporary, 'xb')") && sqlTransfers.includes("'backup_first' => $backupFirst"), 'Database restore lacks managed-root staging, checksum verification, exclusive creation, or pre-import backup.');
for (const routeContract of ['/backups/file/{version}/restore', '/backups/file/{version}/download', '/backups/file/{version}']) {
  assert(deploymentRoutes.includes(routeContract), `File-version backup API route is missing: ${routeContract}`);
}
assert((deploymentRoutes.match(/feature\(\(int\) \$session\['user_id'\], 'backup_enabled'\)/g) || []).length >= 9, 'Backup feature policy is not enforced on every backup API operation.');
for (const action of ['backup-refresh', 'backup-progress', 'backup-download', 'backup-restore', 'backup-delete']) assert(handlers.has(action), `Backup Center action is not connected: ${action}`);
assert(appSource.includes('pollJob(Number(result.job_id), false)') && appSource.includes('pollDeployment(Number(result.job_id), Number(result.deployment_id), true)'), 'Backup Center does not track queued restore/backup and deployment rollback progress.');
assert(read('public/miniapp/index.html').includes('/miniapp/backup.css'), 'Backup Center responsive styling is not loaded.');

const productionFiles = ['app', 'bootstrap', 'cli', 'public', 'resources', 'routes', 'database'].flatMap(directory => walk(directory)).filter(file => !file.startsWith('public/miniapp/vendor/'));
for (const file of productionFiles) {
  const source = read(file);
  assert(!/\b(?:TODO|FIXME|COMING\s+SOON|PLACEHOLDER)\b/i.test(source), `Forbidden unfinished marker in ${file}`);
  assert(!/\b\d{6,12}:[A-Za-z0-9_-]{30,}\b/.test(source), `Telegram token-shaped secret in ${file}`);
  assert(!/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/.test(source), `Private key in ${file}`);
}

const cpanelSources = productionFiles.filter(file => file.endsWith('.php')).map(read).join('\n');
for (const obsolete of [
  /['"]Domain['"]\s*,\s*['"](?:add_domain|delete_domain)['"]/,
  /['"]Mysql['"]\s*,\s*['"]list_hosts['"]/,
  /['"]SSL['"]\s*,\s*['"]can_autossl['"]/,
  /['"]Fileman['"]\s*,\s*['"](?:move_dir|restore_file)['"]/,
]) assert(!obsolete.test(cpanelSources), `Obsolete or nonexistent cPanel UAPI contract remains: ${obsolete}`);
assert(!/->call\([^\n]*['"]Cron['"]\s*,\s*['"](?:list_cron|add_line|edit_line|remove_line)['"]/.test(cpanelSources), 'Cron operations incorrectly use nonexistent UAPI routes.');
assert(cpanelSources.includes("callLegacyApi2($this->accounts->connection($userId, $accountId), 'Cron', 'listcron'") && cpanelSources.includes("'Cron', 'remove_line', ['line' => $lineKey]"), 'Cron API 2 calls do not match the documented compatibility contract.');
assert(cpanelSources.includes("'DNS', 'mass_edit_zone'") && cpanelSources.includes("'serial' => $this->dnsSerial"), 'DNS mass_edit_zone is not bound to the current SOA serial.');
assert(cpanelSources.includes("'redirect_wildcard'") && cpanelSources.includes("'redirect_www'"), 'Redirect parameters do not match the current Mime UAPI contract.');
assert(cpanelSources.includes("'directive-' . $position") && cpanelSources.includes("$key . ':'"), 'PHP INI directives do not match the current LangPHP contract.');
assert(cpanelSources.includes("'api.paginate.enable'") && cpanelSources.includes("'api.paginate.start'"), 'UAPI server-side pagination controls are missing.');
assert(cpanelSources.includes('isOperationUnavailable') && cpanelSources.includes('api2_compatibility'), 'Version-aware cPanel API compatibility policy is missing.');
assert(read('app/Cpanel/UapiClient.php').includes('$idempotent ? $validated[\'ips\'] : array_slice($validated[\'ips\'], 0, 1)'), 'Non-idempotent cPanel API 2 calls are not protected from multi-IP replay.');
assert(read('app/Cpanel/UapiClient.php').includes("$candidateIps = $idempotent ? $validated['ips'] : array_slice($validated['ips'], 0, 1)"), 'Non-idempotent cPanel UAPI calls are not protected from multi-IP replay.');
assert(read('app/FileManager/FileManagerService.php').includes("'op' => 'extract'") && read('app/FileManager/FileManagerService.php').includes("'doubledecode' => 0], false"), 'Archive mutations do not explicitly disable provider-call replay.');

const helpSource = read('resources/help/topics.php');
const helpSlugs = new Set([...helpSource.matchAll(/'slug'\s*=>\s*'([^']+)'/g)].map(match => match[1]));
const helpRelated = [...helpSource.matchAll(/'related'\s*=>\s*\[([^\]]*)\]/g)].flatMap(match => [...match[1].matchAll(/'([^']+)'/g)].map(item => item[1]));
assert(helpSlugs.size >= 70, `Expected at least 70 contextual help topics, found ${helpSlugs.size}.`);
for (const related of helpRelated) assert(helpSlugs.has(related), `Help topic references a missing related topic: ${related}`);
for (const category of ['start', 'hosts', 'security', 'files', 'database', 'domains', 'email', 'ssl', 'cron', 'backup', 'deployment', 'system', 'account', 'errors', 'admin', 'installation']) {
  assert(helpSource.includes(`'category' => '${category}'`), `Contextual help category is missing: ${category}`);
}
const contextHelpMap = appSource.match(/openContextHelp\(\) \{ const map = \{([^}]+)\}/)?.[1] || '';
for (const match of contextHelpMap.matchAll(/:\s*'([^']+)'/g)) assert(helpSlugs.has(match[1]), `Mini App contextual-help route references a missing topic: ${match[1]}`);
assert(!/databasePrivilegesDialog\(\)[^{]*\{[^}]*\bprivileges\s*=\s*\[/s.test(appSource), 'Mini App database privileges are hard-coded instead of server-provided.');

const result = {
  php_files: productionFiles.filter(file => file.endsWith('.php')).length,
  api_routes: routes.length,
  miniapp_actions: actions.size,
  action_handlers: handlers.size,
  translations_per_language: faKeys.length,
  bot_translations_per_language: phpFaKeys.length,
  used_translation_keys: usedTranslationKeys.size,
  help_topics: helpSlugs.size,
  migrations: migrations.length,
  failures,
};
console.log(JSON.stringify(result, null, 2));
if (failures.length > 0) process.exit(1);
