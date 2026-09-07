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

const migrations = walk('database/migrations').filter(file => file.endsWith('.sql')).sort();
assert(migrations.length >= 4, `Expected at least four migrations, found ${migrations.length}.`);
const schema = migrations.map(read).join('\n');
const requiredTables = ['users', 'user_plans', 'cpanel_accounts', 'account_capabilities', 'miniapp_sessions', 'replay_nonces', 'confirmation_nonces', 'rate_limits', 'audit_logs', 'security_events', 'queue_jobs', 'backups', 'deployments', 'database_connections', 'broadcast_deliveries'];
for (const table of requiredTables) assert(new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`).test(schema), `Required table is missing: ${table}`);

const productionFiles = ['app', 'bootstrap', 'cli', 'public', 'resources', 'routes', 'database'].flatMap(directory => walk(directory)).filter(file => !file.startsWith('public/miniapp/vendor/'));
for (const file of productionFiles) {
  const source = read(file);
  assert(!/\b(?:TODO|FIXME|COMING\s+SOON|PLACEHOLDER)\b/i.test(source), `Forbidden unfinished marker in ${file}`);
  assert(!/\b\d{6,12}:[A-Za-z0-9_-]{30,}\b/.test(source), `Telegram token-shaped secret in ${file}`);
  assert(!/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/.test(source), `Private key in ${file}`);
}

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
