import crypto from 'node:crypto';
import process from 'node:process';

const specificationUrl = process.env.CPANEL_OPENAPI_URL || 'https://api.docs.cpanel.net/_bundle/specifications/cpanel.openapi.yaml';
const requiredEndpoints = [
  'AddonDomain/addaddondomain', 'AddonDomain/deladdondomain', 'AddonDomain/listaddondomains',
  'Backup/fullbackup_to_homedir', 'Backup/list_backups', 'Bandwidth/query',
  'DNS/mass_edit_zone', 'DNS/parse_zone', 'DomainInfo/domains_data', 'DomainInfo/list_domains',
  'Email/add_auto_responder', 'Email/add_forwarder', 'Email/add_pop', 'Email/delete_auto_responder',
  'Email/delete_forwarder', 'Email/delete_pop', 'Email/edit_pop_quota', 'Email/list_auto_responders',
  'Email/list_forwarders', 'Email/list_pops_with_disk', 'Email/passwd_pop',
  'Features/has_feature', 'Features/list_features',
  'Fileman/copy_file', 'Fileman/delete_file', 'Fileman/empty_trash', 'Fileman/get_file_content',
  'Fileman/get_file_information', 'Fileman/list_files', 'Fileman/move_file', 'Fileman/restore_from_trash',
  'Fileman/save_file_content', 'Fileman/trash_file', 'Fileman/upload_files',
  'LangPHP/php_get_installed_versions', 'LangPHP/php_get_system_default_version',
  'LangPHP/php_get_vhost_versions', 'LangPHP/php_ini_get_user_basic_directives',
  'LangPHP/php_ini_set_user_basic_directives', 'LangPHP/php_set_vhost_versions',
  'Mime/add_redirect', 'Mime/delete_redirect', 'Mime/list_redirects',
  'Mysql/add_host', 'Mysql/create_database', 'Mysql/create_user', 'Mysql/delete_database',
  'Mysql/delete_host', 'Mysql/delete_user', 'Mysql/get_privileges_on_database', 'Mysql/get_restrictions',
  'Mysql/list_databases', 'Mysql/list_users', 'Mysql/revoke_access_to_database', 'Mysql/set_password',
  'Mysql/set_privileges_on_database', 'ResourceUsage/get_usages',
  'SSL/fetch_certificates_for_fqdns', 'SSL/get_autossl_problems', 'SSL/installed_hosts',
  'SSL/is_autossl_check_in_progress', 'SSL/list_ssl_items', 'SSL/start_autossl_check',
  'StatsBar/get_stats', 'SubDomain/addsubdomain', 'SubDomain/delsubdomain',
  'Variables/get_server_information', 'Variables/get_user_information',
];

const criticalParameters = {
  'AddonDomain/addaddondomain': ['newdomain', 'subdomain'],
  'AddonDomain/deladdondomain': ['domain', 'subdomain'],
  'DNS/mass_edit_zone': ['serial', 'zone'],
  'Fileman/copy_file': ['destination', 'source'],
  'Fileman/move_file': ['destination', 'source'],
  'Fileman/restore_from_trash': ['path'],
  'LangPHP/php_ini_set_user_basic_directives': ['directive', 'type'],
  'SubDomain/addsubdomain': ['domain', 'rootdomain'],
  'SubDomain/delsubdomain': ['domain'],
};

const response = await fetch(specificationUrl, {headers: {'User-Agent': 'TelegramCpanelManager-ContractVerifier/1.0'}, signal: AbortSignal.timeout(45_000)});
if (!response.ok) throw new Error(`cPanel OpenAPI download failed with HTTP ${response.status}`);
const source = await response.text();
const endpoints = new Map();
let current = null;
let parameter = null;
for (const line of source.split('\n')) {
  const path = line.match(/^  \/([^:]+):$/);
  if (path) {
    current = {path: path[1], method: null, parameters: [], version: null};
    endpoints.set(current.path, current);
    parameter = null;
    continue;
  }
  if (!current) continue;
  const method = line.match(/^    (get|post|put|patch|delete):$/);
  if (method) current.method = method[1];
  const name = line.match(/^          name: (.+)$/);
  if (name) {
    parameter = {name: name[1], required: false};
    current.parameters.push(parameter);
  }
  const required = line.match(/^          required: (true|false)$/);
  if (required && parameter) parameter.required = required[1] === 'true';
  const version = line.match(/^      x-cpanel-available-version: (.+)$/);
  if (version) current.version = version[1];
}

const failures = [];
for (const path of requiredEndpoints) {
  if (!endpoints.has(path)) failures.push(`Official OpenAPI endpoint is missing: ${path}`);
}
for (const [path, names] of Object.entries(criticalParameters)) {
  const operation = endpoints.get(path);
  if (!operation) continue;
  const required = new Set(operation.parameters.filter(item => item.required).map(item => item.name));
  for (const name of names) if (!required.has(name)) failures.push(`Official required parameter changed: ${path} -> ${name}`);
}

const result = {
  specification_url: specificationUrl,
  specification_sha256: crypto.createHash('sha256').update(source).digest('hex'),
  specification_bytes: Buffer.byteLength(source),
  checked_endpoints: requiredEndpoints.length,
  checked_at: new Date().toISOString(),
  failures,
};
console.log(JSON.stringify(result, null, 2));
if (failures.length) process.exit(1);
