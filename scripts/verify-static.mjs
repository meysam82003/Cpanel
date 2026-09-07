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
const requiredTables = ['users', 'user_plans', 'cpanel_accounts', 'account_capabilities', 'miniapp_sessions', 'replay_nonces', 'confirmation_nonces', 'rate_limits', 'audit_logs', 'security_events', 'queue_jobs', 'backups', 'deployments', 'database_connections', 'broadcast_deliveries'];
for (const table of requiredTables) assert(new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`).test(schema), `Required table is missing: ${table}`);
const migrationRunner = read('app/Core/MigrationRunner.php');
assert(migrationRunner.includes('GET_LOCK') && migrationRunner.includes('RELEASE_LOCK'), 'MySQL migrations are not protected by an advisory lock.');
assert(migrationRunner.includes("$this->driver === 'mysql'") && migrationRunner.includes('implicitly commit DDL'), 'MySQL DDL transaction semantics are not handled explicitly.');
assert(!/ALTER\s+TABLE\s+[^;]+ADD\s+COLUMN[^;]+,\s*ADD\s+COLUMN/is.test(schema), 'A migration contains multiple ADD COLUMN operations in one non-resumable statement.');
assert(schema.includes('reservation_token CHAR(64)'), 'Queue jobs do not persist an owner-bound lease token.');

const queueSource = read('app/Queue/QueueService.php');
assert(queueSource.includes('QUEUE_STALE_AFTER_SECONDS') || read('app/Core/Container.php').includes("Env::int('QUEUE_STALE_AFTER_SECONDS'"), 'Queue lease duration is not configurable.');
assert(queueSource.includes('reserved_at = CURRENT_TIMESTAMP') && queueSource.includes('reservation_token = ?'), 'Queue progress does not renew an owner-bound lease.');
assert(!queueSource.includes('DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 20 MINUTE)'), 'Queue recovery still uses the old hard-coded MySQL lease timeout.');
assert(read('app/Queue/CleanupService.php').includes("'user_sessions'"), 'Expired bot sessions are not cleaned up.');

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
