import {Api, ApiError, query} from './api.js';
import {setLanguage, language, t} from './i18n.js';

const tg = window.Telegram?.WebApp || null;
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[character]));
const escapeAttr = escapeHtml;
const bytes = value => {
  const number = Number(value || 0);
  if (!Number.isFinite(number)) return '—';
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
  let size = Math.max(0, number), unit = 0;
  while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit += 1; }
  return `${size >= 10 || unit === 0 ? size.toFixed(0) : size.toFixed(1)} ${units[unit]}`;
};
const dateText = value => value ? new Intl.DateTimeFormat(language() === 'fa' ? 'fa-IR' : 'en', {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'))) : '—';
const nameOf = item => String(item?.name ?? item?.file ?? item?.domain ?? item?.database ?? item?.dbname ?? item?.user ?? '');
const asList = (value, keys = []) => {
  if (Array.isArray(value)) return value;
  if (!value || typeof value !== 'object') return [];
  for (const key of keys) if (Array.isArray(value[key])) return value[key];
  for (const child of Object.values(value)) if (Array.isArray(child)) return child;
  return [];
};
const formatJson = value => JSON.stringify(value, null, 2);
const statusText = value => {
  const normalized = String(value ?? '').replace(/[_-]+(.)/g, (_, character) => character.toUpperCase());
  const key = `status${normalized.charAt(0).toUpperCase()}${normalized.slice(1)}`;
  const translated = t(key);
  return translated === key ? String(value ?? '') : translated;
};
const stageText = value => {
  const normalized = String(value ?? '').replace(/[_-]+(.)/g, (_, character) => character.toUpperCase());
  const key = `stage${normalized.charAt(0).toUpperCase()}${normalized.slice(1)}`;
  const translated = t(key);
  return translated === key ? String(value ?? '') : translated;
};

class ManagerApp {
  constructor() {
    this.api = new Api(() => this.sessionLost());
    this.content = $('#content');
    this.hostSelect = $('#host-select');
    this.dialog = $('#dialog');
    this.dialogForm = $('#dialog-form');
    this.user = null;
    this.dashboardData = null;
    this.hosts = [];
    this.hostId = Number(localStorage.getItem('tcpm.host') || 0);
    this.route = 'dashboard';
    this.params = {};
    this.abortController = null;
    this.cache = new Map();
    this.fileSelection = new Set();
    this.tableSelection = new Map();
    this.editor = null;
    this.editorState = null;
    this.dialogSubmit = null;
    this.dragDepth = 0;
    this.rotationTimer = null;
    this.sqlResultQuery = '';
    this.bindGlobalEvents();
  }

  async boot() {
    this.setupTelegram();
    try {
      let authenticated = null;
      if (this.api.token) {
        try { authenticated = await this.api.request('/api/v1/auth/session'); } catch { this.api.clearSession(); }
      }
      if (!authenticated) {
        if (!tg?.initData) throw new ApiError({code: 'telegram_context_required', message: t('authOutside')}, 401);
        authenticated = await this.api.authenticate(tg.initData);
      }
      this.user = authenticated.user;
      this.scheduleSessionRotation(authenticated.session?.expires_at || authenticated.expires_at);
      setLanguage(this.user.language);
      document.body.classList.toggle('advanced', this.user.ux_mode === 'advanced');
      await this.refreshDashboard(false);
      this.readLocation();
      this.renderNavigation();
      await this.render();
      document.querySelector('#app')?.setAttribute('aria-busy', 'false');
    } catch (error) {
      this.renderFatal(error);
    } finally {
      tg?.ready?.();
    }
  }

  setupTelegram() {
    if (!tg) return;
    tg.expand?.();
    try { tg.requestFullscreen?.(); } catch {}
    tg.enableClosingConfirmation?.();
    tg.BackButton?.onClick?.(() => history.length > 1 ? history.back() : this.navigate('dashboard'));
    tg.onEvent?.('themeChanged', () => this.applyTheme());
    this.applyTheme();
  }

  scheduleSessionRotation(expiresAt) {
    clearTimeout(this.rotationTimer);
    const expiry = Date.parse(String(expiresAt || '').replace(' ', 'T') + (String(expiresAt || '').includes('Z') ? '' : 'Z'));
    const remaining = Number.isFinite(expiry) ? Math.max(120000, expiry - Date.now()) : 1800000;
    const delay = Math.max(60000, Math.min(900000, Math.floor(remaining / 2)));
    this.rotationTimer = setTimeout(async () => {
      try {
        const replacement = await this.api.rotateSession();
        this.scheduleSessionRotation(replacement.expires_at);
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return;
        this.rotationTimer = setTimeout(() => this.scheduleSessionRotation(expiresAt), 60000);
      }
    }, delay);
  }

  applyTheme() {
    const theme = tg?.themeParams || {};
    const color = theme.button_color || '#0c8576';
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', color);
  }

  bindGlobalEvents() {
    document.addEventListener('click', event => {
      const close = event.target.closest('[data-dialog-close]');
      if (close) { this.closeDialog(); return; }
      const route = event.target.closest('[data-route]');
      if (route) { event.preventDefault(); this.navigate(route.dataset.route, this.dataParams(route.dataset)); return; }
      const action = event.target.closest('[data-action]');
      if (action) { event.preventDefault(); this.handleAction(action.dataset.action, action.dataset, action).catch(error => this.operationError(error)); }
    });
    this.hostSelect.addEventListener('change', () => {
      this.hostId = Number(this.hostSelect.value || 0);
      localStorage.setItem('tcpm.host', String(this.hostId));
      this.haptic('selection');
      this.render().catch(error => this.renderError(error, () => this.render()));
    });
    $('#context-help').addEventListener('click', () => this.openContextHelp());
    window.addEventListener('popstate', () => { this.readLocation(); this.render().catch(error => this.renderError(error)); });
    this.dialogForm.addEventListener('submit', async event => {
      event.preventDefault();
      if (!this.dialogSubmit) return;
      const submit = $('[type="submit"]', this.dialogForm);
      if (submit) submit.disabled = true;
      try { await this.dialogSubmit(this.dialogForm); } catch (error) { this.operationError(error); } finally { if (submit) submit.disabled = false; }
    });
  }

  dataParams(dataset) {
    const params = {};
    for (const [key, value] of Object.entries(dataset)) if (!['route', 'action'].includes(key)) params[key] = value;
    return params;
  }

  readLocation() {
    const search = new URLSearchParams(location.search);
    this.route = search.get('route') || 'dashboard';
    this.params = Object.fromEntries(search.entries());
    if (search.get('host')) this.hostId = Number(search.get('host')) || this.hostId;
    if (!this.validRoutes().includes(this.route)) this.route = 'dashboard';
    this.syncHostPicker();
  }

  validRoutes() {
    return ['dashboard', 'files', 'editor', 'databases', 'database', 'table', 'sql', 'domains', 'email', 'ssl', 'cron', 'backups', 'deploy', 'usage', 'logs', 'php', 'security', 'settings', 'help', 'admin'];
  }

  navigate(route, params = {}, replace = false) {
    const url = new URL(location.href);
    url.search = '';
    url.searchParams.set('route', route);
    if (this.hostId) url.searchParams.set('host', String(this.hostId));
    for (const [key, value] of Object.entries(params)) if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
    history[replace ? 'replaceState' : 'pushState']({}, '', url);
    this.readLocation();
    this.render().catch(error => this.renderError(error, () => this.render()));
  }

  async render() {
    this.abortController?.abort();
    this.abortController = new AbortController();
    this.hideMainButton();
    tg?.BackButton?.[this.route === 'dashboard' ? 'hide' : 'show']?.();
    this.renderNavigation();
    const pages = {
      dashboard: () => this.pageDashboard(), files: () => this.pageFiles(), editor: () => this.pageEditor(), databases: () => this.pageDatabases(), database: () => this.pageDatabase(), table: () => this.pageTable(), sql: () => this.pageSql(), domains: () => this.pageDomains(), email: () => this.pageEmail(), ssl: () => this.pageSsl(), cron: () => this.pageCron(), backups: () => this.pageBackups(), deploy: () => this.pageDeploy(), usage: () => this.pageUsage(), logs: () => this.pageLogs(), php: () => this.pagePhp(), security: () => this.pageSecurity(), settings: () => this.pageSettings(), help: () => this.pageHelp(), admin: () => this.pageAdmin()
    };
    await pages[this.route]();
    this.applyCapabilityPolicy(this.content);
    this.content.focus({preventScroll: true});
  }

  renderNavigation() {
    const items = [['dashboard', '⌂', 'dashboard'], ['files', '▣', 'files'], ['databases', '◫', 'databases'], ['more', '•••', 'more']];
    const moreRoutes = ['domains', 'email', 'ssl', 'cron', 'backups', 'deploy', 'usage', 'logs', 'php', 'security', 'settings', 'help', 'admin'];
    $('#bottom-nav').innerHTML = items.map(([route, icon, label]) => `<button type="button" data-action="${route === 'more' ? 'more-menu' : 'navigate'}" data-target="${route}" class="${this.route === route || (route === 'more' && moreRoutes.includes(this.route)) ? 'active' : ''}"><span aria-hidden="true">${icon}</span>${escapeHtml(t(label))}</button>`).join('');
  }

  setTitle(key) { $('#page-title').textContent = t(key); document.title = `${t(key)} · ${t('app')}`; }
  pageHead(titleKey, description = '', buttons = '') {
    this.setTitle(titleKey);
    const descriptionKey = {fileManager: 'descFiles', editor: 'descEditor', databaseManager: 'descDatabases', tables: 'descDatabase', table: 'descTable', sqlConsole: 'descSql', domains: 'descDomains', email: 'descEmail', ssl: 'descSsl', cronJobs: 'descCron', backups: 'descBackups', deployCenter: 'descDeploy', usage: 'descUsage', logs: 'descLogs', php: 'descPhp', securityCenter: 'descSecurity', settings: 'descSettings', help: 'descHelp', adminDashboard: 'descAdmin'}[titleKey];
    const contextualDescription = [descriptionKey ? t(descriptionKey) : '', description].filter(Boolean).join(' · ');
    return `<header class="page-head"><div><h1>${escapeHtml(t(titleKey))}</h1>${contextualDescription ? `<p>${escapeHtml(contextualDescription)}</p>` : ''}</div>${buttons}</header>`;
  }
  loading(titleKey) { this.setTitle(titleKey); this.content.innerHTML = `<div class="list"><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></div>`; }
  requireHost() { if (!this.hostId || !this.hosts.some(host => Number(host.id) === this.hostId)) { this.renderNoHost(); return false; } return true; }
  hostPath(suffix = '') { return `/api/v1/hosts/${this.hostId}${suffix}`; }
  activeHost() { return this.hosts.find(host => Number(host.id) === this.hostId) || null; }
  capability(name) { return this.activeHost()?.capabilities?.[name] || null; }
  hasCapability(name, writable = false) { const capability = this.capability(name); return capability === null || Boolean(writable ? capability.writable : capability.available); }
  missingCapability(names) { return (Array.isArray(names) ? names : [names]).find(name => !this.hasCapability(name)) || null; }
  requireCapability(names, titleKey) {
    const missing = this.missingCapability(names);
    if (!missing) return true;
    const capability = this.capability(missing);
    this.setTitle(titleKey);
    this.content.innerHTML = `<section class="empty-state card"><div class="file-icon" aria-hidden="true">⛔</div><h2>${escapeHtml(t('capabilityUnavailableTitle'))}</h2><p>${escapeHtml(t('capabilityUnavailableBody'))}</p><dl class="key-values"><dt>${escapeHtml(t('api'))}</dt><dd>${escapeHtml(missing)}</dd><dt>${escapeHtml(t('capabilityDetectedAt'))}</dt><dd>${escapeHtml(dateText(capability?.detected_at || this.activeHost()?.capability_checked_at))}</dd></dl><div class="toolbar"><button class="button" data-action="host-health" data-id="${Number(this.hostId)}">${escapeHtml(t('healthCheck'))}</button><button class="button secondary" data-route="help" data-slug="hosts.connection-errors">${escapeHtml(t('help'))}</button></div></section>`;
    return false;
  }
  routeCapabilities(route) {
    return {files: ['files'], editor: ['files'], databases: ['mysql'], database: ['mysql'], table: ['mysql'], sql: ['mysql'], domains: ['domains'], email: ['email'], ssl: ['ssl'], cron: ['cron'], backups: [], deploy: ['files'], usage: ['usage'], logs: ['files'], php: ['php']}[route] || [];
  }
  mutationCapabilities(action, dataset = {}) {
    if (dataset.capability) return String(dataset.capability).split(',').map(value => value.trim()).filter(Boolean);
    const groups = {
      files: new Set(['file-upload-picker', 'file-create', 'folder-create', 'file-move', 'file-copy', 'file-delete', 'file-extract', 'file-restore-trash', 'trash-empty', 'files-archive-selected', 'files-delete-selected', 'files-move-selected', 'editor-save', 'editor-save-as', 'editor-restore-version', 'backup-directory']),
      mysql: new Set(['database-create', 'database-delete', 'database-connect', 'database-import', 'db-user-create', 'db-user-password', 'db-user-delete', 'db-remote-add', 'db-remote-delete', 'table-create', 'table-operation', 'column-add', 'column-edit', 'column-delete', 'index-add', 'index-delete', 'row-insert', 'row-edit', 'row-delete', 'row-bulk-delete', 'sql-execute', 'sql-save-query', 'sql-delete-saved', 'backup-database']),
      domains: new Set(['domain-create', 'domain-delete', 'domain-dns', 'subdomain-create', 'subdomain-delete', 'redirect-create', 'redirect-delete']),
      email: new Set(['email-create', 'email-password', 'email-quota', 'email-delete', 'forwarder-create', 'forwarder-delete', 'autoresponder-create', 'autoresponder-edit', 'autoresponder-delete']),
      ssl: new Set(['ssl-autossl']),
      cron: new Set(['cron-create', 'cron-edit', 'cron-toggle', 'cron-delete']),
      backup: new Set(['backup-full']),
      php: new Set(['php-version', 'php-ini']),
    };
    if (['deployment-package', 'deployment-rollback'].includes(action)) return ['files'];
    for (const [capability, actions] of Object.entries(groups)) if (actions.has(action)) return [capability];
    return [];
  }
  assertWritableCapabilities(names) {
    for (const name of Array.isArray(names) ? names : [names]) {
      const capability = this.capability(name);
      if (capability && (!capability.available || !capability.writable)) {
        throw new ApiError({code: 'capability_read_only', message: capability.available ? t('capabilityReadOnly') : t('capabilityUnavailableBody'), help_slug: 'hosts.connection-errors'}, 409);
      }
    }
  }
  applyCapabilityPolicy(root) {
    $$('[data-action]', root).forEach(element => {
      const required = this.mutationCapabilities(element.dataset.action, element.dataset);
      const blocked = required.some(name => { const capability = this.capability(name); return capability && (!capability.available || !capability.writable); });
      if (!blocked) return;
      element.disabled = true;
      element.setAttribute('aria-disabled', 'true');
      element.title = required.some(name => this.capability(name)?.available === false) ? t('capabilityUnavailableBody') : t('capabilityReadOnly');
    });
  }
  toolTile(route, icon, label = route) {
    const missing = this.missingCapability(this.routeCapabilities(route));
    if (!missing) return `<a href="?route=${route}" class="tool-tile" data-route="${route}"><span class="symbol">${icon}</span>${escapeHtml(t(label))}</a>`;
    return `<button type="button" class="tool-tile unavailable" disabled title="${escapeAttr(t('capabilityUnavailableBody'))}"><span class="symbol" aria-hidden="true">${icon}</span>${escapeHtml(t(label))}<small>${escapeHtml(t('unavailable'))}: ${escapeHtml(missing)}</small></button>`;
  }
  favorites() { return Array.isArray(this.dashboardData?.favorites) ? this.dashboardData.favorites : []; }
  favoriteFor(type, reference, accountId = this.hostId) { return this.favorites().find(item => item.resource_type === type && String(item.resource_ref) === String(reference) && Number(item.account_id || 0) === Number(accountId || 0)); }
  favoriteButton(type, reference, label, accountId = this.hostId) { const favorite = this.favoriteFor(type, reference, accountId); return `<button class="icon-button" data-action="favorite-toggle" data-type="${escapeAttr(type)}" data-reference="${escapeAttr(reference)}" data-label="${escapeAttr(label)}" data-account="${Number(accountId || 0)}" aria-label="${escapeAttr(t(favorite ? 'unfavorite' : 'favorite'))}" title="${escapeAttr(t(favorite ? 'unfavorite' : 'favorite'))}">${favorite ? '★' : '☆'}</button>`; }

  async refreshDashboard(render = true) {
    const result = await this.api.request('/api/v1/dashboard');
    this.dashboardData = result;
    this.user = result.user;
    this.hosts = Array.isArray(result.hosts) ? result.hosts : [];
    if (!this.hosts.some(host => Number(host.id) === this.hostId)) this.hostId = Number(this.hosts[0]?.id || 0);
    if (this.hostId) localStorage.setItem('tcpm.host', String(this.hostId)); else localStorage.removeItem('tcpm.host');
    this.syncHostPicker();
    if (render) await this.render();
  }

  syncHostPicker() {
    this.hostSelect.innerHTML = this.hosts.length ? this.hosts.map(host => `<option value="${Number(host.id)}" ${Number(host.id) === this.hostId ? 'selected' : ''}>${escapeHtml(host.label || host.hostname)}</option>`).join('') : `<option value="">${escapeHtml(t('selectHost'))}</option>`;
    this.hostSelect.disabled = this.hosts.length === 0;
    $('#host-label').textContent = t('host');
  }

  renderNoHost() {
    this.setTitle('myHosts');
    this.content.innerHTML = `<section class="empty-state"><div class="file-icon" aria-hidden="true">🖥</div><h2>${escapeHtml(t('noHostTitle'))}</h2><p>${escapeHtml(t('noHostBody'))}</p><div class="toolbar"><button class="button" data-action="add-host">${escapeHtml(t('addFirstHost'))}</button><button class="button secondary" data-route="help" data-slug="token.create">${escapeHtml(t('learnToken'))}</button></div></section>`;
  }

  async pageDashboard() {
    this.loading('dashboard');
    await this.refreshDashboard(false);
    const name = this.user?.first_name || this.user?.username || '';
    const plan = this.dashboardData?.plan || {};
    const tools = [['files', '📂', 'files'], ['databases', '🗄', 'databases'], ['domains', '🌐', 'domains'], ['email', '✉️', 'email'], ['ssl', '🔒', 'ssl'], ['cron', '⏱', 'cron'], ['backups', '💾', 'backups'], ['deploy', '🚀', 'deploy'], ['usage', '📊', 'usage'], ['logs', '📜', 'logs'], ['security', '🛡', 'security'], ['help', '❓', 'help']];
    const hostCards = this.hosts.map(host => this.hostCard(host)).join('');
    const notifications = (this.dashboardData.notifications || []).map(item => `<article class="list-row"><span class="file-icon">🔔</span><div class="grow"><strong>${escapeHtml(item.title || t('notifications'))}</strong><small>${escapeHtml(item.body || '')} · ${escapeHtml(dateText(item.created_at))}</small></div>${item.read_at ? '' : `<button class="button secondary small" data-action="notification-read" data-id="${Number(item.id)}">${escapeHtml(t('markRead'))}</button>`}</article>`).join('');
    const favorites = this.favorites().map(item => `<article class="list-row"><button class="grow link-button" data-action="favorite-open" data-id="${Number(item.id)}"><strong>★ ${escapeHtml(item.label || item.resource_ref)}</strong><small>${escapeHtml(t(item.resource_type))} · ${escapeHtml(item.resource_ref)}</small></button><button class="icon-button" data-action="favorite-remove" data-id="${Number(item.id)}" aria-label="${escapeAttr(t('unfavorite'))}">×</button></article>`).join('');
    const recent = (this.dashboardData.recent || []).slice(0, 12).map(item => `<article class="list-row"><span class="file-icon">↻</span><div class="grow"><strong>${escapeHtml(item.action)}</strong><small>${escapeHtml(item.resource_ref || '')} · ${escapeHtml(dateText(item.created_at))}</small></div></article>`).join('');
    this.content.innerHTML = `${this.pageHead('dashboard', t('welcome', {name}))}
      ${this.hosts.length ? `<section><div class="page-head"><div><h2>${escapeHtml(t('myHosts'))}</h2><p>${escapeHtml(t('plan'))}: ${escapeHtml(plan[language() === 'fa' ? 'name_fa' : 'name_en'] || plan.slug || '—')}</p></div><button class="button" data-action="add-host">＋ ${escapeHtml(t('addHost'))}</button></div><div class="cards">${hostCards}</div></section>` : `<section class="empty-state card"><h2>${escapeHtml(t('noHostTitle'))}</h2><p>${escapeHtml(t('noHostBody'))}</p><div class="toolbar"><button class="button" data-action="add-host">${escapeHtml(t('addFirstHost'))}</button><button class="button secondary" data-route="help" data-slug="token.create">${escapeHtml(t('learnToken'))}</button></div></section>`}
      <section><h2>${escapeHtml(t('sections'))}</h2><div class="tool-grid">${tools.map(([route, icon, label]) => this.toolTile(route, icon, label)).join('')}</div></section>
      ${favorites ? `<section><h2>${escapeHtml(t('favorites'))}</h2><div class="list">${favorites}</div></section>` : ''}
      ${recent ? `<section><h2>${escapeHtml(t('recentActions'))}</h2><div class="list">${recent}</div></section>` : ''}
      ${notifications ? `<section><h2>${escapeHtml(t('notifications'))}</h2><div class="list">${notifications}</div></section>` : ''}`;
    this.observeHostSummaries();
  }

  hostCard(host) {
    const healthy = host.status === 'active';
    return `<article class="card host-card ${healthy ? '' : 'error'}" data-host-card="${Number(host.id)}"><div class="page-head"><div><h3>${escapeHtml(host.label || host.hostname)}</h3><span class="badge ${healthy ? 'ok' : 'error'}">${healthy ? '●' : '!' } ${escapeHtml(statusText(host.status))}</span></div><div class="row-actions">${this.favoriteButton('host', String(host.id), host.label || host.hostname, Number(host.id))}<button class="icon-button" data-action="host-edit" data-id="${Number(host.id)}" aria-label="${escapeAttr(t('edit'))}">✎</button></div></div><dl class="key-values"><dt>${escapeHtml(t('domain'))}</dt><dd>${escapeHtml(host.main_domain || host.hostname)}</dd><dt>${escapeHtml(t('username'))}</dt><dd>${escapeHtml(host.cpanel_username)}</dd><dt>${escapeHtml(t('disk'))}</dt><dd data-host-disk>—</dd><dt>${escapeHtml(t('ssl'))}</dt><dd data-host-ssl>—</dd><dt>${escapeHtml(t('api'))}</dt><dd>${Number(host.capability_count || 0)} ${escapeHtml(t('available'))}</dd><dt>${escapeHtml(t('lastCheck'))}</dt><dd>${escapeHtml(dateText(host.last_checked_at))}</dd></dl><div class="row-actions"><button class="button small" data-action="host-open" data-id="${Number(host.id)}">${escapeHtml(t('open'))}</button><button class="button secondary small" data-action="host-test-token" data-id="${Number(host.id)}">${escapeHtml(t('testToken'))}</button><button class="button secondary small" data-action="host-health" data-id="${Number(host.id)}">${escapeHtml(t('healthCheck'))}</button><button class="button secondary small" data-action="host-token" data-id="${Number(host.id)}">${escapeHtml(t('rotateToken'))}</button><button class="button danger small" data-action="host-remove" data-id="${Number(host.id)}">${escapeHtml(t('remove'))}</button></div></article>`;
  }

  observeHostSummaries() {
    const observer = new IntersectionObserver(entries => entries.forEach(entry => { if (entry.isIntersecting) { observer.unobserve(entry.target); this.hydrateHostSummary(entry.target).catch(() => {}); } }), {rootMargin: '100px'});
    $$('[data-host-card]', this.content).forEach(card => observer.observe(card));
  }

  async hydrateHostSummary(card) {
    const id = Number(card.dataset.hostCard);
    const key = `host-summary:${id}`;
    const host = this.hosts.find(item => Number(item.id) === id);
    let summary = this.cached(key);
    if (!summary) {
      const canUse = name => host?.capabilities?.[name]?.available !== false;
      const [usage, ssl] = await Promise.allSettled([canUse('usage') ? this.api.request(`/api/v1/hosts/${id}/usage`) : Promise.resolve(null), canUse('ssl') ? this.api.request(`/api/v1/hosts/${id}/ssl`) : Promise.resolve(null)]);
      summary = {usage: usage.status === 'fulfilled' ? usage.value : null, ssl: ssl.status === 'fulfilled' ? ssl.value : null};
      this.remember(key, summary, 60000);
    }
    const disk = this.findNumeric(summary.usage, ['used', 'diskused', 'disk_usage', 'bytes_used']);
    $('[data-host-disk]', card).textContent = disk === null ? '—' : bytes(disk);
    const sslItems = asList(summary.ssl, ['certificates', 'domains', 'items']);
    $('[data-host-ssl]', card).textContent = summary.ssl ? `${sslItems.filter(item => item?.is_valid !== false && item?.status !== 'error').length}/${sslItems.length || '—'}` : '—';
  }

  findNumeric(value, keys) {
    if (!value || typeof value !== 'object') return null;
    for (const [key, child] of Object.entries(value)) {
      if (keys.includes(key.toLowerCase()) && Number.isFinite(Number(child))) return Number(child);
      if (child && typeof child === 'object') { const found = this.findNumeric(child, keys); if (found !== null) return found; }
    }
    return null;
  }

  async pageFiles() {
    if (!this.requireHost() || !this.requireCapability('files', 'fileManager')) return;
    this.loading('fileManager');
    const path = this.params.path || '.';
    const page = Math.max(1, Number(this.params.page || 1));
    const sort = this.params.sort || 'name';
    const direction = this.params.direction || 'asc';
    const hidden = this.params.hidden === '1';
    const result = await this.api.request(query(this.hostPath('/files'), {path, page, per_page: 50, sort, direction, hidden: hidden ? 1 : 0}), {signal: this.abortController.signal});
    this.params.path = result.path;
    const isTrashPath = /\/\.trash(?:\/|$)/.test(String(result.path));
    this.fileSelection.clear();
    const breadcrumbs = this.breadcrumbs(result.path);
    const items = (result.items || []).map(item => this.fileRow(item)).join('');
    const mode = localStorage.getItem('tcpm.fileMode') === 'grid' ? 'grid' : 'list';
    this.content.innerHTML = `${this.pageHead('fileManager', result.path, `<button class="button" data-action="file-upload-picker">＋ ${escapeHtml(t('upload'))}</button>`)}
      <div class="breadcrumbs" aria-label="${escapeAttr(t('path'))}">${breadcrumbs}</div>
      <div class="toolbar sticky"><button class="button secondary small" data-action="file-create">${escapeHtml(t('newFile'))}</button><button class="button secondary small" data-action="folder-create">${escapeHtml(t('newFolder'))}</button><button class="button secondary small" data-action="file-search">${escapeHtml(t('search'))}</button><button class="button secondary small" data-action="file-trash">${escapeHtml(t('trash'))}</button>${isTrashPath ? `<button class="button danger small" data-action="trash-empty">${escapeHtml(t('emptyTrash'))}</button>` : ''}<span class="segmented"><button data-action="file-view" data-mode="list" class="${mode === 'list' ? 'active' : ''}">${escapeHtml(t('list'))}</button><button data-action="file-view" data-mode="grid" class="${mode === 'grid' ? 'active' : ''}">${escapeHtml(t('grid'))}</button></span><select class="input" id="file-sort" aria-label="${escapeAttr(t('sortName'))}"><option value="name">${escapeHtml(t('sortName'))}</option><option value="size">${escapeHtml(t('sortSize'))}</option><option value="mtime">${escapeHtml(t('sortTime'))}</option></select><label class="check"><input id="show-hidden" type="checkbox" ${hidden ? 'checked' : ''}>${escapeHtml(t('hidden'))}</label></div>
      <div id="file-selection-toolbar" class="toolbar" hidden></div>
      <label class="drop-zone" id="drop-zone"><input id="file-input" class="sr-only" type="file" multiple><strong>${escapeHtml(t('dragUpload'))}</strong></label>
      ${items ? `<div id="file-list" class="${mode === 'grid' ? 'file-grid' : 'list'}">${items}</div>` : `<section class="empty-state card"><h2>${escapeHtml(t('noFilesTitle'))}</h2><p>${escapeHtml(t('noFilesBody'))}</p></section>`}
      ${this.pagination(result.pagination, 'files', {path: result.path, sort, direction, hidden: hidden ? 1 : 0})}`;
    $('#file-sort').value = sort;
    $('#file-sort').addEventListener('change', event => this.navigate('files', {path: result.path, sort: event.target.value, direction, hidden: hidden ? 1 : 0}));
    $('#show-hidden').addEventListener('change', event => this.navigate('files', {path: result.path, sort, direction, hidden: event.target.checked ? 1 : 0}));
    this.bindFileUpload(result.path);
  }

  breadcrumbs(path) {
    const host = this.activeHost();
    const root = String(host?.root_path || `/home/${host?.cpanel_username || ''}`).replace(/\/$/, '');
    const relative = path.startsWith(root) ? path.slice(root.length).replace(/^\//, '') : path.replace(/^\//, '');
    let current = root;
    const parts = relative ? relative.split('/').filter(Boolean) : [];
    let output = `<button data-action="file-path" data-path="${escapeAttr(root)}">${escapeHtml(t('home'))}</button>`;
    for (const part of parts) { current += `/${part}`; output += `<span>/</span><button data-action="file-path" data-path="${escapeAttr(current)}">${escapeHtml(part)}</button>`; }
    return output;
  }

  fileRow(item) {
    const path = String(item.path || item.fullpath || '');
    const name = String(item.file || item.name || path.split('/').pop() || '');
    const type = String(item.type || '').toLowerCase();
    const directory = ['dir', 'directory'].includes(type);
    const extension = name.split('.').pop()?.toLowerCase() || '';
    const text = ['php', 'html', 'htm', 'css', 'js', 'json', 'xml', 'txt', 'env', 'htaccess', 'conf', 'ini', 'md', 'log', 'sql'].includes(extension) || ['.env', '.htaccess'].includes(name.toLowerCase());
    const archive = /\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|gz|bz2)$/i.test(name);
    const inTrash = path.includes('/.trash/');
    return `<article class="list-row selectable"><input type="checkbox" class="file-check" data-action="file-select" data-path="${escapeAttr(path)}" aria-label="${escapeAttr(t('selected', {count: 1}))}"><span class="file-icon">${directory ? '📁' : archive ? '🗜' : text ? '⌘' : '📄'}</span><button class="grow link-button" data-action="file-open" data-path="${escapeAttr(path)}" data-directory="${directory ? 1 : 0}" data-text="${text ? 1 : 0}"><strong>${escapeHtml(name)}</strong><small>${directory ? escapeHtml(t('folderName')) : bytes(item.size)} · ${escapeHtml(String(item.mtime || item.modified || ''))}</small></button><div class="row-actions">${this.favoriteButton(directory ? 'directory' : 'file', path, name)}<button class="icon-button" data-action="file-download" data-path="${escapeAttr(path)}" title="${escapeAttr(t('download'))}" ${directory ? 'disabled' : ''}>↓</button><button class="icon-button" data-action="file-move" data-path="${escapeAttr(path)}" title="${escapeAttr(t('rename'))}">✎</button><button class="icon-button" data-action="file-copy" data-path="${escapeAttr(path)}" title="${escapeAttr(t('copy'))}">⧉</button>${archive ? `<button class="icon-button" data-action="file-extract" data-path="${escapeAttr(path)}" title="${escapeAttr(t('extract'))}">⇱</button>` : ''}${inTrash ? `<button class="icon-button" data-action="file-restore-trash" data-path="${escapeAttr(path)}" title="${escapeAttr(t('restore'))}">↶</button>` : `<button class="icon-button" data-action="file-delete" data-path="${escapeAttr(path)}" data-directory="${directory ? 1 : 0}" title="${escapeAttr(t('remove'))}">×</button>`}<button class="icon-button" data-action="file-info" data-path="${escapeAttr(path)}" title="${escapeAttr(t('details'))}">i</button></div></article>`;
  }

  pagination(pagination = {}, route, params = {}) {
    const page = Number(pagination.page || 1);
    return `<nav class="pagination"><button class="button secondary" data-route="${route}" ${page <= 1 ? 'disabled' : ''} ${this.paramAttrs({...params, page: page - 1})}>${escapeHtml(t('previous'))}</button><span>${page}</span><button class="button secondary" data-route="${route}" ${!pagination.has_more ? 'disabled' : ''} ${this.paramAttrs({...params, page: page + 1})}>${escapeHtml(t('next'))}</button></nav>`;
  }

  paramAttrs(params) { return Object.entries(params).map(([key, value]) => `data-${escapeAttr(key.replaceAll('_', '-'))}="${escapeAttr(value)}"`).join(' '); }

  bindFileUpload(directory) {
    const input = $('#file-input');
    const zone = $('#drop-zone');
    input.addEventListener('change', () => this.uploadFiles([...input.files], directory));
    zone.addEventListener('dragenter', event => { event.preventDefault(); this.dragDepth += 1; zone.classList.add('dragging'); });
    zone.addEventListener('dragover', event => event.preventDefault());
    zone.addEventListener('dragleave', () => { this.dragDepth -= 1; if (this.dragDepth <= 0) zone.classList.remove('dragging'); });
    zone.addEventListener('drop', event => { event.preventDefault(); this.dragDepth = 0; zone.classList.remove('dragging'); this.uploadFiles([...event.dataTransfer.files], directory); });
  }

  async uploadFiles(files, directory) {
    this.assertWritableCapabilities('files');
    if (!files.length) return;
    if (files.length > 20) { this.toast(t('uploadCountLimit'), 'warning'); return; }
    const filenames = files.map(file => file.name);
    this.openForm('upload', `<div class="notice"><strong>${escapeHtml(t('info'))}</strong>${escapeHtml(t('dragUpload'))}</div><div class="field"><label>${escapeHtml(t('collision'))}</label><select name="collision"><option value="reject">${escapeHtml(t('reject'))}</option><option value="rename">${escapeHtml(t('autoRename'))}</option><option value="overwrite">${escapeHtml(t('overwrite'))}</option></select></div><div class="notice danger" id="upload-overwrite-warning" hidden><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningUploadOverwrite'))}<pre class="code">${escapeHtml(filenames.join('\n'))}</pre><label class="check"><input name="confirm_overwrite" type="checkbox">${escapeHtml(t('confirmUploadOverwrite'))}</label></div><div class="job"><div class="progress"><span id="upload-bar" style="width:0%"></span></div><span id="upload-text">${escapeHtml(t('uploadProgress', {percent: 0}))}</span></div>`, 'upload', async form => {
      const collision = form.elements.collision.value;
      if (collision === 'overwrite' && !form.elements.confirm_overwrite.checked) { this.toast(t('confirmUploadOverwrite'), 'warning'); return; }
      let confirmation = '';
      if (collision === 'overwrite') {
        const issued = await this.api.request(this.hostPath('/files/upload-overwrite-confirmation'), {method: 'POST', body: {directory, filenames}});
        confirmation = issued.nonce;
      }
      const data = new FormData();
      files.forEach(file => data.append('files[]', file, file.name));
      data.append('directory', directory);
      data.append('collision', collision);
      if (confirmation) data.append('confirmation', confirmation);
      await this.api.upload(this.hostPath('/files/upload'), data, percent => { $('#upload-bar').style.width = `${percent}%`; $('#upload-text').textContent = t('uploadProgress', {percent}); });
      this.closeDialog(); this.haptic('success'); this.toast(t('success')); await this.render();
    });
    const collision = $('select[name="collision"]', this.dialogForm);
    const warning = $('#upload-overwrite-warning', this.dialogForm);
    const checkbox = $('input[name="confirm_overwrite"]', this.dialogForm);
    const syncWarning = () => { const active = collision.value === 'overwrite'; warning.hidden = !active; checkbox.required = active; if (!active) checkbox.checked = false; };
    collision.addEventListener('change', syncWarning);
    syncWarning();
  }

  async pageEditor() {
    if (!this.requireHost() || !this.requireCapability('files', 'editor')) return;
    const path = this.params.path || '';
    if (!path) { this.navigate('files', {}, true); return; }
    this.loading('editor');
    const content = await this.api.request(query(this.hostPath('/files/content'), {path, offset: 0, length: 262144}), {signal: this.abortController.signal});
    this.editorState = {path, content: content.content || '', offset: Number(content.length || 0), total: Number(content.total_bytes || 0), hasMore: Boolean(content.has_more), language: content.language || 'text', dirty: false};
    this.content.innerHTML = `${this.pageHead('editor', path)}<div class="toolbar sticky"><button class="button" data-action="editor-save">${escapeHtml(t('save'))}</button><button class="button secondary" data-action="editor-save-as">${escapeHtml(t('saveAs'))}</button><button class="button secondary" data-action="editor-find">${escapeHtml(t('find'))}</button><button class="button secondary" data-action="editor-replace">${escapeHtml(t('replace'))}</button><button class="button secondary" data-action="editor-undo">${escapeHtml(t('undo'))}</button><button class="button secondary" data-action="editor-redo">${escapeHtml(t('redo'))}</button><button class="button secondary" data-action="editor-wrap">${escapeHtml(t('wordWrap'))}</button><button class="button secondary" data-action="editor-fullscreen">${escapeHtml(t('fullscreen'))}</button><button class="button secondary" data-action="editor-versions">${escapeHtml(t('versions'))}</button><button class="button secondary" data-action="file-download" data-path="${escapeAttr(path)}">${escapeHtml(t('download'))}</button></div>${content.has_more ? `<div class="notice warning"><strong>${escapeHtml(bytes(content.total_bytes))}</strong>${escapeHtml(t('loadMore'))}<button class="button secondary small" data-action="editor-load-more">${escapeHtml(t('loadMore'))}</button></div>` : ''}<label class="check"><input id="editor-backup" type="checkbox" checked>${escapeHtml(t('backupBeforeSave'))}</label><div class="editor-shell" id="editor-shell"><div id="code-editor" aria-label="${escapeAttr(t('editor'))}"></div></div>`;
    await this.mountEditor();
  }

  async mountEditor() {
    await this.ensureAce();
    const modes = {php: 'php', html: 'html', htm: 'html', css: 'css', js: 'javascript', javascript: 'javascript', json: 'json', xml: 'xml', sql: 'sql', env: 'sh', ini: 'ini', md: 'markdown', markdown: 'markdown', apache: 'text', log: 'text', txt: 'text'};
    this.editor = window.ace.edit('code-editor');
    window.ace.config.set('basePath', '/miniapp/vendor/ace');
    this.editor.setTheme(tg?.colorScheme === 'light' ? 'ace/theme/chrome' : 'ace/theme/tomorrow_night_eighties');
    this.editor.session.setMode(`ace/mode/${modes[this.editorState.language] || 'text'}`);
    this.editor.session.setUseWorker(false);
    this.editor.session.setUseWrapMode(true);
    this.editor.setOptions({fontSize: '14px', showPrintMargin: false, tabSize: 2, useSoftTabs: true, enableBasicAutocompletion: false});
    this.editor.setValue(this.editorState.content, -1);
    this.editor.session.getUndoManager().reset();
    this.editor.on('change', () => { this.editorState.dirty = true; this.showEditorMainButton(); });
    this.showEditorMainButton();
  }

  ensureAce() {
    if (window.ace) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = '/miniapp/vendor/ace/ace.js';
      script.onload = resolve;
      script.onerror = () => reject(new ApiError({code: 'editor_asset_failed', message: t('editorLoadFailed')}, 500));
      document.head.appendChild(script);
    });
  }

  showEditorMainButton() {
    if (!tg?.MainButton) return;
    tg.MainButton.setText(t('save'));
    if (this.editorState?.dirty && !this.editorState?.hasMore) tg.MainButton.show(); else tg.MainButton.hide();
    tg.MainButton.offClick?.(this._mainButtonHandler);
    this._mainButtonHandler = () => this.saveEditor().catch(error => this.operationError(error));
    tg.MainButton.onClick(this._mainButtonHandler);
  }

  hideMainButton() {
    if (!tg?.MainButton) return;
    if (this._mainButtonHandler) tg.MainButton.offClick?.(this._mainButtonHandler);
    tg.MainButton.hide();
  }

  async saveEditor(target = null) {
    if (!this.editor || !this.editorState || this.editorState.hasMore) {
      this.toast(t('loadMore'), true); return;
    }
    const path = target || this.editorState.path;
    const sensitive = ['.htaccess', 'index.php'].includes(path.split('/').pop().toLowerCase());
    const perform = async confirmation => {
      await this.api.request(this.hostPath('/files/content'), {method: 'PUT', body: {path, content: this.editor.getValue(), backup: target ? false : $('#editor-backup')?.checked !== false, confirmation}});
      this.editorState.path = path; this.editorState.dirty = false; this.editor.session.getUndoManager().markClean(); this.hideMainButton(); this.toast(t('success')); this.haptic('success');
      if (target) this.navigate('editor', {path}, true);
    };
    if (sensitive) return this.confirmOperation({warningKey: 'warningFileDelete', action: 'file.replace_sensitive', target: path, summary: `${t('path')}: ${path}`, perform});
    await perform(null);
  }

  async loadEditorRemainder() {
    while (this.editorState.hasMore) {
      const chunk = await this.api.request(query(this.hostPath('/files/content'), {path: this.editorState.path, offset: this.editorState.offset, length: 262144}));
      this.editorState.content += chunk.content || '';
      this.editorState.offset += Number(chunk.length || 0);
      this.editorState.hasMore = Boolean(chunk.has_more);
      if (!chunk.length && chunk.has_more) throw new ApiError({code: 'editor_chunk_stalled', message: t('editorStalled')}, 500);
    }
    this.editor.setValue(this.editorState.content, -1); this.editor.session.getUndoManager().reset(); this.editorState.dirty = false; this.showEditorMainButton();
    $('.notice.warning', this.content)?.remove();
  }

  async pageDatabases() {
    if (!this.requireHost() || !this.requireCapability('mysql', 'databaseManager')) return;
    this.loading('databaseManager');
    const [databaseResult, userResult, connectionResult] = await Promise.all([
      this.api.request(this.hostPath('/databases'), {signal: this.abortController.signal}),
      this.api.request(this.hostPath('/database-users'), {signal: this.abortController.signal}),
      this.api.request(this.hostPath('/database-connections'), {signal: this.abortController.signal})
    ]);
    const databases = asList(databaseResult.databases, ['databases', 'items']);
    const users = asList(userResult.users, ['users', 'items']);
    const connections = connectionResult.connections || [];
    this.databaseItems = databases;
    this.databaseUsers = users;
    const cards = databases.map(item => {
      const name = nameOf(item) || String(item.database_name || '');
      const connection = connections.find(row => row.database_name === name);
      return `<article class="card"><div class="page-head"><div><h3>${escapeHtml(name)}</h3><span class="badge ${connection?.status === 'active' ? 'ok' : 'warning'}">${escapeHtml(connection?.status || t('disabled'))}</span></div><div class="row-actions">${this.favoriteButton('database', name, name)}<span>${escapeHtml(bytes(item.disk_usage || item.size || item.size_bytes))}</span></div></div><dl class="key-values"><dt>${escapeHtml(t('tables'))}</dt><dd>${escapeHtml(item.table_count ?? item.tables ?? '—')}</dd><dt>${escapeHtml(t('dbUsers'))}</dt><dd>${escapeHtml(item.users?.length ?? '—')}</dd></dl><div class="row-actions"><button class="button small" data-route="database" data-database="${escapeAttr(name)}">${escapeHtml(t('open'))}</button><button class="button secondary small" data-action="database-connect" data-database="${escapeAttr(name)}">${escapeHtml(connection?.status === 'active' ? t('refresh') : t('connect'))}</button><button class="button secondary small" data-route="sql" data-database="${escapeAttr(name)}">SQL</button><button class="button secondary small" data-action="database-export" data-database="${escapeAttr(name)}">${escapeHtml(t('exportDatabase'))}</button><button class="button danger small" data-action="database-delete" data-database="${escapeAttr(name)}">${escapeHtml(t('remove'))}</button></div></article>`;
    }).join('');
    this.content.innerHTML = `${this.pageHead('databaseManager', '', `<button class="button" data-action="database-create">＋ ${escapeHtml(t('create'))}</button>`)}<div class="toolbar"><button class="button secondary" data-action="db-users">${escapeHtml(t('dbUsers'))} (${users.length})</button><button class="button secondary" data-action="db-privileges">${escapeHtml(t('privileges'))} ⓘ</button><button class="button secondary" data-action="db-remote">${escapeHtml(t('remoteMysql'))} ⓘ</button><button class="button secondary" data-action="database-import">${escapeHtml(t('importSql'))}</button></div>${cards ? `<div class="cards">${cards}</div>` : `<section class="empty-state card"><h2>${escapeHtml(t('noDatabaseTitle'))}</h2><p>${escapeHtml(t('noDatabaseBody'))}</p><button class="button" data-action="database-create">${escapeHtml(t('create'))}</button></section>`}`;
  }

  async pageDatabase() {
    if (!this.requireHost() || !this.requireCapability('mysql', 'tables')) return;
    const database = this.params.database || '';
    if (!database) { this.navigate('databases', {}, true); return; }
    this.loading('tables');
    const result = await this.api.request(this.hostPath(`/databases/${encodeURIComponent(database)}/tables`), {signal: this.abortController.signal});
    const tables = result.tables || [];
    const cards = tables.map(item => `<article class="list-row"><span class="file-icon">▦</span><div class="grow"><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(String(item.engine || ''))} · ${escapeHtml(String(item.row_count ?? 0))} ${escapeHtml(t('rows'))} · ${escapeHtml(bytes(item.size_bytes))}</small></div><div class="row-actions"><button class="button small" data-route="table" data-database="${escapeAttr(database)}" data-table="${escapeAttr(item.name)}">${escapeHtml(t('open'))}</button><button class="button secondary small" data-action="table-export" data-database="${escapeAttr(database)}" data-table="${escapeAttr(item.name)}">${escapeHtml(t('exportDatabase'))}</button><button class="button danger small" data-action="table-operation" data-database="${escapeAttr(database)}" data-table="${escapeAttr(item.name)}" data-operation="DROP">DROP</button></div></article>`).join('');
    this.content.innerHTML = `${this.pageHead('tables', database, `<button class="button" data-action="table-create" data-database="${escapeAttr(database)}">＋ ${escapeHtml(t('createTable'))}</button>`)}<div class="toolbar"><button class="button secondary" data-route="sql" data-database="${escapeAttr(database)}">${escapeHtml(t('sqlConsole'))}</button><button class="button secondary" data-action="database-import" data-database="${escapeAttr(database)}">${escapeHtml(t('importSql'))}</button><button class="button secondary" data-action="database-export" data-database="${escapeAttr(database)}">${escapeHtml(t('exportDatabase'))}</button><button class="button secondary" data-route="databases">${escapeHtml(t('dbUsers'))}</button></div>${cards ? `<div class="list">${cards}</div>` : `<section class="empty-state card"><h2>${escapeHtml(t('tables'))}</h2><p>${escapeHtml(t('noRowsBody'))}</p><button class="button" data-action="table-create" data-database="${escapeAttr(database)}">${escapeHtml(t('createTable'))}</button></section>`}`;
  }

  async pageTable() {
    if (!this.requireHost() || !this.requireCapability('mysql', 'table')) return;
    const database = this.params.database || '', table = this.params.table || '';
    if (!database || !table) { this.navigate('databases', {}, true); return; }
    this.loading('table');
    const page = Math.max(1, Number(this.params.page || 1));
    const filter = this.params.filterColumn ? [{column: this.params.filterColumn, operator: this.params.operator || 'LIKE', value: this.params.value || ''}] : [];
    const [rows, structure] = await Promise.all([
      this.api.request(query(this.hostPath(`/databases/${encodeURIComponent(database)}/tables/${encodeURIComponent(table)}/rows`), {page, per_page: 50, sort: this.params.sort, direction: this.params.direction || 'asc', filters: filter}), {signal: this.abortController.signal}),
      this.api.request(this.hostPath(`/databases/${encodeURIComponent(database)}/tables/${encodeURIComponent(table)}`), {signal: this.abortController.signal})
    ]);
    this.tableRows = rows.rows || [];
    this.tableColumns = rows.columns || [];
    this.tableStructure = structure;
    this.tableSelection = new Map();
    const visibleKey = `tcpm.columns.${this.hostId}.${database}.${table}`;
    const storedVisible = JSON.parse(localStorage.getItem(visibleKey) || 'null');
    const allColumns = this.tableColumns.map(column => column.name);
    this.visibleColumns = new Set(Array.isArray(storedVisible) ? storedVisible.filter(name => allColumns.includes(name)) : allColumns);
    if (!this.visibleColumns.size) this.visibleColumns = new Set(allColumns);
    const columns = allColumns.filter(name => this.visibleColumns.has(name));
    const primary = this.tableColumns.filter(column => column.column_key === 'PRI').map(column => column.name);
    this.tablePrimary = primary;
    const heading = `<tr><th><input id="rows-all" type="checkbox" aria-label="${escapeAttr(t('selected', {count: this.tableRows.length}))}"></th>${columns.map(column => `<th><button class="link-button" data-action="table-sort" data-column="${escapeAttr(column)}">${escapeHtml(column)}</button></th>`).join('')}<th>${escapeHtml(t('actions'))}</th></tr>`;
    const body = this.tableRows.map((row, index) => `<tr><td><input class="row-check" type="checkbox" data-action="row-select" data-index="${index}"></td>${columns.map(column => `<td title="${escapeAttr(row[column] === null ? 'NULL' : String(row[column]))}">${row[column] === null ? '<em>NULL</em>' : escapeHtml(String(row[column]))}</td>`).join('')}<td><div class="row-actions"><button class="button secondary small" data-action="row-details" data-index="${index}">${escapeHtml(t('details'))}</button><button class="button secondary small" data-action="row-edit" data-index="${index}" ${primary.length ? '' : 'disabled'}>${escapeHtml(t('edit'))}</button><button class="button danger small" data-action="row-delete" data-index="${index}" ${primary.length ? '' : 'disabled'}>${escapeHtml(t('remove'))}</button></div></td></tr>`).join('');
    this.content.innerHTML = `${this.pageHead('table', `${database} / ${table}`)}<div class="tabs"><button class="active">${escapeHtml(t('rows'))}</button><button data-action="table-structure">${escapeHtml(t('structure'))}</button><button data-route="sql" data-database="${escapeAttr(database)}">SQL</button></div><div class="toolbar"><button class="button" data-action="row-insert">＋ ${escapeHtml(t('insertRow'))}</button><button class="button danger" data-action="row-bulk-delete" disabled id="bulk-delete">${escapeHtml(t('bulkDelete'))}</button><button class="button secondary" data-action="row-export" disabled id="row-export">${escapeHtml(t('exportSelected'))}</button><button class="button secondary" data-action="column-visibility">${escapeHtml(t('visibleColumns'))}</button><button class="button secondary" data-action="table-filter">${escapeHtml(t('search'))}</button><button class="button secondary advanced-only" data-action="table-operation" data-database="${escapeAttr(database)}" data-table="${escapeAttr(table)}" data-operation="OPTIMIZE">${escapeHtml(t('optimize'))}</button><button class="button warning advanced-only" data-action="table-operation" data-database="${escapeAttr(database)}" data-table="${escapeAttr(table)}" data-operation="TRUNCATE">TRUNCATE ⓘ</button></div>${this.tableRows.length ? `<div class="table-wrap"><table><thead>${heading}</thead><tbody>${body}</tbody></table></div>` : `<section class="empty-state card"><h2>${escapeHtml(t('noRowsTitle'))}</h2><p>${escapeHtml(t('noRowsBody'))}</p><button class="button" data-action="row-insert">${escapeHtml(t('insertRow'))}</button></section>`}${this.pagination(rows.pagination, 'table', {database, table, filterColumn: this.params.filterColumn || '', operator: this.params.operator || '', value: this.params.value || ''})}`;
    $('#rows-all')?.addEventListener('change', event => { $$('.row-check').forEach(box => { box.checked = event.target.checked; this.toggleRowSelection(Number(box.dataset.index), box.checked); }); });
  }

  async pageSql() {
    if (!this.requireHost() || !this.requireCapability('mysql', 'sqlConsole')) return;
    const database = this.params.database || '';
    if (!database) { this.navigate('databases', {}, true); return; }
    this.loading('sqlConsole');
    const [history, saved] = await Promise.all([this.api.request(this.hostPath('/sql/history')), this.api.request(this.hostPath('/sql/saved'))]);
    this.sqlHistory = history.history || [];
    this.savedQueries = saved.queries || [];
    this.content.innerHTML = `${this.pageHead('sqlConsole', database)}<div class="notice danger beginner-copy"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningSql'))}</div><div class="toolbar"><button class="button" data-action="sql-execute">▶ ${escapeHtml(t('execute'))}</button><button class="button secondary" data-action="sql-explain">${escapeHtml(t('explain'))}</button><button class="button secondary" data-action="sql-format">${escapeHtml(t('formatSql'))}</button><button class="button secondary" data-action="sql-save-query">${escapeHtml(t('saveQuery'))}</button><button class="button secondary" data-action="sql-clear">${escapeHtml(t('clear'))}</button></div><label class="check"><input id="sql-backup" type="checkbox">${escapeHtml(t('backupFirst'))}</label><label class="check"><input id="sql-history" type="checkbox" checked>${escapeHtml(t('saveHistory'))}</label><div class="editor-shell"><div id="sql-editor" style="height:42dvh;min-height:300px" aria-label="${escapeAttr(t('sqlConsole'))}"></div></div><section id="sql-result"></section><div class="cards"><article class="card"><h2>${escapeHtml(t('history'))}</h2><p class="muted">${escapeHtml(t('historyPrivacy'))}</p><div class="list">${this.sqlHistory.map(item => `<div class="list-row"><span class="badge ${item.result === 'success' ? 'ok' : 'error'}">${escapeHtml(item.query_type)}</span><span class="grow truncate" dir="ltr">${escapeHtml(item.query_hash)}</span><small>${escapeHtml(dateText(item.created_at))}</small></div>`).join('') || `<p class="muted">${escapeHtml(t('noRowsTitle'))}</p>`}</div></article><article class="card"><h2>${escapeHtml(t('savedQueries'))}</h2><div class="list">${this.savedQueries.map((item, index) => `<div class="list-row"><button class="grow link-button" data-action="sql-load-saved" data-index="${index}"><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.database_name)}</small></button><button class="icon-button" data-action="sql-delete-saved" data-id="${Number(item.id)}">×</button></div>`).join('') || `<p class="muted">${escapeHtml(t('noRowsTitle'))}</p>`}</div></article></div>`;
    await this.ensureAce();
    this.sqlEditor = window.ace.edit('sql-editor');
    window.ace.config.set('basePath', '/miniapp/vendor/ace');
    this.sqlEditor.setTheme(tg?.colorScheme === 'light' ? 'ace/theme/chrome' : 'ace/theme/tomorrow_night_eighties');
    this.sqlEditor.session.setMode('ace/mode/sql'); this.sqlEditor.session.setUseWorker(false); this.sqlEditor.session.setUseWrapMode(true); this.sqlEditor.setOptions({fontSize: '14px', showPrintMargin: false, tabSize: 2});
  }

  renderSqlResult(result, sql = '', allowPagination = true) {
    const target = $('#sql-result');
    if (!target) return;
    if (sql) this.sqlResultQuery = sql;
    const columns = result.columns || Object.keys(result.rows?.[0] || {});
    const pagination = result.pagination || {};
    const page = Number(pagination.page || 1);
    const pager = allowPagination && result.analysis?.read_only ? `<nav class="pagination"><button class="button secondary" data-action="sql-page" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>${escapeHtml(t('previous'))}</button><span>${escapeHtml(t('page'))} ${page}</span><button class="button secondary" data-action="sql-page" data-page="${page + 1}" ${!pagination.has_more ? 'disabled' : ''}>${escapeHtml(t('next'))}</button></nav>` : '';
    target.innerHTML = `<article class="card"><div class="toolbar"><span class="badge ok">${escapeHtml(t('affectedRows'))}: ${Number(result.affected_rows || 0)}</span><span class="badge">${escapeHtml(t('duration'))}: ${Number(result.execution_ms || 0)} ms</span>${result.truncated ? `<span class="badge warning">${escapeHtml(t('truncated'))}</span>` : ''}</div>${result.rows?.length ? `<div class="table-wrap"><table><thead><tr>${columns.map(column => `<th>${escapeHtml(column)}</th>`).join('')}</tr></thead><tbody>${result.rows.map(row => `<tr>${columns.map(column => `<td>${row[column] === null ? '<em>NULL</em>' : escapeHtml(String(row[column]))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : `<pre class="code">${escapeHtml(formatJson(result))}</pre>`}${pager}</article>`;
    target.scrollIntoView({behavior: 'smooth', block: 'start'});
  }

  async pageDomains() {
    if (!this.requireHost() || !this.requireCapability('domains', 'domains')) return;
    this.loading('domains');
    const [result, redirectResult] = await Promise.all([this.api.request(this.hostPath('/domains')), this.api.request(this.hostPath('/redirects'))]);
    this.domainData = result;
    this.redirectData = redirectResult;
    const domains = this.namedObjects(result.domains, 'domain');
    const redirects = asList(redirectResult.redirects, ['redirects', 'items']);
    const domainRows = domains.map(item => {
      const domain = String(item.domain || item.name || item.servername || '');
      const type = String(item.domain_type || item.type || '').toLowerCase();
      const isSubdomain = type.includes('sub') || Boolean(item.parentdomain || item.rootdomain);
      const removeAction = isSubdomain ? 'subdomain-delete' : 'domain-delete';
      return `<article class="list-row"><span class="file-icon">🌐</span><div class="grow"><strong>${escapeHtml(domain)}</strong><small>${escapeHtml(item.documentroot || item.document_root || item.dir || '')}</small></div><div class="row-actions"><button class="button secondary small" data-action="domain-dns" data-domain="${escapeAttr(domain)}">${escapeHtml(t('dns'))}</button>${isSubdomain ? '' : `<button class="button secondary small" data-action="subdomain-create" data-domain="${escapeAttr(domain)}">＋ ${escapeHtml(t('subdomains'))}</button>`}<button class="button danger small" data-action="${removeAction}" data-domain="${escapeAttr(domain)}">${escapeHtml(t('remove'))}</button></div></article>`;
    }).join('');
    const redirectRows = redirects.map(item => { const domain = String(item.domain || item.host || ''), source = String(item.source || item.src || item.path || '/'), target = String(item.destination || item.redirect || item.url || ''); return `<article class="list-row"><div class="grow"><strong>${escapeHtml(domain + source)}</strong><small>→ ${escapeHtml(target)}</small></div><button class="button danger small" data-action="redirect-delete" data-domain="${escapeAttr(domain)}" data-source="${escapeAttr(source)}">${escapeHtml(t('remove'))}</button></article>`; }).join('');
    this.content.innerHTML = `${this.pageHead('domains', '', `<button class="button" data-action="domain-create">＋ ${escapeHtml(t('addDomain'))}</button>`)}<div class="toolbar"><button class="button secondary" data-action="redirect-create">＋ ${escapeHtml(t('redirects'))}</button></div><div class="cards"><section class="card"><h2>${escapeHtml(t('domains'))}</h2><div class="list">${domainRows || `<p class="muted">${escapeHtml(t('noRowsTitle'))}</p>`}</div></section><section class="card"><h2>${escapeHtml(t('redirects'))}</h2><div class="list">${redirectRows || `<p class="muted">${escapeHtml(t('noRowsTitle'))}</p>`}</div></section></div>`;
  }

  namedObjects(value, keyName = 'name') {
    if (Array.isArray(value)) return value;
    if (!value || typeof value !== 'object') return [];
    const candidate = value.domains || value.items || value.data || value;
    if (Array.isArray(candidate)) return candidate;
    if (candidate && typeof candidate === 'object') return Object.entries(candidate).filter(([, item]) => item && typeof item === 'object').map(([key, item]) => ({[keyName]: key, ...item}));
    return [];
  }

  async pageEmail() {
    if (!this.requireHost() || !this.requireCapability('email', 'email')) return;
    this.loading('email');
    const tab = this.params.tab || 'accounts';
    const [accountsResult, forwardResult, autoResult] = await Promise.all([this.api.request(this.hostPath('/email/accounts')), this.api.request(this.hostPath('/email/forwarders')), this.api.request(this.hostPath('/email/autoresponders'))]);
    this.emailAccounts = asList(accountsResult.accounts, ['accounts', 'items']);
    this.forwarders = asList(forwardResult.forwarders, ['forwarders', 'items']);
    this.autoresponders = asList(autoResult.autoresponders, ['autoresponders', 'items']);
    const tabs = [['accounts', 'mailAccounts'], ['forwarders', 'forwarders'], ['autoresponders', 'autoresponders']];
    let body = '';
    if (tab === 'accounts') body = this.emailAccounts.map(item => { const address = String(item.email || item.address || `${item.user || ''}@${item.domain || ''}`); return `<article class="list-row"><span class="file-icon">✉️</span><div class="grow"><strong>${escapeHtml(address)}</strong><small>${escapeHtml(t('quota'))}: ${escapeHtml(item.humandiskquota || item.quota || '—')} · ${escapeHtml(bytes(item.diskusedbytes || item.disk_used))}</small></div><div class="row-actions"><button class="button secondary small" data-action="email-password" data-email="${escapeAttr(address)}">${escapeHtml(t('password'))}</button><button class="button secondary small" data-action="email-quota" data-email="${escapeAttr(address)}">${escapeHtml(t('quota'))}</button><button class="button danger small" data-action="email-delete" data-email="${escapeAttr(address)}">${escapeHtml(t('remove'))}</button></div></article>`; }).join('');
    if (tab === 'forwarders') body = this.forwarders.map(item => { const source = String(item.dest || item.address || item.email || ''), target = String(item.forward || item.forwarder || item.destination || ''); return `<article class="list-row"><div class="grow"><strong>${escapeHtml(source)}</strong><small>→ ${escapeHtml(target)}</small></div><button class="button danger small" data-action="forwarder-delete" data-source="${escapeAttr(source)}" data-destination="${escapeAttr(target)}">${escapeHtml(t('remove'))}</button></article>`; }).join('');
    if (tab === 'autoresponders') body = this.autoresponders.map(item => { const address = String(item.email || item.address || ''); return `<article class="list-row"><div class="grow"><strong>${escapeHtml(address)}</strong><small>${escapeHtml(item.subject || '')}</small></div><button class="button secondary small" data-action="autoresponder-edit" data-index="${this.autoresponders.indexOf(item)}">${escapeHtml(t('edit'))}</button><button class="button danger small" data-action="autoresponder-delete" data-email="${escapeAttr(address)}">${escapeHtml(t('remove'))}</button></article>`; }).join('');
    const addAction = tab === 'accounts' ? 'email-create' : tab === 'forwarders' ? 'forwarder-create' : 'autoresponder-create';
    this.content.innerHTML = `${this.pageHead('email', '', `<button class="button" data-action="${addAction}">＋ ${escapeHtml(t('create'))}</button>`)}<div class="tabs">${tabs.map(([name, label]) => `<button class="${tab === name ? 'active' : ''}" data-route="email" data-tab="${name}">${escapeHtml(t(label))}</button>`).join('')}</div><div class="list">${body || `<section class="empty-state card"><h2>${escapeHtml(t(tabs.find(row => row[0] === tab)?.[1] || 'email'))}</h2><p>${escapeHtml(t('noRowsBody'))}</p><button class="button" data-action="${addAction}">${escapeHtml(t('create'))}</button></section>`}</div>`;
  }

  async pageSsl() {
    if (!this.requireHost() || !this.requireCapability('ssl', 'ssl')) return;
    this.loading('ssl');
    const [status, eligibility] = await Promise.all([this.api.request(this.hostPath('/ssl')), this.api.request(this.hostPath('/ssl/autossl'))]);
    this.sslData = status;
    const items = asList(status.items, ['items', 'domains', 'certificates']);
    this.content.innerHTML = `${this.pageHead('ssl', '', `<button class="button" data-action="ssl-autossl">${escapeHtml(t('autoSsl'))}</button>`)}<div class="notice"><strong>${escapeHtml(t('info'))}</strong>${escapeHtml(t('autoSsl'))}: ${escapeHtml(formatJson(eligibility.autossl).slice(0, 300))}</div><div class="list">${items.map(item => { const domain = String(item.domain || item.fqdn || item.host || item.name || ''); return `<article class="list-row"><span class="file-icon">🔒</span><div class="grow"><strong>${escapeHtml(domain)}</strong><small>${escapeHtml(String(item.status || item.type || ''))} · ${escapeHtml(item.expiration || item.not_after || '')}</small></div><button class="button secondary small" data-action="ssl-inspect" data-domain="${escapeAttr(domain)}">${escapeHtml(t('inspectCertificate'))}</button></article>`; }).join('') || `<pre class="code">${escapeHtml(formatJson(status))}</pre>`}</div>`;
  }

  async pageCron() {
    if (!this.requireHost() || !this.requireCapability('cron', 'cronJobs')) return;
    this.loading('cronJobs');
    const result = await this.api.request(this.hostPath('/cron'));
    this.cronJobs = asList(result.jobs, ['jobs', 'items']);
    const rows = this.cronJobs.map((item, index) => { const expression = [item.minute, item.hour, item.day, item.month, item.weekday].join(' '), lineKey = String(item.linekey || ''), lineNumber = Number(item.count ?? item.line ?? index + 1), disabled = String(item.command || '').startsWith('#TCM_DISABLED# '); return `<article class="card"><div class="page-head"><div><h3 class="code">${escapeHtml(expression)}</h3><span class="badge ${disabled ? 'warning' : 'ok'}">${escapeHtml(disabled ? t('disabled') : t('enabled'))}</span></div></div><pre class="code">${escapeHtml(String(item.command || ''))}</pre><div class="row-actions"><button class="button secondary small" data-action="cron-edit" data-index="${index}" data-line="${escapeAttr(lineKey)}">${escapeHtml(t('edit'))}</button><button class="button secondary small" data-action="cron-toggle" data-index="${index}" data-line="${escapeAttr(lineKey)}" data-enabled="${disabled ? 1 : 0}">${escapeHtml(disabled ? t('enabled') : t('disabled'))}</button><button class="button danger small" data-action="cron-delete" data-index="${index}" data-line="${lineNumber}">${escapeHtml(t('remove'))}</button></div></article>`; }).join('');
    this.content.innerHTML = `${this.pageHead('cronJobs', '', `<button class="button" data-action="cron-create">＋ ${escapeHtml(t('create'))}</button>`)}<div class="notice warning beginner-copy"><strong>${escapeHtml(t('warning'))}</strong>${escapeHtml(t('warningCron'))}</div>${rows ? `<div class="cards">${rows}</div>` : `<section class="empty-state card"><h2>${escapeHtml(t('cronJobs'))}</h2><p>${escapeHtml(t('warningCron'))}</p><button class="button" data-action="cron-create">${escapeHtml(t('create'))}</button></section>`}`;
  }

  async pageBackups() {
    if (!this.requireHost()) return;
    this.loading('backups');
    const result = await this.api.request(this.hostPath('/backups'));
    const managed = Array.isArray(result.managed) ? result.managed : [];
    const fallback = {
      file_backups: managed.filter(item => ['file', 'directory'].includes(item.type)),
      database_backups: managed.filter(item => item.type === 'database'),
      deployment_backups: managed.filter(item => item.type === 'deployment'),
      full_backups: managed.filter(item => item.type === 'full'),
    };
    this.backupGroups = result.groups && typeof result.groups === 'object' ? result.groups : fallback;
    this.backupItems = Object.values(this.backupGroups).flat().filter((item, index, items) => items.findIndex(candidate => candidate.key === item.key) === index);
    const section = (titleKey, groupKey, emptyKey) => {
      const items = Array.isArray(this.backupGroups[groupKey]) ? this.backupGroups[groupKey] : [];
      return `<section class="backup-section" aria-labelledby="backup-${escapeAttr(groupKey)}"><div class="section-heading"><h2 id="backup-${escapeAttr(groupKey)}">${escapeHtml(t(titleKey))}</h2><span class="badge">${items.length}</span></div>${items.length ? `<div class="cards backup-grid">${items.map(item => this.backupCard(item)).join('')}</div>` : `<div class="card compact-empty"><p>${escapeHtml(t(emptyKey))}</p></div>`}</section>`;
    };
    const providerUnavailable = result.provider?.available === false || !this.hasCapability('backup');
    const provider = providerUnavailable
      ? `<div class="notice warning"><strong>${escapeHtml(t('providerBackups'))}</strong>${escapeHtml(t('providerBackupUnavailable'))}</div>`
      : `<details class="advanced-only provider-backup-details"><summary>${escapeHtml(t('providerBackups'))}</summary><pre class="code">${escapeHtml(formatJson(result.provider || {}))}</pre></details>`;
    const buttons = `<button class="button secondary" data-action="backup-refresh">↻ ${escapeHtml(t('refresh'))}</button>`;
    this.content.innerHTML = `${this.pageHead('backups', '', buttons)}<div class="backup-actions"><button class="button" data-action="backup-full" data-capability="backup">${escapeHtml(t('fullBackup'))}</button><button class="button secondary" data-action="backup-database" data-capability="mysql">${escapeHtml(t('databaseBackup'))}</button><button class="button secondary" data-action="backup-directory" data-capability="files">${escapeHtml(t('directoryBackup'))}</button></div><div class="notice beginner-copy"><strong>${escapeHtml(t('info'))}</strong>${escapeHtml(t('noBackupsBody'))}</div>${provider}${section('fileBackups', 'file_backups', 'noFileBackups')}${section('databaseBackups', 'database_backups', 'noDatabaseBackups')}${section('deploymentBackups', 'deployment_backups', 'noDeploymentBackups')}${section('fullAccountBackups', 'full_backups', 'noFullBackups')}`;
    this.applyCapabilityPolicy(this.content);
  }

  backupTypeText(type) {
    return t({file: 'file', directory: 'directory', database: 'database', deployment: 'deploy', full: 'fullBackup'}[type] || 'backups');
  }

  backupItem(kind, id) {
    return this.backupItems.find(item => String(item.record_kind || 'managed') === String(kind || 'managed') && Number(item.record_id ?? item.id) === Number(id));
  }

  backupReasonText(reason) {
    const key = {editor_save: 'reasonEditorSave', before_version_restore: 'reasonBeforeVersionRestore', before_sql_import: 'reasonBeforeSqlImport', before_destructive_sql: 'reasonBeforeDestructiveSql'}[String(reason || '')];
    return key ? t(key) : String(reason || '');
  }

  backupCard(item) {
    const kind = String(item.record_kind || 'managed');
    const id = Number(item.record_id ?? item.id);
    const actions = item.actions && typeof item.actions === 'object' ? item.actions : {};
    const active = ['queued', 'processing', 'requested', 'running'].includes(String(item.status));
    const restoreCapability = {file: 'files', directory: 'files', database: 'mysql', deployment: 'files'}[actions.restore_kind] || '';
    const metadata = item.metadata && typeof item.metadata === 'object' ? item.metadata : {};
    const version = item.type === 'file' ? `<dt>${escapeHtml(t('backupVersion'))}</dt><dd>#${Number(metadata.version_number || 0)}</dd>` : '';
    const reason = metadata.reason ? `<dt>${escapeHtml(t('backupReason'))}</dt><dd>${escapeHtml(this.backupReasonText(metadata.reason))}</dd>` : '';
    const job = item.job_id ? `<dt>${escapeHtml(t('backupJob'))}</dt><dd>#${Number(item.job_id)}</dd>` : '';
    const error = item.status === 'failed' && metadata.error_code ? `<dt>${escapeHtml(t('errorCode'))}</dt><dd dir="ltr">${escapeHtml(String(metadata.error_code))}</dd>` : '';
    const progress = active && item.job_id ? `<button class="button secondary small" data-action="backup-progress" data-job="${Number(item.job_id)}">${escapeHtml(t('trackProgress'))}</button>` : '';
    const download = actions.download ? `<button class="button secondary small" data-action="backup-download" data-kind="${escapeAttr(kind)}" data-id="${id}">${escapeHtml(t('download'))}</button>` : '';
    const restore = actions.restore ? `<button class="button warning small" data-action="backup-restore" data-kind="${escapeAttr(kind)}" data-id="${id}" ${restoreCapability ? `data-capability="${escapeAttr(restoreCapability)}"` : ''}>${escapeHtml(t('restore'))}</button>` : '';
    const remove = actions.delete ? `<button class="button danger small" data-action="backup-delete" data-kind="${escapeAttr(kind)}" data-id="${id}">${escapeHtml(t('remove'))}</button>` : '';
    return `<article class="card backup-card ${active ? 'backup-active' : ''}" aria-busy="${active ? 'true' : 'false'}"><div class="page-head"><div><h3>${escapeHtml(this.backupTypeText(item.type))}</h3><span class="badge ${item.status === 'completed' ? 'ok' : item.status === 'failed' ? 'error' : 'warning'}">${escapeHtml(statusText(item.status))}</span></div><small>${kind === 'file_version' ? `v${Number(metadata.version_number || 0)}` : `#${id}`}</small></div><dl class="key-values"><dt>${escapeHtml(t('target'))}</dt><dd dir="ltr">${escapeHtml(item.target || '—')}</dd><dt>${escapeHtml(t('backupSize'))}</dt><dd>${escapeHtml(item.size_bytes === null || item.size_bytes === undefined ? '—' : bytes(item.size_bytes))}</dd><dt>${escapeHtml(t('createdAt'))}</dt><dd>${escapeHtml(dateText(item.completed_at || item.created_at))}</dd>${version}${reason}${job}${error}</dl><div class="row-actions">${progress}${download}${restore}${remove}</div></article>`;
  }

  async pageDeploy() {
    if (!this.requireHost() || !this.requireCapability('files', 'deployCenter')) return;
    this.loading('deployCenter');
    const result = await this.api.request(this.hostPath('/deployments'));
    this.deploymentOverview = result;
    this.deployments = Array.isArray(result.deployments) ? result.deployments : [];
    const current = Array.isArray(result.current_versions) ? result.current_versions : this.deployments.filter(item => item.is_current);
    const rollbackPoints = Array.isArray(result.rollback_points) ? result.rollback_points : this.deployments.filter(item => item.rollback_available);
    const active = Array.isArray(result.active_deployments) ? result.active_deployments : this.deployments.filter(item => item.is_active);
    const attention = Array.isArray(result.attention_required) ? result.attention_required : this.deployments.filter(item => item.requires_attention);
    const section = (key, items, emptyKey, variant) => `<section class="deploy-section" aria-labelledby="deploy-${escapeAttr(key)}"><div class="section-heading"><h2 id="deploy-${escapeAttr(key)}">${escapeHtml(t(key))}</h2><span class="badge">${items.length}</span></div>${items.length ? `<div class="cards deploy-grid">${items.map(item => this.deploymentCard(item, variant)).join('')}</div>` : `<div class="card compact-empty"><p>${escapeHtml(t(emptyKey))}</p></div>`}</section>`;
    const activeSection = active.length ? section('activeDeployments', active, 'noDeployments', 'active') : '';
    const attentionSection = attention.length ? `<section class="deploy-section" aria-labelledby="deploy-attention"><div class="notice danger"><strong id="deploy-attention">${escapeHtml(t('needsAttention'))} · ${attention.length}</strong>${escapeHtml(t('deploymentAttention'))}</div><div class="cards deploy-grid">${attention.map(item => this.deploymentCard(item, 'attention')).join('')}</div></section>` : '';
    const recent = this.deployments.length ? `<section class="deploy-section" aria-labelledby="deploy-recent"><div class="section-heading"><h2 id="deploy-recent">${escapeHtml(t('recentDeployments'))}</h2><span class="badge">${this.deployments.length}</span></div><div class="cards deploy-grid">${this.deployments.map(item => this.deploymentCard(item, 'recent')).join('')}</div></section>` : `<section class="empty-state card"><h2>${escapeHtml(t('noDeployments'))}</h2><p>${escapeHtml(t('warningDeploy'))}</p><button class="button" data-action="deployment-package">${escapeHtml(t('startDeploy'))}</button></section>`;
    this.content.innerHTML = `${this.pageHead('deployCenter', '', `<button class="button" data-action="deployment-package">＋ ${escapeHtml(t('startDeploy'))}</button>`)}<div class="notice danger beginner-copy"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningDeploy'))}</div>${attentionSection}${activeSection}${section('currentVersions', current, 'noCurrentVersion', 'current')}${section('rollbackPoints', rollbackPoints, 'noRollbackPoints', 'rollback')}${recent}`;
  }

  deploymentBadgeClass(status) {
    if (status === 'completed') return 'ok';
    if (['failed', 'rollback_failed', 'reconciliation_required'].includes(status)) return 'error';
    return 'warning';
  }

  rollbackKindText(kind) {
    return t({directory: 'rollbackDirectory', archive: 'rollbackArchive', remove_destination: 'rollbackRemoveDestination'}[kind] || 'rollbackPoints');
  }

  deploymentCard(item, variant = 'recent') {
    const when = item.rolled_back_at || item.completed_at || item.started_at || item.created_at;
    const health = item.health_status !== null && item.health_status !== undefined ? `HTTP ${Number(item.health_status)}` : (item.health_check_url ? '—' : t('stepSkipped'));
    const rollback = item.rollback_available ? `<button class="button warning small" data-action="deployment-rollback" data-id="${Number(item.id)}">${escapeHtml(t('rollback'))}</button>` : '';
    const context = variant === 'current' ? `<span class="badge ok">${escapeHtml(t('currentRelease'))}</span>` : (variant === 'rollback' ? `<span class="badge">${escapeHtml(this.rollbackKindText(item.rollback_kind))}</span>` : '');
    return `<article class="card release-card ${item.requires_attention ? 'requires-attention' : ''}"><div class="page-head"><div><h3>${escapeHtml(item.package_name || `${t('deploy')} #${Number(item.id)}`)}</h3><div class="badge-row"><span class="badge ${this.deploymentBadgeClass(item.status)}">${escapeHtml(statusText(item.status))}</span>${context}</div></div><small>#${Number(item.id)}</small></div><dl class="key-values"><dt>${escapeHtml(t('destination'))}</dt><dd dir="ltr">${escapeHtml(item.destination)}</dd><dt>${escapeHtml(t('healthStatus'))}</dt><dd>${escapeHtml(health)}</dd><dt>${escapeHtml(t('lastCheck'))}</dt><dd>${escapeHtml(dateText(when))}</dd>${variant === 'rollback' ? `<dt>${escapeHtml(t('rollbackPoints'))}</dt><dd>${escapeHtml(this.rollbackKindText(item.rollback_kind))}</dd>` : ''}</dl><div class="row-actions"><button class="button secondary small" data-action="deployment-details" data-id="${Number(item.id)}">${escapeHtml(t('timeline'))}</button>${rollback}</div></article>`;
  }

  async pageUsage() {
    if (!this.requireHost() || !this.requireCapability('usage', 'usage')) return;
    this.loading('usage');
    const result = await this.api.request(this.hostPath('/usage'));
    const stats = asList(result.stats, ['items', 'stats']);
    const cards = stats.map(item => `<article class="card stat"><strong>${escapeHtml(String(item.value ?? item.count ?? '—'))}</strong><span>${escapeHtml(item.name || item.id || item.display || '')}${item.maximum !== undefined ? ` / ${escapeHtml(String(item.maximum))}` : ''}</span></article>`).join('');
    this.content.innerHTML = `${this.pageHead('usage', result.account?.domain || '')}${cards ? `<div class="cards">${cards}</div>` : ''}<div class="cards"><article class="card"><h2>${escapeHtml(t('bandwidth'))}</h2><pre class="code">${escapeHtml(formatJson(result.bandwidth))}</pre></article><article class="card"><h2>${escapeHtml(t('server'))}</h2><pre class="code">${escapeHtml(formatJson(result.server))}</pre></article><article class="card"><h2>${escapeHtml(t('resources'))}</h2><pre class="code">${escapeHtml(formatJson(result.resources))}</pre></article></div>`;
  }

  async pageLogs() {
    if (!this.requireHost() || !this.requireCapability('files', 'logs')) return;
    this.loading('logs');
    const result = await this.api.request(this.hostPath('/logs'));
    this.logItems = result.logs || [];
    this.content.innerHTML = `${this.pageHead('logs')}<div class="list">${this.logItems.map(item => `<article class="list-row"><span class="file-icon">📜</span><div class="grow"><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.path)}</small></div><button class="button small" data-action="log-open" data-path="${escapeAttr(item.path)}">${escapeHtml(t('open'))}</button></article>`).join('') || `<section class="empty-state card"><h2>${escapeHtml(t('logs'))}</h2><p>${escapeHtml(t('noFilesBody'))}</p><button class="button" data-action="log-custom">${escapeHtml(t('open'))}</button></section>`}</div><div class="toolbar"><button class="button secondary" data-action="log-custom">${escapeHtml(t('path'))}</button></div><section id="log-output"></section>`;
  }

  async pagePhp() {
    if (!this.requireHost() || !this.requireCapability('php', 'php')) return;
    this.loading('php');
    const result = await this.api.request(this.hostPath('/php'));
    this.phpData = result;
    this.content.innerHTML = `${this.pageHead('php')}<div class="toolbar"><button class="button" data-action="php-version">${escapeHtml(t('phpVersion'))}</button><button class="button secondary" data-action="php-ini">${escapeHtml(t('iniDirectives'))}</button></div><div class="cards"><article class="card"><h2>${escapeHtml(t('vhosts'))}</h2><pre class="code">${escapeHtml(formatJson(result.vhosts))}</pre></article><article class="card"><h2>${escapeHtml(t('phpVersion'))}</h2><pre class="code">${escapeHtml(formatJson(result.installed_versions))}</pre></article><article class="card"><h2>${escapeHtml(t('iniDirectives'))}</h2><pre class="code">${escapeHtml(formatJson(result.ini))}</pre></article></div>`;
  }

  async pageSecurity() {
    this.loading('securityCenter');
    const result = await this.api.request('/api/v1/security');
    this.securityData = result;
    const summary = result.summary || {};
    const stats = [
      ['connectedHosts', summary.connected_hosts], ['failedTokens', summary.failed_tokens], ['suspiciousRequests', summary.suspicious_requests],
      ['recentDestructiveActions', summary.recent_destructive_actions], ['activeSessions', summary.active_sessions], ['securityAlerts', summary.security_alerts]
    ].map(([label, value]) => `<article class="card stat"><strong>${Number(value || 0)}</strong><span>${escapeHtml(t(label))}</span></article>`).join('');
    const hosts = (result.hosts || []).map(host => `<article class="list-row"><span class="file-icon">🖥</span><div class="grow"><strong>${escapeHtml(host.label || host.hostname)}</strong><small>${escapeHtml(host.status)}${host.last_error_code ? ` · ${escapeHtml(host.last_error_code)}` : ''}</small></div><button class="button secondary small" data-action="host-token" data-id="${Number(host.id)}">${escapeHtml(t('rotateToken'))}</button><button class="button danger small" data-action="host-remove" data-id="${Number(host.id)}">${escapeHtml(t('remove'))}</button></article>`).join('');
    const sessions = (result.sessions || []).map(session => `<article class="list-row"><div class="grow"><strong>${escapeHtml(t('session'))} #${Number(session.id)}</strong><small>${escapeHtml(dateText(session.last_used_at))} · ${escapeHtml(dateText(session.expires_at))}</small></div><button class="button danger small" data-action="session-revoke" data-id="${Number(session.id)}">${escapeHtml(t('terminateSession'))}</button></article>`).join('');
    const alerts = (result.alerts || []).map(alert => `<article class="list-row"><span class="badge ${alert.severity === 'danger' ? 'error' : 'warning'}">${escapeHtml(alert.severity)}</span><div class="grow"><strong>${escapeHtml(alert.event_type)}</strong><small>${escapeHtml(dateText(alert.created_at))}</small></div>${alert.acknowledged_at ? '' : `<button class="button secondary small" data-action="security-ack" data-id="${Number(alert.id)}">${escapeHtml(t('markRead'))}</button>`}</article>`).join('');
    const destructive = (result.destructive_actions || []).map(event => `<article class="list-row"><div class="grow"><strong>${escapeHtml(event.action)}</strong><small>${escapeHtml(event.target_ref || '')} · ${escapeHtml(dateText(event.created_at))}</small></div><span class="badge ${event.result === 'success' ? 'ok' : 'error'}">${escapeHtml(event.result)}</span></article>`).join('');
    this.content.innerHTML = `${this.pageHead('securityCenter')}<div class="notice danger"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningToken'))}</div><div class="cards">${stats}</div><div class="cards"><section class="card"><h2>${escapeHtml(t('connectedHosts'))}</h2><div class="list">${hosts || `<p class="muted">${escapeHtml(t('noHostTitle'))}</p>`}</div></section><section class="card"><h2>${escapeHtml(t('activeSessions'))}</h2><div class="list">${sessions || `<p class="muted">${escapeHtml(t('noRowsTitle'))}</p>`}</div></section><section class="card"><h2>${escapeHtml(t('alerts'))}</h2><div class="list">${alerts || `<p class="muted">${escapeHtml(t('noRowsTitle'))}</p>`}</div></section><section class="card"><h2>${escapeHtml(t('destructive'))}</h2><div class="list">${destructive || `<p class="muted">${escapeHtml(t('noRowsTitle'))}</p>`}</div></section></div>`;
  }

  async pageSettings() {
    this.setTitle('settings');
    this.content.innerHTML = `${this.pageHead('settings')}<div class="cards"><section class="card"><h2>${escapeHtml(t('language'))}</h2><div class="segmented"><button class="${language() === 'fa' ? 'active' : ''}" data-action="settings-language" data-language="fa">🇮🇷 فارسی</button><button class="${language() === 'en' ? 'active' : ''}" data-action="settings-language" data-language="en">🇬🇧 English</button></div></section><section class="card"><h2>${escapeHtml(t('experience'))}</h2><p class="muted">${escapeHtml(t('beginner'))} / ${escapeHtml(t('advanced'))}</p><div class="segmented"><button class="${this.user.ux_mode === 'beginner' ? 'active' : ''}" data-action="settings-mode" data-mode="beginner">${escapeHtml(t('beginner'))}</button><button class="${this.user.ux_mode === 'advanced' ? 'active' : ''}" data-action="settings-mode" data-mode="advanced">${escapeHtml(t('advanced'))}</button></div></section><section class="card"><h2>${escapeHtml(t('security'))}</h2><p>${escapeHtml(t('warningToken'))}</p><button class="button danger" data-action="logout">${escapeHtml(t('logout'))}</button></section></div>`;
  }

  async pageHelp() {
    this.loading('help');
    const slug = this.params.slug || '';
    if (slug) {
      const result = await this.api.request(`/api/v1/help/${encodeURIComponent(slug)}`);
      const topic = result.topic;
      this.content.innerHTML = `${this.pageHead('help', t(topic.category))}<article class="card help-topic"><span class="badge ${topic.warning_level === 'danger' ? 'error' : topic.warning_level === 'warning' ? 'warning' : ''}">${escapeHtml(this.warningBadgeText(topic.warning_level))}</span><h2>${escapeHtml(topic.title)}</h2><div class="help-body">${this.helpMarkup(topic.body)}</div>${topic.related?.length ? `<h3>${escapeHtml(t('related'))}</h3><div class="toolbar">${topic.related.map(related => `<button class="button secondary small" data-route="help" data-slug="${escapeAttr(related.slug)}">${escapeHtml(related.title)}</button>`).join('')}</div>` : ''}</article><button class="button secondary" data-route="help">${escapeHtml(t('helpSearch'))}</button>`;
      return;
    }
    const q = this.params.q || '';
    const result = await this.api.request(query('/api/v1/help', {q, limit: 50}), {signal: this.abortController.signal});
    const topics = result.topics || [];
    this.content.innerHTML = `${this.pageHead('help')}<form id="help-form" class="toolbar"><input class="input grow" name="q" value="${escapeAttr(q)}" aria-label="${escapeAttr(t('helpSearch'))}"><button class="button">${escapeHtml(t('search'))}</button></form><div class="list">${topics.map(topic => `<button class="list-row" data-route="help" data-slug="${escapeAttr(topic.slug)}"><span class="badge ${topic.warning_level === 'danger' ? 'error' : topic.warning_level === 'warning' ? 'warning' : ''}">${escapeHtml(this.warningBadgeText(topic.warning_level))}</span><div class="grow"><strong>${escapeHtml(topic.title)}</strong><small>${escapeHtml(t(topic.category))}</small></div><span>›</span></button>`).join('') || `<section class="empty-state card"><h2>${escapeHtml(t('noHelp'))}</h2></section>`}</div>`;
    $('#help-form').addEventListener('submit', event => { event.preventDefault(); this.navigate('help', {q: new FormData(event.target).get('q') || ''}); });
  }

  helpMarkup(body) {
    return escapeHtml(body).split(/\n{2,}/).map(block => `<p>${block.replaceAll('\n', '<br>')}</p>`).join('');
  }

  warningBadgeText(level) { return `${level === 'danger' ? '🔴' : level === 'warning' ? '🟠' : '🔵'} ${t(level)}`; }

  async pageAdmin() {
    if (!this.user?.is_super_admin) { throw new ApiError({code: 'admin_required', message: t('adminRequired')}, 403); }
    const tab = this.params.tab || 'dashboard';
    this.loading('adminDashboard');
    const tabs = [['dashboard', 'dashboard'], ['users', 'users'], ['hosts', 'myHosts'], ['plans', 'plans'], ['broadcasts', 'broadcasts'], ['audit', 'auditLogs'], ['security', 'securityEvents'], ['settings', 'systemSettings'], ['maintenance', 'maintenance'], ['health', 'health'], ['queue', 'queue'], ['failed', 'failedJobs']];
    const endpoint = {dashboard: 'dashboard', users: 'users', hosts: 'hosts', plans: 'plans', broadcasts: 'broadcasts', audit: 'audit', security: 'security-events', settings: 'settings', maintenance: 'settings', health: 'health', queue: 'queue', failed: 'failed-jobs'}[tab] || 'dashboard';
    const result = await this.api.request(`/api/v1/admin/${endpoint}`);
    this.adminData = result;
    let body = '';
    if (tab === 'dashboard') {
      const totals = result.totals || {};
      const cards = [
        ['users', Number(totals.users || 0)], ['activeUsers', Number(totals.active_users || 0)], ['connectedHosts', Number(totals.hosts || 0)],
        ['apiRequests', Number(totals.api_requests_today || 0)], ['errors', Number(totals.errors_today || 0)], ['queue', Number(totals.queued || 0)],
        ['alerts', Number(totals.security_alerts || 0)], ['storageFree', totals.storage_free_bytes === null ? '—' : bytes(totals.storage_free_bytes)]
      ];
      body = `<div class="cards">${cards.map(([label, value]) => `<article class="card stat"><strong>${escapeHtml(String(value))}</strong><span>${escapeHtml(t(label))}</span></article>`).join('')}</div><article class="card"><h2>${escapeHtml(t('errors'))}</h2><pre class="code">${escapeHtml(formatJson(result.recent_errors || []))}</pre></article>`;
    } else if (tab === 'users') {
      body = `<div class="list">${(result.items || []).map(user => `<article class="list-row"><div class="grow"><strong>${escapeHtml(user.first_name || user.username || String(user.telegram_id))}</strong><small>#${Number(user.id)} · ${escapeHtml(user.status)} · ${escapeHtml(user.plan || '—')} · ${Number(user.hosts || 0)} ${escapeHtml(t('host'))}</small></div><div class="row-actions"><button class="button secondary small" data-action="admin-user-plan" data-id="${Number(user.id)}">${escapeHtml(t('assignPlan'))}</button><button class="button ${user.status === 'active' ? 'danger' : ''} small" data-action="admin-user-status" data-id="${Number(user.id)}" data-status="${user.status === 'active' ? 'banned' : 'active'}">${escapeHtml(user.status === 'active' ? t('ban') : t('activate'))}</button></div></article>`).join('')}</div>`;
    } else if (tab === 'hosts') {
      body = `<div class="list">${(result.hosts || []).map(host => `<article class="list-row"><div class="grow"><strong>${escapeHtml(host.label || host.hostname)}</strong><small>${escapeHtml(t('userLabel'))} #${Number(host.user_id)} · ${escapeHtml(host.cpanel_username)} · ${escapeHtml(statusText(host.status))}</small></div><span class="badge">${escapeHtml(host.token_mode)}</span></article>`).join('')}</div>`;
    } else if (tab === 'plans') {
      this.adminPlans = result.plans || [];
      body = `<div class="cards">${this.adminPlans.map((plan, index) => `<article class="card"><h2>${escapeHtml(plan[language() === 'fa' ? 'name_fa' : 'name_en'])}</h2><dl class="key-values"><dt>${escapeHtml(t('host'))}</dt><dd>${Number(plan.host_limit)}</dd><dt>${escapeHtml(t('upload'))}</dt><dd>${escapeHtml(bytes(plan.max_upload_bytes))}</dd><dt>SQL</dt><dd>${Number(plan.sql_console) ? '✓' : '—'}</dd><dt>${escapeHtml(t('backups'))}</dt><dd>${Number(plan.backup_enabled) ? '✓' : '—'}</dd><dt>${escapeHtml(t('deploy'))}</dt><dd>${Number(plan.deployment_enabled) ? '✓' : '—'}</dd></dl><button class="button secondary" data-action="admin-plan-edit" data-index="${index}">${escapeHtml(t('edit'))}</button></article>`).join('')}</div>`;
    } else if (tab === 'broadcasts') {
      body = `<button class="button" data-action="admin-broadcast">＋ ${escapeHtml(t('sendBroadcast'))}</button><div class="list">${(result.broadcasts || []).map(item => `<article class="list-row"><div class="grow"><strong>#${Number(item.id)} · ${escapeHtml(item.status)}</strong><small>${Number(item.sent_count)} ✓ · ${Number(item.failed_count)} ✕ · ${escapeHtml(dateText(item.created_at))}</small></div></article>`).join('')}</div>`;
    } else if (tab === 'audit') {
      body = this.adminLogRows(result.events || []);
    } else if (tab === 'security') {
      body = this.adminLogRows(result.events || []);
    } else if (tab === 'queue') {
      body = this.adminLogRows(result.jobs || []);
    } else if (tab === 'failed') {
      body = `<div class="list">${(result.jobs || []).map(job => `<article class="list-row"><div class="grow"><strong>#${Number(job.id)} · ${escapeHtml(job.job_type)}</strong><small>${escapeHtml(job.error_code)} · ${escapeHtml(job.error_message_safe)}</small></div><button class="button small" data-action="admin-job-retry" data-id="${Number(job.id)}">${escapeHtml(t('retryJob'))}</button></article>`).join('')}</div>`;
    } else if (tab === 'settings') {
      const settings = result.settings || {};
      body = `<form id="admin-settings-form" class="card"><div class="field"><label>${escapeHtml(t('workerMax'))}</label><input name="worker_max_jobs" type="number" min="1" max="100" value="${Number(settings.worker_max_jobs || 20)}"></div><div class="field"><label>${escapeHtml(t('notificationBatch'))}</label><input name="notification_batch" type="number" min="1" max="100" value="${Number(settings.notification_batch || 25)}"></div><div class="field"><label>${escapeHtml(t('auditRetention'))}</label><input name="audit_retention_days" type="number" min="30" max="3650" value="${Number(settings.audit_retention_days || 365)}"></div><div class="field"><label>${escapeHtml(t('logRetention'))}</label><input name="log_retention_days" type="number" min="7" max="365" value="${Number(settings.log_retention_days || 30)}"></div><button class="button">${escapeHtml(t('save'))}</button></form>`;
    } else if (tab === 'maintenance') {
      const maintenance = result.maintenance || {};
      body = `<form id="maintenance-form" class="card"><label class="check"><input name="enabled" type="checkbox" ${maintenance.enabled ? 'checked' : ''}>${escapeHtml(t('maintenance'))}</label><div class="field"><label>${escapeHtml(t('maintenanceFa'))}</label><textarea name="message_fa">${escapeHtml(maintenance.message_fa || '')}</textarea></div><div class="field"><label>${escapeHtml(t('maintenanceEn'))}</label><textarea name="message_en">${escapeHtml(maintenance.message_en || '')}</textarea></div><button class="button warning">${escapeHtml(t('save'))}</button></form>`;
    } else if (tab === 'health') {
      body = `<div class="cards"><article class="card"><h2>${escapeHtml(t('telegramWebhook'))}</h2><pre class="code">${escapeHtml(formatJson(result.telegram_webhook))}</pre></article><article class="card"><h2>${escapeHtml(t('database'))}</h2><pre class="code">${escapeHtml(formatJson(result.database))}</pre></article><article class="card"><h2>${escapeHtml(t('storage'))}</h2><pre class="code">${escapeHtml(formatJson({storage: result.storage_paths, disk_free_bytes: result.disk_free_bytes}))}</pre></article><article class="card"><h2>${escapeHtml(t('queue'))}</h2><pre class="code">${escapeHtml(formatJson(result.queue))}</pre></article><article class="card"><h2>${escapeHtml(t('phpEncryption'))}</h2><pre class="code">${escapeHtml(formatJson({php: result.php_version, extensions: result.required_extensions, encryption: result.encryption_round_trip}))}</pre></article><article class="card"><h2>${escapeHtml(t('cron'))}</h2><pre class="code">${escapeHtml(formatJson(result.cron?.last_run))}</pre><label class="field"><span>${escapeHtml(t('cronCommand'))}</span><textarea readonly>${escapeHtml(result.cron?.command || '')}</textarea></label></article></div>`;
    }
    this.content.innerHTML = `${this.pageHead('adminDashboard')}<div class="tabs">${tabs.map(([name, label]) => `<button data-route="admin" data-tab="${name}" class="${tab === name ? 'active' : ''}">${escapeHtml(t(label))}</button>`).join('')}</div>${body}`;
    $('#admin-settings-form')?.addEventListener('submit', event => { event.preventDefault(); this.saveAdminSettings(event.target).catch(error => this.operationError(error)); });
    $('#maintenance-form')?.addEventListener('submit', event => { event.preventDefault(); this.saveMaintenance(event.target).catch(error => this.operationError(error)); });
  }

  adminLogRows(rows) {
    this.adminRows = rows;
    return `<div class="list">${rows.map((row, index) => `<article class="list-row"><div class="grow"><strong>${escapeHtml(row.action || row.event_type || row.job_type || `#${row.id}`)}</strong><small>${escapeHtml(row.result || row.severity || row.status || '')} · ${escapeHtml(dateText(row.created_at || row.failed_at))}</small></div><button class="button secondary small" data-action="show-json" data-index="${index}" data-store="adminRows">${escapeHtml(t('details'))}</button></article>`).join('')}</div>`;
  }

  async handleAction(action, data, element = null) {
    const handlers = {
      navigate: () => this.navigate(data.target),
      'more-menu': () => this.moreMenu(),
      'notification-read': () => this.markNotification(Number(data.id)),
      'favorite-toggle': () => this.toggleFavorite(data),
      'favorite-open': () => this.openFavorite(Number(data.id)),
      'favorite-remove': () => this.removeFavorite(Number(data.id)),
      'add-host': () => this.addHostDialog(),
      'host-open': () => this.openHost(Number(data.id)),
      'host-edit': () => this.editHost(Number(data.id)),
      'host-test-token': () => this.healthHost(Number(data.id), true),
      'host-health': () => this.healthHost(Number(data.id)),
      'host-token': () => this.rotateHostToken(Number(data.id)),
      'host-remove': () => this.removeHost(Number(data.id)),
      'file-upload-picker': () => $('#file-input')?.click(),
      'file-path': () => this.navigate('files', {path: data.path}),
      'file-view': () => this.fileView(data.mode),
      'file-select': () => this.toggleFileSelection(data.path, Boolean(element?.checked)),
      'file-open': () => data.directory === '1' ? this.navigate('files', {path: data.path}) : data.text === '1' ? this.navigate('editor', {path: data.path}) : this.fileInfo(data.path),
      'file-create': () => this.createFile(), 'folder-create': () => this.createFolder(), 'file-search': () => this.searchFiles(), 'file-trash': () => this.openTrash(), 'trash-empty': () => this.emptyTrash(),
      'file-download': () => this.downloadRemote(data.path), 'file-move': () => this.moveFile(data.path), 'file-copy': () => this.copyFile(data.path), 'file-delete': () => this.deleteFile(data.path, data.directory === '1'), 'file-info': () => this.fileInfo(data.path), 'file-extract': () => this.extractFile(data.path), 'file-restore-trash': () => this.restoreTrash(data.path),
      'files-archive-selected': () => this.archiveSelected(), 'files-delete-selected': () => this.deleteSelectedFiles(), 'files-move-selected': () => this.moveSelectedFiles(),
      'editor-save': () => this.saveEditor(), 'editor-save-as': () => this.saveEditorAs(), 'editor-find': () => this.editor?.execCommand('find'), 'editor-replace': () => this.editor?.execCommand('replace'), 'editor-undo': () => this.editor?.undo(), 'editor-redo': () => this.editor?.redo(), 'editor-wrap': () => this.toggleEditorWrap(), 'editor-fullscreen': () => $('#editor-shell')?.classList.toggle('fullscreen'), 'editor-versions': () => this.editorVersions(), 'editor-load-more': () => this.loadEditorRemainder(), 'editor-restore-version': () => this.restoreEditorVersion(Number(data.id)),
      'database-create': () => this.createDatabase(), 'database-delete': () => this.deleteDatabase(data.database), 'database-connect': () => this.connectDatabase(data.database), 'database-export': () => this.exportDatabase(data.database), 'database-import': () => this.importDatabase(data.database), 'db-users': () => this.databaseUsersDialog(), 'db-user-create': () => this.createDatabaseUser(), 'db-user-password': () => this.databaseUserPassword(data.user), 'db-user-delete': () => this.deleteDatabaseUser(data.user), 'db-privileges': () => this.databasePrivilegesDialog(), 'db-remote': () => this.remoteMysqlDialog(), 'db-remote-add': () => this.addRemoteMysql(), 'db-remote-delete': () => this.deleteRemoteMysql(data.host),
      'table-create': () => this.createTable(data.database || this.params.database), 'table-export': () => this.exportDatabase(data.database, data.table), 'table-operation': () => this.tableOperation(data.database || this.params.database, data.table || this.params.table, data.operation), 'table-sort': () => this.tableSort(data.column), 'table-filter': () => this.tableFilter(), 'table-structure': () => this.tableStructureDialog(), 'column-visibility': () => this.columnVisibilityDialog(), 'column-add': () => this.addColumn(), 'column-edit': () => this.editColumn(Number(data.index)), 'column-delete': () => this.deleteColumn(data.column), 'index-add': () => this.addIndex(), 'index-delete': () => this.deleteIndex(data.indexName),
      'row-select': () => this.toggleRowSelection(Number(data.index), Boolean(element?.checked)), 'row-details': () => this.rowDetails(Number(data.index)), 'row-insert': () => this.insertRow(), 'row-edit': () => this.editRow(Number(data.index)), 'row-delete': () => this.deleteRow(Number(data.index)), 'row-bulk-delete': () => this.bulkDeleteRows(), 'row-export': () => this.exportSelectedRows(),
      'sql-execute': () => this.executeSql(), 'sql-page': () => this.executeSql(Number(data.page)), 'sql-explain': () => this.explainSql(), 'sql-format': () => this.formatSql(), 'sql-save-query': () => this.saveSqlQuery(), 'sql-clear': () => this.sqlEditor?.setValue('', -1), 'sql-load-saved': () => this.sqlEditor?.setValue(this.savedQueries[Number(data.index)]?.query || '', -1), 'sql-delete-saved': () => this.deleteSavedQuery(Number(data.id)),
      'domain-create': () => this.createDomain(), 'domain-delete': () => this.deleteDomain(data.domain), 'domain-dns': () => this.dnsDialog(data.domain), 'subdomain-create': () => this.createSubdomain(data.domain), 'subdomain-delete': () => this.deleteSubdomain(data.domain), 'redirect-create': () => this.createRedirect(), 'redirect-delete': () => this.deleteRedirect(data.domain, data.source),
      'email-create': () => this.createEmail(), 'email-password': () => this.changeEmailPassword(data.email), 'email-quota': () => this.changeEmailQuota(data.email), 'email-delete': () => this.deleteEmail(data.email), 'forwarder-create': () => this.createForwarder(), 'forwarder-delete': () => this.deleteForwarder(data.source, data.destination), 'autoresponder-create': () => this.autoresponderDialog(), 'autoresponder-edit': () => this.autoresponderDialog(this.autoresponders[Number(data.index)]), 'autoresponder-delete': () => this.deleteAutoresponder(data.email),
      'ssl-autossl': () => this.runAutoSsl(), 'ssl-inspect': () => this.inspectSsl(data.domain),
      'cron-create': () => this.cronDialog(), 'cron-edit': () => this.cronDialog(this.cronJobs[Number(data.index)], data.line), 'cron-toggle': () => this.toggleCron(this.cronJobs[Number(data.index)], data.line, data.enabled === '1'), 'cron-delete': () => this.deleteCron(this.cronJobs[Number(data.index)], Number(data.line)),
      'backup-refresh': () => this.pageBackups(), 'backup-progress': () => this.followBackupJob(Number(data.job)),
      'backup-full': () => this.fullBackup(), 'backup-database': () => this.databaseBackup(), 'backup-directory': () => this.directoryBackup(), 'backup-download': () => this.downloadBackup(data.kind, Number(data.id)), 'backup-restore': () => this.restoreBackup(data.kind, Number(data.id)), 'backup-delete': () => this.deleteBackup(data.kind, Number(data.id)),
      'deployment-package': () => this.deploymentPackage(), 'deployment-details': () => this.deploymentDetails(Number(data.id)), 'deployment-rollback': () => this.rollbackDeployment(Number(data.id)),
      'log-open': () => this.openLog(data.path), 'log-custom': () => this.customLog(), 'php-version': () => this.changePhpVersion(), 'php-ini': () => this.changePhpIni(),
      'session-revoke': () => this.revokeSession(Number(data.id)), 'security-ack': () => this.ackSecurity(Number(data.id)), 'settings-language': () => this.changeLanguage(data.language), 'settings-mode': () => this.changeMode(data.mode), 'logout': () => this.logout(),
      'admin-user-status': () => this.adminUserStatus(Number(data.id), data.status), 'admin-user-plan': () => this.adminUserPlan(Number(data.id)), 'admin-plan-edit': () => this.adminPlanEdit(Number(data.index)), 'admin-broadcast': () => this.adminBroadcast(), 'admin-job-retry': () => this.adminRetryJob(Number(data.id)), 'show-json': () => this.showJson(this[data.store]?.[Number(data.index)]), 'job-download': () => this.api.download(`/api/v1/jobs/${Number(data.id)}/download`, 'export.sql.gz')
    };
    if (!handlers[action]) throw new Error(`${t('unsupportedAction')}: ${action}`);
    this.assertWritableCapabilities(this.mutationCapabilities(action, data));
    await handlers[action]();
  }

  moreMenu() {
    const tools = [['domains', '🌐'], ['email', '✉️'], ['ssl', '🔒'], ['cron', '⏱'], ['backups', '💾'], ['deploy', '🚀'], ['usage', '📊'], ['logs', '📜'], ['php', 'PHP'], ['security', '🛡'], ['settings', '⚙️'], ['help', '❓']];
    if (this.user?.is_super_admin) tools.push(['admin', '🛠']);
    this.openInfo('more', `<div class="tool-grid">${tools.map(([route, icon]) => this.toolTile(route, icon)).join('')}</div>`);
  }

  openForm(titleKey, body, submitKey, submit, danger = false) {
    $('#dialog-title').textContent = t(titleKey);
    $('#dialog-body').innerHTML = body;
    $('#dialog-actions').innerHTML = `<button type="button" class="button secondary" data-dialog-close>${escapeHtml(t('cancel'))}</button><button type="submit" class="button ${danger ? 'danger' : ''}">${escapeHtml(t(submitKey))}</button>`;
    this.dialogSubmit = submit;
    this.applyCapabilityPolicy(this.dialog);
    if (!this.dialog.open) this.dialog.showModal();
    setTimeout(() => $('input:not([type=hidden]),textarea,select', this.dialog)?.focus(), 30);
  }

  openInfo(titleKey, body) {
    $('#dialog-title').textContent = t(titleKey);
    $('#dialog-body').innerHTML = body;
    $('#dialog-actions').innerHTML = `<button type="button" class="button" data-dialog-close>${escapeHtml(t('close'))}</button>`;
    this.applyCapabilityPolicy(this.dialog);
    this.dialogSubmit = null;
    if (!this.dialog.open) this.dialog.showModal();
  }

  closeDialog() { if (this.dialog.open) this.dialog.close(); this.dialogSubmit = null; }

  async confirmOperation({warningKey, action, target, summary, perform, preview = {}, details = '', closeOnSuccess = true}) {
    this.openForm('confirm', `<div class="notice danger"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t(warningKey))}</div>${details}<pre class="code">${escapeHtml(summary)}</pre>`, 'confirm', async () => {
      const confirmation = await this.api.request('/api/v1/confirmations', {method: 'POST', body: {account_id: this.hostId || null, action, target, preview}});
      const outcome = await perform(confirmation.nonce);
      if (closeOnSuccess) this.closeDialog();
      if (outcome !== false) { this.toast(t('success')); this.haptic('success'); }
    }, true);
  }

  confirmClient(warningKey, summary, perform) {
    this.openForm('confirm', `<div class="notice warning"><strong>${escapeHtml(t('warning'))}</strong>${escapeHtml(t(warningKey))}</div><pre class="code">${escapeHtml(summary)}</pre>`, 'confirm', async () => { await perform(); this.closeDialog(); this.toast(t('success')); this.haptic('success'); }, true);
  }

  async markNotification(id) { await this.api.request(`/api/v1/notifications/${id}/read`, {method: 'POST', body: {}}); await this.pageDashboard(); }

  async toggleFavorite(data) {
    const accountId = Number(data.account || 0) || null;
    const existing = this.favoriteFor(data.type, data.reference, accountId);
    if (existing) {
      await this.api.request(`/api/v1/favorites/${Number(existing.id)}`, {method: 'DELETE', body: {}});
    } else {
      await this.api.request('/api/v1/favorites', {method: 'POST', body: {account_id: accountId, type: data.type, reference: data.reference, label: data.label || null}});
    }
    await this.refreshDashboard(false);
    await this.render();
  }

  async removeFavorite(id) {
    await this.api.request(`/api/v1/favorites/${id}`, {method: 'DELETE', body: {}});
    await this.refreshDashboard(false);
    await this.render();
  }

  openFavorite(id) {
    const favorite = this.favorites().find(item => Number(item.id) === id);
    if (!favorite) return;
    if (favorite.account_id) {
      this.hostId = Number(favorite.account_id);
      localStorage.setItem('tcpm.host', String(this.hostId));
    }
    const reference = String(favorite.resource_ref || '');
    if (favorite.resource_type === 'host') { this.openHost(Number(favorite.account_id || reference)); return; }
    if (favorite.resource_type === 'directory') { this.navigate('files', {path: reference}); return; }
    if (favorite.resource_type === 'file') {
      if (/\.(?:php|html?|css|js|json|xml|txt|env|conf|ini|md|log|sql)$/i.test(reference) || /\/(?:\.env|\.htaccess)$/i.test(reference)) this.navigate('editor', {path: reference});
      else this.navigate('files', {path: reference.slice(0, reference.lastIndexOf('/')) || '.'});
      return;
    }
    if (favorite.resource_type === 'database') { this.navigate('database', {database: reference}); return; }
    if (favorite.resource_type === 'domain') { this.navigate('domains'); return; }
    if (favorite.resource_type === 'page' && this.validRoutes().includes(reference)) this.navigate(reference);
  }

  addHostDialog() {
    this.openForm('addHost', `<div class="notice danger"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningToken'))}</div><div class="field"><label>${escapeHtml(t('hostAddress'))}</label><input name="host" required inputmode="url" autocomplete="url" dir="ltr"></div><div class="field"><label>${escapeHtml(t('username'))}</label><input name="username" required autocomplete="username" dir="ltr"></div><div class="field"><label>${escapeHtml(t('token'))}</label><textarea name="token" required autocomplete="off" dir="ltr"></textarea></div><label class="check"><input name="store_token" type="checkbox" checked>${escapeHtml(t('storeToken'))}</label>`, 'addHost', async form => {
      const data = new FormData(form);
      await this.api.request('/api/v1/hosts', {method: 'POST', body: {host: data.get('host'), username: data.get('username'), token: data.get('token'), store_token: data.get('store_token') === 'on'}});
      form.elements.token.value = ''; this.closeDialog(); await this.refreshDashboard(); this.toast(t('success')); this.haptic('success');
    });
  }

  openHost(id) { this.hostId = id; localStorage.setItem('tcpm.host', String(id)); this.navigate('dashboard'); }
  editHost(id) { const host = this.hosts.find(item => Number(item.id) === id); this.openForm('edit', `<div class="field"><label>${escapeHtml(t('host'))}</label><input name="label" required maxlength="120" value="${escapeAttr(host?.label || host?.hostname || '')}"></div>`, 'save', async form => { await this.api.request(`/api/v1/hosts/${id}`, {method: 'PATCH', body: {label: form.elements.label.value}}); this.closeDialog(); await this.refreshDashboard(); }); }
  async healthHost(id, tokenOnly = false) { const result = await this.api.request(`/api/v1/hosts/${id}/health`, {method: 'POST', body: {}}); this.cache.delete(`host-summary:${id}`); await this.refreshDashboard(false); const visibleChecks = tokenOnly ? Object.fromEntries(Object.entries(result.checks || {}).filter(([name]) => ['cpanel', 'api'].includes(name))) : (result.checks || {}); const checks = Object.entries(visibleChecks).map(([name, value]) => `<article class="list-row"><span class="badge ${value?.ok ? 'ok' : 'error'}">${value?.ok ? '✓' : '✕'}</span><div class="grow"><strong>${escapeHtml(t(name))}</strong><small>${value?.latency_ms !== undefined ? `${Number(value.latency_ms)} ms` : escapeHtml(value?.ok ? t('available') : t('unavailable'))}</small></div></article>`).join(''); this.openInfo(tokenOnly ? 'testToken' : 'healthCheck', `${tokenOnly ? `<div class="notice"><strong>✓ ${escapeHtml(t('tokenTestPassed'))}</strong></div>` : ''}<div class="list">${checks}</div><details><summary>${escapeHtml(t('details'))}</summary><pre class="code">${escapeHtml(formatJson(result.capabilities || {}))}</pre></details>`); this.haptic('success'); }
  rotateHostToken(id) { this.openForm('rotateToken', `<div class="notice danger"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningToken'))}</div><div class="field"><label>${escapeHtml(t('token'))}</label><textarea name="token" required autocomplete="off" dir="ltr"></textarea></div><label class="check"><input type="checkbox" name="store_token" checked>${escapeHtml(t('storeToken'))}</label>`, 'save', async form => { await this.api.request(`/api/v1/hosts/${id}/token`, {method: 'PATCH', body: {token: form.elements.token.value, store_token: form.elements.store_token.checked}}); form.elements.token.value = ''; this.closeDialog(); await this.refreshDashboard(); }); }
  removeHost(id) { const host = this.hosts.find(item => Number(item.id) === id); return this.confirmOperation({warningKey: 'warningHostRemove', action: 'host.remove', target: String(id), summary: `${t('host')}: ${host?.hostname || id}`, preview: {hostname: host?.hostname || ''}, perform: async confirmation => { await this.api.request(`/api/v1/hosts/${id}`, {method: 'DELETE', body: {confirmation}}); if (this.hostId === id) this.hostId = 0; await this.refreshDashboard(false); await this.render(); }}); }

  fileView(mode) { localStorage.setItem('tcpm.fileMode', mode === 'grid' ? 'grid' : 'list'); const list = $('#file-list'); if (list) list.className = mode === 'grid' ? 'file-grid' : 'list'; $$('[data-action="file-view"]').forEach(button => button.classList.toggle('active', button.dataset.mode === mode)); }
  toggleFileSelection(path, checked) { if (checked) this.fileSelection.add(path); else this.fileSelection.delete(path); const bar = $('#file-selection-toolbar'); if (!bar) return; bar.hidden = this.fileSelection.size === 0; bar.innerHTML = `<strong>${escapeHtml(t('selected', {count: this.fileSelection.size}))}</strong><button class="button secondary small" data-action="files-archive-selected">${escapeHtml(t('archive'))}</button><button class="button secondary small" data-action="files-move-selected">${escapeHtml(t('move'))}</button><button class="button danger small" data-action="files-delete-selected">${escapeHtml(t('remove'))}</button>`; }
  createFile() { this.openForm('newFile', `<div class="field"><label>${escapeHtml(t('filename'))}</label><input name="name" required></div>`, 'create', async form => { const result = await this.api.request(this.hostPath('/files/new'), {method: 'POST', body: {directory: this.params.path || '.', name: form.elements.name.value}}); this.closeDialog(); this.navigate('editor', {path: result.path}); }); }
  createFolder() { this.openForm('newFolder', `<div class="field"><label>${escapeHtml(t('folderName'))}</label><input name="name" required></div><div class="field"><label>${escapeHtml(t('permissions'))}</label><input name="permissions" value="0755" pattern="0[0-7]{3}" required dir="ltr"></div>`, 'create', async form => { await this.api.request(this.hostPath('/folders'), {method: 'POST', body: {directory: this.params.path || '.', name: form.elements.name.value, permissions: form.elements.permissions.value}}); this.closeDialog(); await this.render(); }); }
  searchFiles() { this.openForm('search', `<div class="field"><label>${escapeHtml(t('search'))}</label><input name="q" required maxlength="100"></div><div class="field"><label>${escapeHtml(t('type'))}</label><select name="type"><option value="all">${escapeHtml(t('all'))}</option><option value="file">${escapeHtml(t('file'))}</option><option value="dir">${escapeHtml(t('directory'))}</option></select></div><section id="file-search-results"></section>`, 'search', async form => { const result = await this.api.request(query(this.hostPath('/files/search'), {directory: this.params.path || '.', q: form.elements.q.value, type: form.elements.type.value})); $('#file-search-results').innerHTML = `<div class="list">${(result.items || []).map(item => this.fileRow({...item, path: item.path || item.fullpath})).join('') || escapeHtml(t('noFilesTitle'))}</div>`; }); }
  openTrash() { const host = this.activeHost(), root = host?.root_path || `/home/${host?.cpanel_username || ''}`; this.navigate('files', {path: `${String(root).replace(/\/$/, '')}/.trash`}); }
  emptyTrash() { return this.confirmOperation({warningKey: 'warningTrash', action: 'trash.empty', target: 'trash', summary: t('emptyTrash'), perform: async confirmation => { await this.api.request(this.hostPath('/trash'), {method: 'DELETE', body: {confirmation}}); await this.render(); }}); }
  async downloadRemote(path) { const result = await this.api.request(this.hostPath('/download-links'), {method: 'POST', body: {path}}); await this.downloadWhenReady(result); }
  async downloadWhenReady(result) { if (!result?.token || !Number(result?.job_id)) throw new ApiError({code: 'download_preparation_invalid', message: t('failed')}, 422); this.openInfo('download', `<div id="job-progress">${this.progressHtml(0, t('queued'))}</div>`); await this.pollJob(Number(result.job_id), false); const anchor = document.createElement('a'); anchor.href = `/download/${encodeURIComponent(result.token)}`; anchor.rel = 'noopener'; anchor.download = result.filename || ''; document.body.appendChild(anchor); anchor.click(); anchor.remove(); this.closeDialog(); }
  moveFile(path) { this.openForm('move', `<div class="field"><label>${escapeHtml(t('source'))}</label><input value="${escapeAttr(path)}" readonly></div><div class="field"><label>${escapeHtml(t('destination'))}</label><input name="destination" value="${escapeAttr(path)}" required dir="ltr"></div>`, 'move', async form => { await this.api.request(this.hostPath('/files/move'), {method: 'POST', body: {source: path, destination: form.elements.destination.value}}); this.closeDialog(); await this.render(); }); }
  copyFile(path) { this.openForm('copy', `<div class="field"><label>${escapeHtml(t('source'))}</label><input value="${escapeAttr(path)}" readonly></div><div class="field"><label>${escapeHtml(t('destination'))}</label><input name="destination" value="${escapeAttr(path)}" required dir="ltr"></div>`, 'copy', async form => { await this.api.request(this.hostPath('/files/copy'), {method: 'POST', body: {source: path, destination: form.elements.destination.value}}); this.closeDialog(); await this.render(); }); }
  deleteFile(path, directory) { this.openForm('remove', `<div class="notice ${directory ? 'danger' : 'warning'}"><strong>${escapeHtml(t(directory ? 'danger' : 'warning'))}</strong>${escapeHtml(t('warningFileDelete'))}</div><pre class="code">${escapeHtml(path)}</pre><label class="check"><input name="permanent" type="checkbox">${escapeHtml(t('permanentDelete'))}</label>`, 'remove', async form => { const permanent = form.elements.permanent.checked; let confirmation = null; if (permanent || directory) { const issued = await this.api.request('/api/v1/confirmations', {method: 'POST', body: {account_id: this.hostId, action: permanent ? 'file.delete_permanent' : 'file.delete_recursive', target: path, preview: {path, permanent, directory}}}); confirmation = issued.nonce; } await this.api.request(this.hostPath('/files'), {method: 'DELETE', body: {path, permanent, confirmation}}); this.closeDialog(); await this.render(); }, true); }
  async fileInfo(path) { const info = await this.api.request(query(this.hostPath('/files/info'), {path})); this.openInfo('fileInfo', `<pre class="code">${escapeHtml(formatJson(info))}</pre>`); }
  extractFile(path) {
    this.openForm('extract', `<div class="notice warning"><strong>${escapeHtml(t('warning'))}</strong>${escapeHtml(t('archiveQueueHint'))}</div><div class="field"><label>${escapeHtml(t('destination'))}</label><input name="destination" value="${escapeAttr(this.params.path || '.')}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('archivePolicy'))}</label><select name="collision"><option value="reject">${escapeHtml(t('rejectExisting'))}</option><option value="overwrite">${escapeHtml(t('overwriteExisting'))}</option></select></div><div class="notice danger" id="archive-overwrite-warning" hidden>${escapeHtml(t('warningArchiveOverwrite'))}</div><div id="job-progress"></div>`, 'extract', async form => {
      const destination = form.elements.destination.value;
      const collision = form.elements.collision.value;
      let confirmation = null;
      if (collision === 'overwrite') {
        const issued = await this.api.request('/api/v1/confirmations', {method: 'POST', body: {account_id: this.hostId, action: 'archive.extract_overwrite', target: `${path}|${destination}`, preview: {archive: path, destination, collision}}});
        confirmation = issued.nonce;
      }
      const result = await this.api.request(this.hostPath('/archives/extract'), {method: 'POST', body: {archive: path, destination, collision, confirmation}});
      $('#dialog-body').innerHTML = `<div id="job-progress">${this.progressHtml(0, t('queued'))}</div>`;
      $('#dialog-actions').innerHTML = '';
      this.dialogSubmit = null;
      await this.pollJob(Number(result.job_id), false);
      this.closeDialog();
      await this.render();
    }, true);
    const policy = $('select[name="collision"]', this.dialogForm);
    const warning = $('#archive-overwrite-warning', this.dialogForm);
    const syncWarning = () => { if (warning) warning.hidden = policy?.value !== 'overwrite'; };
    policy?.addEventListener('change', syncWarning);
    syncWarning();
  }
  async restoreTrash(path) { await this.api.request(this.hostPath('/trash/restore'), {method: 'POST', body: {path}}); await this.render(); }
  archiveSelected() {
    const sources = [...this.fileSelection];
    const directory = (this.params.path || '.').replace(/\/$/, '');
    const singleOnlyDisabled = sources.length === 1 ? '' : ' disabled';
    this.openForm('archive', `<div class="notice"><strong>${escapeHtml(t('info'))}</strong>${escapeHtml(t('archiveQueueHint'))}</div><div class="field"><label>${escapeHtml(t('destination'))}</label><input name="destination" value="${escapeAttr(directory + '/archive.zip')}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('format'))}</label><select name="format"><option>zip</option><option>tar.gz</option><option>tar.bz2</option><option>tar</option><option${singleOnlyDisabled}>gz</option><option${singleOnlyDisabled}>bz2</option></select></div><div id="job-progress"></div>`, 'archive', async form => {
      const result = await this.api.request(this.hostPath('/archives'), {method: 'POST', body: {sources, destination: form.elements.destination.value, format: form.elements.format.value}});
      $('#dialog-body').innerHTML = `<div id="job-progress">${this.progressHtml(0, t('queued'))}</div>`;
      $('#dialog-actions').innerHTML = '';
      this.dialogSubmit = null;
      await this.pollJob(Number(result.job_id), false);
      this.fileSelection.clear();
      this.closeDialog();
      await this.render();
    });
    const format = $('select[name="format"]', this.dialogForm);
    const destination = $('input[name="destination"]', this.dialogForm);
    format?.addEventListener('change', () => {
      const suffix = {zip: '.zip', 'tar.gz': '.tar.gz', 'tar.bz2': '.tar.bz2', tar: '.tar', gz: '.gz', bz2: '.bz2'}[format.value] || '.zip';
      destination.value = destination.value.replace(/(?:\.tar\.gz|\.tar\.bz2|\.tgz|\.tbz2|\.zip|\.tar|\.gz|\.bz2)$/i, '') + suffix;
    });
  }
  async deleteSelectedFiles() { const selected = [...this.fileSelection]; for (const path of selected) { const checkbox = $(`.file-check[data-path="${CSS.escape(path)}"]`); const directory = checkbox?.closest('.list-row')?.querySelector('[data-action="file-open"]')?.dataset.directory === '1'; if (directory) { const issued = await this.api.request('/api/v1/confirmations', {method: 'POST', body: {account_id: this.hostId, action: 'file.delete_recursive', target: path, preview: {path}}}); await this.api.request(this.hostPath('/files'), {method: 'DELETE', body: {path, permanent: false, confirmation: issued.nonce}}); } else await this.api.request(this.hostPath('/files'), {method: 'DELETE', body: {path, permanent: false}}); } this.toast(t('success')); await this.render(); }
  moveSelectedFiles() { const selected = [...this.fileSelection]; this.openForm('move', `<div class="field"><label>${escapeHtml(t('destination'))}</label><input name="destination" value="${escapeAttr(this.params.path || '.')}" required dir="ltr"></div>`, 'move', async form => { const destination = form.elements.destination.value.replace(/\/$/, ''); for (const source of selected) await this.api.request(this.hostPath('/files/move'), {method: 'POST', body: {source, destination: `${destination}/${source.split('/').pop()}`}}); this.closeDialog(); await this.render(); }); }
  saveEditorAs() { this.openForm('saveAs', `<div class="field"><label>${escapeHtml(t('path'))}</label><input name="path" value="${escapeAttr(this.editorState.path)}" required dir="ltr"></div>`, 'save', async form => { this.closeDialog(); await this.saveEditor(form.elements.path.value); }); }
  toggleEditorWrap() { if (!this.editor) return; this.editor.session.setUseWrapMode(!this.editor.session.getUseWrapMode()); }
  async editorVersions() { const result = await this.api.request(query(this.hostPath('/files/versions'), {path: this.editorState.path})); this.openInfo('versions', `<div class="list">${(result.versions || []).map(version => `<article class="list-row"><div class="grow"><strong>#${Number(version.version_number || version.id)}</strong><small>${escapeHtml(bytes(version.size_bytes))} · ${escapeHtml(dateText(version.created_at))}</small></div><button class="button warning small" data-action="editor-restore-version" data-id="${Number(version.id)}">${escapeHtml(t('restore'))}</button></article>`).join('') || escapeHtml(t('noVersions'))}</div>`); this.editorVersionsData = result.versions || []; }

  createDatabase() { this.openForm('create', `<div class="field"><label>${escapeHtml(t('databaseName'))}</label><input name="name" required maxlength="64" dir="ltr"></div>`, 'create', async form => { await this.api.request(this.hostPath('/databases'), {method: 'POST', body: {name: form.elements.name.value}}); this.closeDialog(); await this.render(); }); }
  deleteDatabase(database) { return this.confirmOperation({warningKey: 'warningDatabase', action: 'database.delete', target: database, summary: `${t('databaseName')}: ${database}`, preview: {database}, perform: async confirmation => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(database)}`), {method: 'DELETE', body: {confirmation}}); await this.render(); }}); }
  async connectDatabase(database) { const result = await this.api.request(this.hostPath(`/databases/${encodeURIComponent(database)}/connect`), {method: 'POST', body: {}}); this.openInfo('directConnection', `<div class="notice"><strong>${escapeHtml(t('success'))}</strong>${escapeHtml(result.status)} · ${escapeHtml(result.db_user)} · ${escapeHtml(result.tested_ip || '')}</div>`); }
  databaseUsersDialog() { this.openInfo('dbUsers', `<button class="button" data-action="db-user-create">＋ ${escapeHtml(t('create'))}</button><div class="list">${(this.databaseUsers || []).map(item => { const user = nameOf(item) || String(item.name || ''); return `<article class="list-row"><div class="grow"><strong>${escapeHtml(user)}</strong></div><button class="button secondary small" data-action="db-user-password" data-user="${escapeAttr(user)}">${escapeHtml(t('password'))}</button><button class="button danger small" data-action="db-user-delete" data-user="${escapeAttr(user)}">${escapeHtml(t('remove'))}</button></article>`; }).join('')}</div>`); }
  createDatabaseUser() { const password = this.strongPassword(); this.openForm('dbUsers', `<div class="field"><label>${escapeHtml(t('dbUser'))}</label><input name="name" required maxlength="64" dir="ltr"></div><div class="field"><label>${escapeHtml(t('password'))}</label><input name="password" type="text" value="${escapeAttr(password)}" required minlength="12" dir="ltr"><span class="hint">${escapeHtml(t('generatePassword'))}</span></div>`, 'create', async form => { await this.api.request(this.hostPath('/database-users'), {method: 'POST', body: {name: form.elements.name.value, password: form.elements.password.value}}); form.elements.password.value = ''; this.closeDialog(); await this.pageDatabases(); }); }
  databaseUserPassword(user) { this.openForm('password', `<div class="field"><label>${escapeHtml(t('dbUser'))}</label><input value="${escapeAttr(user)}" readonly></div><div class="field"><label>${escapeHtml(t('password'))}</label><input name="password" type="text" value="${escapeAttr(this.strongPassword())}" required minlength="12" dir="ltr"></div>`, 'save', async form => { await this.api.request(this.hostPath(`/database-users/${encodeURIComponent(user)}/password`), {method: 'PATCH', body: {password: form.elements.password.value}}); form.elements.password.value = ''; this.closeDialog(); }); }
  deleteDatabaseUser(user) { return this.confirmOperation({warningKey: 'warningDatabase', action: 'database_user.delete', target: user, summary: `${t('dbUser')}: ${user}`, perform: async confirmation => { await this.api.request(this.hostPath(`/database-users/${encodeURIComponent(user)}`), {method: 'DELETE', body: {confirmation}}); await this.pageDatabases(); }}); }
  async databasePrivilegesDialog() {
    const databases = (this.databaseItems || []).map(item => nameOf(item) || item.database_name).filter(Boolean);
    const users = (this.databaseUsers || []).map(item => nameOf(item) || item.name).filter(Boolean);
    if (!databases.length || !users.length) {
      this.openInfo('privileges', `<section class="empty-state"><h2>${escapeHtml(t('noDatabaseTitle'))}</h2><p>${escapeHtml(t('noDatabaseBody'))}</p></section>`);
      return;
    }
    this.openForm('privileges', `<div class="notice"><strong>${escapeHtml(t('serverPrivileges'))}</strong><span id="privilege-source">${escapeHtml(t('loading'))}</span></div><div class="field"><label>${escapeHtml(t('databaseName'))}</label><select name="database">${databases.map(name => `<option>${escapeHtml(name)}</option>`).join('')}</select></div><div class="field"><label>${escapeHtml(t('dbUser'))}</label><select name="user">${users.map(name => `<option>${escapeHtml(name)}</option>`).join('')}</select></div><div id="database-privilege-options" class="cards"><div class="skeleton"></div></div><label class="check"><input type="checkbox" name="revoke_all">${escapeHtml(t('revokeAllPrivileges'))}</label>`, 'save', async form => {
      this.assertWritableCapabilities('mysql');
      const database = form.elements.database.value;
      const user = form.elements.user.value;
      if (form.elements.revoke_all.checked) {
        this.closeDialog();
        return this.confirmOperation({warningKey: 'warningDatabase', action: 'database.privileges_revoke', target: `${database}:${user}`, summary: `${database}\n${user}\n${t('revokeAllPrivileges')}`, preview: {database, user}, perform: async confirmation => {
          await this.api.request(query(this.hostPath('/database-privileges'), {database, user}), {method: 'DELETE', body: {confirmation}});
        }});
      } else {
        const selected = $$('input[name="privileges"]:checked', form).map(input => input.value);
        await this.api.request(this.hostPath('/database-privileges'), {method: 'PUT', body: {database, user, privileges: selected}});
      }
      this.closeDialog();
      this.toast(t('success'));
    });
    const form = this.dialogForm;
    const refresh = () => this.refreshDatabasePrivileges(form).catch(error => this.operationError(error));
    form.elements.database.addEventListener('change', refresh);
    form.elements.user.addEventListener('change', refresh);
    form.elements.revoke_all.addEventListener('change', event => {
      $$('input[name="privileges"]', form).forEach(input => { input.disabled = event.target.checked; });
    });
    await this.refreshDatabasePrivileges(form);
  }

  async refreshDatabasePrivileges(form) {
    const result = await this.api.request(query(this.hostPath('/database-privileges'), {database: form.elements.database.value, user: form.elements.user.value}));
    if (!this.dialog.open || form !== this.dialogForm) return;
    const granted = new Set(result.granted_privileges || []);
    const options = result.supported_privileges || [];
    $('#privilege-source', form).textContent = t(result.capability_source === 'official_uapi_contract' ? 'privilegeSourceContract' : 'privilegeSourceCpanel');
    $('#database-privilege-options', form).innerHTML = options.map(name => `<label class="check"><input type="checkbox" name="privileges" value="${escapeAttr(name)}" ${granted.has(name) ? 'checked' : ''}>${escapeHtml(name)}</label>`).join('') || `<p class="muted">${escapeHtml(t('unavailable'))}</p>`;
  }
  async remoteMysqlDialog() { const result = await this.api.request(this.hostPath('/remote-mysql-hosts')); const hosts = asList(result.hosts, ['hosts', 'items']); this.remoteHosts = hosts; this.openInfo('remoteMysql', `<button class="button" data-action="db-remote-add">＋ ${escapeHtml(t('create'))}</button><div class="notice warning"><strong>${escapeHtml(t('info'))}</strong>${escapeHtml(t('remoteMysqlExplain'))}</div><div class="list">${hosts.map(item => { const host = String(item.host || item.hostname || item); return `<article class="list-row"><div class="grow"><strong>${escapeHtml(host)}</strong></div><button class="button danger small" data-action="db-remote-delete" data-host="${escapeAttr(host)}">${escapeHtml(t('remove'))}</button></article>`; }).join('')}</div>`); }
  addRemoteMysql() { this.openForm('remoteMysql', `<div class="field"><label>${escapeHtml(t('remoteMysqlHost'))}</label><input name="host" required dir="ltr"></div><div class="notice warning">${escapeHtml(t('remoteMysqlWarning'))}</div>`, 'create', async form => { await this.api.request(this.hostPath('/remote-mysql-hosts'), {method: 'POST', body: {host: form.elements.host.value}}); this.closeDialog(); await this.remoteMysqlDialog(); }); }
  deleteRemoteMysql(host) { return this.confirmClient('warningDatabase', host, async () => { await this.api.request(this.hostPath('/remote-mysql-hosts'), {method: 'DELETE', body: {host}}); await this.remoteMysqlDialog(); }); }
  strongPassword() { const values = crypto.getRandomValues(new Uint8Array(24)); return `Aa7!${[...values].map(value => (value % 36).toString(36)).join('')}`; }

  exportDatabase(database = '', table = '') { const selectedDatabase = database || this.params.database || ''; this.openForm('exportDatabase', `<div class="field"><label>${escapeHtml(t('databaseName'))}</label><input name="database" value="${escapeAttr(selectedDatabase)}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('tables'))}</label><input name="tables" value="${escapeAttr(table)}" dir="ltr"><span class="hint">${escapeHtml(t('commaTablesHint'))}</span></div><div class="field"><label>${escapeHtml(t('exportMode'))}</label><select name="mode"><option value="full">${escapeHtml(t('full'))}</option><option value="structure">${escapeHtml(t('structure'))}</option><option value="data">${escapeHtml(t('dataOnly'))}</option></select></div><div class="field"><label>${escapeHtml(t('compression'))}</label><select name="compression"><option value="gz">Gzip</option><option value="none">${escapeHtml(t('none'))}</option></select></div><div id="job-progress"></div>`, 'exportDatabase', async form => { const db = form.elements.database.value, tables = form.elements.tables.value.split(',').map(value => value.trim()).filter(Boolean); const result = await this.api.request(this.hostPath(`/databases/${encodeURIComponent(db)}/exports`), {method: 'POST', body: {tables, mode: form.elements.mode.value, compression: form.elements.compression.value}}); await this.pollJob(result.job_id, true); }); }
  importDatabase(database = '') { this.openForm('importSql', `<div class="notice danger"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningImport'))}</div><div class="field"><label>${escapeHtml(t('databaseName'))}</label><input name="database" value="${escapeAttr(database || this.params.database || '')}" required dir="ltr"></div><div class="field"><label>SQL / SQL.GZ / ZIP</label><input name="file" type="file" accept=".sql,.sql.gz,.gz,.zip" required></div><label class="check"><input name="backup_first" type="checkbox" checked>${escapeHtml(t('backupFirst'))}</label><label class="check"><input name="confirm_import" type="checkbox" required>${escapeHtml(t('confirmImport'))}</label><div id="job-progress"></div>`, 'importSql', async form => { const file = form.elements.file.files[0]; if (!file || !form.elements.confirm_import.checked) return; const db = form.elements.database.value; const issued = await this.api.request(this.hostPath(`/databases/${encodeURIComponent(db)}/imports/confirmation`), {method: 'POST', body: {filename: file.name, bytes: file.size, backup_first: form.elements.backup_first.checked}}); const data = new FormData(); data.append('file', file, file.name); data.append('backup_first', form.elements.backup_first.checked ? '1' : '0'); data.append('confirmation', issued.nonce); $('#job-progress').innerHTML = this.progressHtml(0, t('uploadProgress', {percent: 0})); const result = await this.api.upload(this.hostPath(`/databases/${encodeURIComponent(db)}/imports`), data, percent => this.updateProgress(percent, t('uploadProgress', {percent}))); await this.pollJob(result.job_id, false); }); }
  progressHtml(percent, label) { return `<div class="job"><div class="progress"><span style="width:${percent}%"></span></div><span>${escapeHtml(label)}</span></div>`; }
  updateProgress(percent, label) { const root = $('#job-progress'); if (root) root.innerHTML = this.progressHtml(percent, label); }
  async pollJob(jobId, downloadable = false) { let status; for (;;) { status = await this.api.request(`/api/v1/jobs/${jobId}`); this.updateProgress(Number(status.progress || 0), `${t(status.status) || status.status} · ${status.progress || 0}%`); if (['completed', 'failed'].includes(status.status)) break; await new Promise(resolve => setTimeout(resolve, 1500)); } if (status.status === 'failed') throw new ApiError({code: status.last_error_code || 'queue_job_failed', message: t('failed')}, 422); this.haptic('success'); if (downloadable) { $('#job-progress').insertAdjacentHTML('beforeend', `<button type="button" class="button" data-action="job-download" data-id="${Number(jobId)}">${escapeHtml(t('download'))}</button>`); } return status; }

  createTable(database) { const definitions = [{name: 'id', type: 'BIGINT', unsigned: true, nullable: false, auto_increment: true, primary: true}, {name: 'name', type: 'VARCHAR', length: '191', nullable: false}]; this.openForm('createTable', `<div class="field"><label>${escapeHtml(t('tableName'))}</label><input name="name" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('columnDefinition'))} (JSON)</label><textarea name="columns" rows="10" dir="ltr">${escapeHtml(formatJson(definitions))}</textarea></div><div class="field"><label>${escapeHtml(t('engine'))}</label><select name="engine"><option>InnoDB</option><option>MyISAM</option><option>MEMORY</option><option>Aria</option></select></div><div class="field"><label>${escapeHtml(t('collation'))}</label><input name="collation" value="utf8mb4_unicode_ci" required dir="ltr"></div>`, 'create', async form => { const columns = this.parseJson(form.elements.columns.value, 'column_definition'); await this.api.request(this.hostPath(`/databases/${encodeURIComponent(database)}/tables`), {method: 'POST', body: {name: form.elements.name.value, columns, engine: form.elements.engine.value, collation: form.elements.collation.value}}); this.closeDialog(); await this.render(); }); }
  tableSort(column) { const direction = this.params.sort === column && this.params.direction !== 'desc' ? 'desc' : 'asc'; this.navigate('table', {...this.params, sort: column, direction}); }
  tableFilter() { this.openForm('search', `<div class="field"><label>${escapeHtml(t('filterColumn'))}</label><select name="column">${this.tableColumns.map(column => `<option>${escapeHtml(column.name)}</option>`).join('')}</select></div><div class="field"><label>${escapeHtml(t('operator'))}</label><select name="operator">${['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IS NULL', 'IS NOT NULL'].map(operator => `<option>${escapeHtml(operator)}</option>`).join('')}</select></div><div class="field"><label>${escapeHtml(t('value'))}</label><input name="value"></div>`, 'search', async form => { this.closeDialog(); this.navigate('table', {database: this.params.database, table: this.params.table, filterColumn: form.elements.column.value, operator: form.elements.operator.value, value: form.elements.value.value}); }); }
  tableStructureDialog() { const columns = this.tableStructure.columns || [], indexes = this.tableStructure.indexes || []; this.openInfo('structure', `<div class="toolbar"><button class="button" data-action="column-add">＋ ${escapeHtml(t('addColumn'))}</button><button class="button secondary" data-action="index-add">＋ ${escapeHtml(t('addIndex'))}</button></div><h3>${escapeHtml(t('columns'))}</h3><div class="list">${columns.map((column, index) => `<article class="list-row"><div class="grow"><strong>${escapeHtml(column.name)}</strong><small>${escapeHtml(column.type)} · ${escapeHtml(column.nullable)}</small></div><button class="button secondary small" data-action="column-edit" data-index="${index}">${escapeHtml(t('edit'))}</button><button class="button danger small" data-action="column-delete" data-column="${escapeAttr(column.name)}">${escapeHtml(t('remove'))}</button></article>`).join('')}</div><h3>${escapeHtml(t('indexes'))}</h3><div class="list">${indexes.map(index => { const name = String(index.Key_name || index.key_name || index.name || ''); return `<article class="list-row"><div class="grow"><strong>${escapeHtml(name)}</strong><small>${escapeHtml(index.Column_name || index.column_name || '')}</small></div><button class="button danger small" data-action="index-delete" data-index-name="${escapeAttr(name)}">${escapeHtml(t('remove'))}</button></article>`; }).join('')}</div>`); }
  columnVisibilityDialog() { const key = `tcpm.columns.${this.hostId}.${this.params.database}.${this.params.table}`; this.openForm('visibleColumns', `<div class="list">${this.tableColumns.map(column => `<label class="check"><input type="checkbox" name="columns" value="${escapeAttr(column.name)}" ${this.visibleColumns.has(column.name) ? 'checked' : ''}>${escapeHtml(column.name)}</label>`).join('')}</div>`, 'save', async form => { const values = $$('input[name="columns"]:checked', form).map(input => input.value); if (!values.length) return; localStorage.setItem(key, JSON.stringify(values)); this.closeDialog(); await this.render(); }); }
  columnForm(column = {}) { return `<div class="field"><label>${escapeHtml(t('name'))}</label><input name="name" value="${escapeAttr(column.name || '')}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('type'))}</label><select name="type">${['TINYINT','SMALLINT','MEDIUMINT','INT','BIGINT','DECIMAL','FLOAT','DOUBLE','BOOLEAN','CHAR','VARCHAR','TEXT','MEDIUMTEXT','LONGTEXT','BLOB','DATE','DATETIME','TIMESTAMP','JSON','ENUM','SET'].map(type => `<option ${String(column.data_type || '').toUpperCase() === type ? 'selected' : ''}>${type}</option>`).join('')}</select></div><div class="field"><label>${escapeHtml(t('lengthValues'))}</label><input name="length" value="${escapeAttr(this.columnLength(column.type || ''))}" dir="ltr"></div><div class="field"><label>${escapeHtml(t('defaultValue'))}</label><input name="default" value="${escapeAttr(column.default_value ?? '')}" dir="ltr"></div><label class="check"><input name="nullable" type="checkbox" ${column.nullable === 'YES' || column.nullable === true ? 'checked' : ''}>${escapeHtml(t('nullable'))}</label><label class="check"><input name="unsigned" type="checkbox" ${String(column.type || '').includes('unsigned') ? 'checked' : ''}>${escapeHtml(t('unsigned'))}</label><label class="check"><input name="auto_increment" type="checkbox" ${String(column.extra || '').includes('auto_increment') ? 'checked' : ''}>${escapeHtml(t('autoIncrement'))}</label><label class="check"><input name="primary" type="checkbox" ${column.column_key === 'PRI' ? 'checked' : ''}>${escapeHtml(t('primaryKey'))}</label>`; }
  columnLength(type) { return String(type).match(/\((.*)\)/)?.[1] || ''; }
  readColumnForm(form) { const definition = {name: form.elements.name.value, type: form.elements.type.value, nullable: form.elements.nullable.checked, unsigned: form.elements.unsigned.checked, auto_increment: form.elements.auto_increment.checked, primary: form.elements.primary.checked}; if (form.elements.length.value) definition.length = form.elements.length.value; if (form.elements.default.value !== '') definition.default = form.elements.default.value; return definition; }
  addColumn() { this.openForm('addColumn', this.columnForm(), 'create', async form => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/columns`), {method: 'POST', body: {definition: this.readColumnForm(form)}}); this.closeDialog(); await this.render(); }); }
  editColumn(index) { const column = this.tableStructure.columns[index]; this.openForm('edit', this.columnForm(column), 'save', async form => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/columns/${encodeURIComponent(column.name)}`), {method: 'PATCH', body: {definition: this.readColumnForm(form)}}); this.closeDialog(); await this.render(); }); }
  deleteColumn(column) { const target = `${this.params.database}.${this.params.table}.${column}`; return this.confirmOperation({warningKey: 'warningTable', action: 'column.drop', target, summary: target, perform: async confirmation => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/columns/${encodeURIComponent(column)}`), {method: 'DELETE', body: {confirmation}}); await this.render(); }}); }
  addIndex() { this.openForm('addIndex', `<div class="field"><label>${escapeHtml(t('name'))}</label><input name="name" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('columns'))}</label><div class="list">${this.tableColumns.map(column => `<label class="check"><input type="checkbox" name="columns" value="${escapeAttr(column.name)}">${escapeHtml(column.name)}</label>`).join('')}</div></div><label class="check"><input type="checkbox" name="unique">UNIQUE</label><label class="check"><input type="checkbox" name="primary">PRIMARY</label>`, 'create', async form => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/indexes`), {method: 'POST', body: {name: form.elements.name.value, columns: $$('input[name="columns"]:checked', form).map(input => input.value), unique: form.elements.unique.checked, primary: form.elements.primary.checked}}); this.closeDialog(); await this.render(); }); }
  deleteIndex(index) { const target = `${this.params.database}.${this.params.table}.${index}`; return this.confirmOperation({warningKey: 'warningTable', action: 'index.drop', target, summary: target, perform: async confirmation => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/indexes/${encodeURIComponent(index)}`), {method: 'DELETE', body: {confirmation}}); await this.render(); }}); }
  tableOperation(database, table, operation) { const destructive = ['DROP', 'TRUNCATE'].includes(operation), run = async confirmation => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(database)}/tables/${encodeURIComponent(table)}/operations`), {method: 'POST', body: {operation, confirmation}}); if (operation === 'DROP') this.navigate('database', {database}); else await this.render(); }; if (destructive) return this.confirmOperation({warningKey: 'warningTable', action: `table.${operation.toLowerCase()}`, target: `${database}.${table}`, summary: `${operation} TABLE ${database}.${table}`, perform: run}); return this.confirmClient('warningTable', `${operation} TABLE ${database}.${table}`, () => run(null)); }

  toggleRowSelection(index, checked) { if (checked) this.tableSelection.set(index, this.rowKey(this.tableRows[index])); else this.tableSelection.delete(index); const enabled = this.tableSelection.size > 0; if ($('#bulk-delete')) $('#bulk-delete').disabled = !enabled; if ($('#row-export')) $('#row-export').disabled = !enabled; }
  rowKey(row) { return Object.fromEntries(this.tablePrimary.map(column => [column, row?.[column] ?? null])); }
  rowFields(row = {}, editing = false) { return this.tableColumns.filter(column => !String(column.extra || '').includes('generated') && !(editing && column.column_key === 'PRI')).map(column => `<div class="field"><label>${escapeHtml(column.name)} <small>${escapeHtml(column.type)}</small></label><input name="value:${escapeAttr(column.name)}" value="${escapeAttr(row[column.name] ?? '')}" dir="auto"><label class="check"><input type="checkbox" name="null:${escapeAttr(column.name)}" ${row[column.name] === null ? 'checked' : ''}>NULL</label></div>`).join(''); }
  rowValues(form) { const data = new FormData(form), values = {}; for (const column of this.tableColumns) { if (String(column.extra || '').includes('generated')) continue; if (data.has(`value:${column.name}`)) values[column.name] = data.has(`null:${column.name}`) ? null : data.get(`value:${column.name}`); } return values; }
  rowDetails(index) { this.openInfo('details', `<pre class="code">${escapeHtml(formatJson(this.tableRows[index]))}</pre>`); }
  insertRow() { this.openForm('insertRow', this.rowFields(), 'create', async form => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/rows`), {method: 'POST', body: {values: this.rowValues(form)}}); this.closeDialog(); await this.render(); }); }
  editRow(index) { const row = this.tableRows[index], key = this.rowKey(row); this.openForm('editRow', this.rowFields(row, true), 'save', async form => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/rows`), {method: 'PATCH', body: {key, values: this.rowValues(form)}}); this.closeDialog(); await this.render(); }); }
  deleteRow(index) { const key = this.rowKey(this.tableRows[index]), target = `${this.params.database}.${this.params.table}:row`; return this.confirmOperation({warningKey: 'warningTable', action: 'row.delete', target, summary: formatJson(key), preview: {key}, perform: async confirmation => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/rows`), {method: 'DELETE', body: {key, confirmation}}); await this.render(); }}); }
  bulkDeleteRows() { const keys = [...this.tableSelection.values()], target = `${this.params.database}.${this.params.table}:bulk`; return this.confirmOperation({warningKey: 'warningTable', action: 'row.bulk_delete', target, summary: `${t('selected', {count: keys.length})}\n${formatJson(keys)}`, preview: {count: keys.length}, perform: async confirmation => { await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/tables/${encodeURIComponent(this.params.table)}/rows`), {method: 'DELETE', body: {keys, confirmation}}); await this.render(); }}); }
  exportSelectedRows() { const rows = [...this.tableSelection.keys()].map(index => this.tableRows[index]), columns = this.tableColumns.map(column => column.name), quote = value => `"${String(value ?? '').replaceAll('"', '""')}"`, csv = '\uFEFF' + [columns.map(quote).join(','), ...rows.map(row => columns.map(column => quote(row[column])).join(','))].join('\r\n'), blob = new Blob([csv], {type: 'text/csv;charset=utf-8'}), url = URL.createObjectURL(blob), anchor = document.createElement('a'); anchor.href = url; anchor.download = `${this.params.table}-selected.csv`; anchor.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }

  async executeSql(page = 1) { page = Math.max(1, Number(page) || 1); const editorSql = this.sqlEditor?.getValue() || '', sql = page === 1 ? editorSql : this.sqlResultQuery, database = this.params.database; if (page > 1 && (!sql || sql !== editorSql)) { this.toast(t('sqlPaginationChanged'), 'warning'); return; } const analysis = await this.api.request(this.hostPath(`/databases/${encodeURIComponent(database)}/sql/analyze`), {method: 'POST', body: {sql}}); if (page > 1 && !analysis.read_only) { this.toast(t('sqlPaginationReadOnly'), 'warning'); return; } const perform = async confirmation => { const result = await this.api.request(this.hostPath(`/databases/${encodeURIComponent(database)}/sql`), {method: 'POST', body: {sql, confirmation, page, per_page: 100, backup_first: page === 1 && ($('#sql-backup')?.checked || false), save_history: page === 1 && ($('#sql-history')?.checked ?? true)}}); this.renderSqlResult(result.execution || result, sql, true); this.toast(t('success')); this.haptic('success'); }; if (analysis.requires_confirmation) { const hash = await this.sha256(sql); return this.confirmOperation({warningKey: 'warningSql', action: 'sql.execute', target: hash, summary: `${analysis.type}\n${(analysis.reasons || []).join(', ')}\n\n${sql.slice(0, 2000)}`, preview: analysis, perform}); } return perform(null); }
  async explainSql() { const sql = this.sqlEditor?.getValue() || ''; const result = await this.api.request(this.hostPath(`/databases/${encodeURIComponent(this.params.database)}/sql/explain`), {method: 'POST', body: {sql}}); this.renderSqlResult(result, '', false); }
  formatSql() { if (!this.sqlEditor) return; this.sqlEditor.setValue(this.formatSqlText(this.sqlEditor.getValue()), -1); }
  formatSqlText(sql) { const keywords = new Set(['SELECT','FROM','WHERE','LEFT','RIGHT','INNER','OUTER','JOIN','GROUP','ORDER','HAVING','LIMIT','UNION','VALUES','SET','AND','OR']); let output = '', word = '', quote = null; const flush = () => { if (!word) return; const upper = word.toUpperCase(); if (keywords.has(upper) && ['FROM','WHERE','LEFT','RIGHT','INNER','OUTER','JOIN','GROUP','ORDER','HAVING','LIMIT','UNION','VALUES','SET'].includes(upper)) output = output.trimEnd() + '\n'; else if (['AND','OR'].includes(upper)) output = output.trimEnd() + '\n  '; output += keywords.has(upper) ? upper : word; word = ''; }; for (let index = 0; index < sql.length; index += 1) { const character = sql[index]; if (quote) { output += character; if (character === '\\') { output += sql[++index] || ''; continue; } if (character === quote) quote = null; continue; } if (["'", '"', '`'].includes(character)) { flush(); quote = character; output += character; continue; } if (/[A-Za-z_]/.test(character)) word += character; else { flush(); output += character; } } flush(); return output.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim(); }
  saveSqlQuery() { const sql = this.sqlEditor?.getValue() || ''; this.openForm('saveQuery', `<div class="field"><label>${escapeHtml(t('queryName'))}</label><input name="name" required maxlength="191"></div>`, 'save', async form => { await this.api.request(this.hostPath('/sql/saved'), {method: 'POST', body: {database: this.params.database, name: form.elements.name.value, sql}}); this.closeDialog(); await this.pageSql(); }); }
  async deleteSavedQuery(id) { await this.api.request(this.hostPath(`/sql/saved/${id}`), {method: 'DELETE', body: {}}); await this.pageSql(); }
  async sha256(value) { const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value)); return [...new Uint8Array(hash)].map(byte => byte.toString(16).padStart(2, '0')).join(''); }
  async restoreEditorVersion(id) { const path = this.editorState.path, target = `${path}:${id}`; this.closeDialog(); return this.confirmOperation({warningKey: 'warningVersion', action: 'file.restore_version', target, summary: `${path}\n${t('version')} #${id}`, perform: async confirmation => { await this.api.request(this.hostPath(`/files/versions/${id}/restore`), {method: 'POST', body: {path, confirmation}}); await this.pageEditor(); }}); }

  createDomain() { this.openForm('addDomain', `<div class="field"><label>${escapeHtml(t('domain'))}</label><input name="domain" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('documentRoot'))}</label><input name="document_root" required value="public_html/" dir="ltr"></div>`, 'create', async form => { await this.api.request(this.hostPath('/domains'), {method: 'POST', body: {domain: form.elements.domain.value, document_root: form.elements.document_root.value}}); this.closeDialog(); await this.render(); }); }
  deleteDomain(domain) { return this.confirmOperation({warningKey: 'warningDatabase', action: 'domain.delete', target: domain, summary: `${t('domain')}: ${domain}\n${t('dnsWarning')}`, preview: {domain}, perform: async confirmation => { await this.api.request(this.hostPath(`/domains/${encodeURIComponent(domain)}`), {method: 'DELETE', body: {confirmation}}); await this.render(); }}); }
  createSubdomain(parent) { this.openForm('subdomains', `<div class="field"><label>${escapeHtml(t('subdomains'))}</label><input name="subdomain" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('domain'))}</label><input name="parent_domain" value="${escapeAttr(parent)}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('documentRoot'))}</label><input name="document_root" value="public_html/" required dir="ltr"></div>`, 'create', async form => { await this.api.request(this.hostPath('/subdomains'), {method: 'POST', body: {subdomain: form.elements.subdomain.value, parent_domain: form.elements.parent_domain.value, document_root: form.elements.document_root.value}}); this.closeDialog(); await this.render(); }); }
  deleteSubdomain(domain) { return this.confirmOperation({warningKey: 'warningDatabase', action: 'subdomain.delete', target: domain, summary: domain, perform: async confirmation => { await this.api.request(this.hostPath(`/subdomains/${encodeURIComponent(domain)}`), {method: 'DELETE', body: {confirmation}}); await this.render(); }}); }
  createRedirect() { const domains = this.namedObjects(this.domainData?.domains, 'domain').map(item => item.domain || item.name).filter(Boolean); this.openForm('redirects', `<div class="field"><label>${escapeHtml(t('domain'))}</label><select name="domain">${domains.map(name => `<option>${escapeHtml(name)}</option>`).join('')}</select></div><div class="field"><label>${escapeHtml(t('source'))}</label><input name="source" value="/" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('target'))}</label><input name="destination" type="url" required pattern="https://.*" dir="ltr"></div><div class="field"><label>${escapeHtml(t('httpStatus'))}</label><select name="status"><option value="301">301</option><option value="302">302</option></select></div><label class="check"><input name="wildcard" type="checkbox">${escapeHtml(t('wildcard'))}</label>`, 'create', async form => { await this.api.request(this.hostPath('/redirects'), {method: 'POST', body: {domain: form.elements.domain.value, source: form.elements.source.value, destination: form.elements.destination.value, status: Number(form.elements.status.value), wildcard: form.elements.wildcard.checked}}); this.closeDialog(); await this.render(); }); }
  deleteRedirect(domain, source) { const target = `${domain}:${source}`; return this.confirmOperation({warningKey: 'warningFileDelete', action: 'redirect.delete', target, summary: target, perform: async confirmation => { await this.api.request(this.hostPath('/redirects'), {method: 'DELETE', body: {domain, source, confirmation}}); await this.render(); }}); }
  async dnsDialog(domain) { const result = await this.api.request(this.hostPath(`/dns/${encodeURIComponent(domain)}`)); this.openForm('dns', `<div class="notice warning"><strong>${escapeHtml(t('warning'))}</strong>${escapeHtml(t('dnsWarning'))}</div><pre class="code">${escapeHtml(formatJson(result.records))}</pre><div class="field"><label>${escapeHtml(t('dnsChanges'))} (JSON)</label><textarea name="changes" rows="10" dir="ltr">[]</textarea><span class="hint">${escapeHtml(t('dnsHint'))}</span></div>`, 'save', async form => { const changes = this.parseJson(form.elements.changes.value, 'dns_changes'); if (!Array.isArray(changes) || !changes.length) throw new ApiError({code: 'invalid_dns_changes', message: t('dnsRequired')}, 422); this.closeDialog(); await this.confirmOperation({warningKey: 'warningDatabase', action: 'dns.edit', target: domain, summary: `${domain}\n${formatJson(changes)}`, preview: {count: changes.length}, perform: async confirmation => { await this.api.request(this.hostPath(`/dns/${encodeURIComponent(domain)}`), {method: 'PATCH', body: {changes, confirmation}}); await this.render(); }}); }); }

  createEmail() { this.openForm('mailAccounts', `<div class="field"><label>${escapeHtml(t('emailLocalPart'))}</label><input name="local_part" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('domain'))}</label><input name="domain" value="${escapeAttr(this.activeHost()?.main_domain || '')}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('password'))}</label><input name="password" type="text" value="${escapeAttr(this.strongPassword())}" required minlength="12" dir="ltr"></div><div class="field"><label>${escapeHtml(t('quota'))}</label><input name="quota_mib" type="number" min="1" value="1024"></div>`, 'create', async form => { await this.api.request(this.hostPath('/email/accounts'), {method: 'POST', body: {local_part: form.elements.local_part.value, domain: form.elements.domain.value, password: form.elements.password.value, quota_mib: Number(form.elements.quota_mib.value)}}); form.elements.password.value = ''; this.closeDialog(); await this.render(); }); }
  changeEmailPassword(email) { this.openForm('password', `<div class="field"><label>${escapeHtml(t('email'))}</label><input value="${escapeAttr(email)}" readonly></div><div class="field"><label>${escapeHtml(t('password'))}</label><input name="password" type="text" value="${escapeAttr(this.strongPassword())}" required minlength="12" dir="ltr"></div>`, 'save', async form => { await this.api.request(this.hostPath('/email/password'), {method: 'PATCH', body: {email, password: form.elements.password.value}}); form.elements.password.value = ''; this.closeDialog(); }); }
  changeEmailQuota(email) { this.openForm('quota', `<div class="field"><label>${escapeHtml(t('quota'))}</label><input name="quota_mib" type="number" min="1" max="1048576" value="1024" required></div>`, 'save', async form => { await this.api.request(this.hostPath('/email/quota'), {method: 'PATCH', body: {email, quota_mib: Number(form.elements.quota_mib.value)}}); this.closeDialog(); await this.render(); }); }
  deleteEmail(email) { return this.confirmOperation({warningKey: 'warningFileDelete', action: 'email.delete', target: email, summary: `${t('email')}: ${email}\n${t('mailboxDeleteWarning')}`, perform: async confirmation => { await this.api.request(this.hostPath('/email/accounts'), {method: 'DELETE', body: {email, confirmation}}); await this.render(); }}); }
  createForwarder() { this.openForm('forwarders', `<div class="field"><label>${escapeHtml(t('source'))}</label><input name="source" type="email" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('target'))}</label><input name="destination" type="email" required dir="ltr"></div>`, 'create', async form => { await this.api.request(this.hostPath('/email/forwarders'), {method: 'POST', body: {source: form.elements.source.value, destination: form.elements.destination.value}}); this.closeDialog(); await this.render(); }); }
  deleteForwarder(source, destination) { const target = `${source}:${destination}`; return this.confirmOperation({warningKey: 'warningFileDelete', action: 'email.forwarder_delete', target, summary: `${source} → ${destination}`, perform: async confirmation => { await this.api.request(this.hostPath('/email/forwarders'), {method: 'DELETE', body: {source, destination, confirmation}}); await this.render(); }}); }
  autoresponderDialog(item = {}) { const address = item.email || item.address || ''; this.openForm('autoresponders', `<div class="field"><label>${escapeHtml(t('email'))}</label><input name="email" type="email" value="${escapeAttr(address)}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('subject'))}</label><input name="subject" value="${escapeAttr(item.subject || '')}" required></div><div class="field"><label>${escapeHtml(t('message'))}</label><textarea name="body" required>${escapeHtml(item.body || '')}</textarea></div><div class="field"><label>${escapeHtml(t('interval'))}</label><input name="interval_hours" type="number" min="1" max="720" value="${Number(item.interval || 8)}"></div><div class="field"><label>${escapeHtml(t('startUnix'))}</label><input name="start" type="number" min="0" value="${Number(item.start || 0)}"></div><div class="field"><label>${escapeHtml(t('stopUnix'))}</label><input name="stop" type="number" min="0" value="${Number(item.stop || 0)}"></div>`, 'save', async form => { await this.api.request(this.hostPath('/email/autoresponders'), {method: 'PUT', body: {email: form.elements.email.value, subject: form.elements.subject.value, body: form.elements.body.value, interval_hours: Number(form.elements.interval_hours.value), start: Number(form.elements.start.value), stop: Number(form.elements.stop.value)}}); this.closeDialog(); await this.render(); }); }
  deleteAutoresponder(email) { return this.confirmOperation({warningKey: 'warningFileDelete', action: 'email.autoresponder_delete', target: email, summary: email, perform: async confirmation => { await this.api.request(this.hostPath('/email/autoresponders'), {method: 'DELETE', body: {email, confirmation}}); await this.render(); }}); }

  runAutoSsl() { return this.confirmOperation({warningKey: 'warningFileDelete', action: 'ssl.autossl', target: 'autossl', summary: t('autoSslSummary'), perform: async confirmation => { await this.api.request(this.hostPath('/ssl/autossl'), {method: 'POST', body: {confirmation}}); await this.render(); }}); }
  async inspectSsl(domain) { const result = await this.api.request(this.hostPath('/ssl/certificates/inspect'), {method: 'POST', body: {domains: [domain]}}); this.openInfo('inspectCertificate', `<pre class="code">${escapeHtml(formatJson(result.certificates))}</pre>`); }

  cronDialog(item = null, line = null) { const expression = item ? [item.minute, item.hour, item.day, item.month, item.weekday].join(' ') : '0 * * * *', command = String(item?.command || '').replace(/^#TCM_DISABLED# /, ''); this.openForm('cronJobs', `<div class="notice warning"><strong>${escapeHtml(t('warning'))}</strong>${escapeHtml(t('warningCron'))}</div><div class="field"><label>${escapeHtml(t('expression'))}</label><input name="expression" value="${escapeAttr(expression)}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('command'))}</label><textarea name="command" required dir="ltr">${escapeHtml(command)}</textarea></div>`, 'save', async form => { const value = form.elements.command.value, schedule = form.elements.expression.value, target = `cron:${await this.sha256(value)}`, existing = item ? {minute: String(item.minute), hour: String(item.hour), day: String(item.day), month: String(item.month), weekday: String(item.weekday), command: String(item.command)} : null; this.closeDialog(); await this.confirmOperation({warningKey: 'warningCron', action: 'cron.save', target, summary: `${schedule}\n${value}`, perform: async confirmation => { if (item) await this.api.request(this.hostPath(`/cron/${line}`), {method: 'PUT', body: {existing, expression: schedule, command: value, confirmation}}); else await this.api.request(this.hostPath('/cron'), {method: 'POST', body: {expression: schedule, command: value, confirmation}}); await this.render(); }}); }); }
  async toggleCron(item, line, enabled) { const existing = {minute: String(item.minute), hour: String(item.hour), day: String(item.day), month: String(item.month), weekday: String(item.weekday), command: String(item.command)}; await this.api.request(this.hostPath(`/cron/${line}/enabled`), {method: 'PATCH', body: {existing, enabled}}); await this.render(); }
  deleteCron(item, line) { const existing = {minute: String(item.minute), hour: String(item.hour), day: String(item.day), month: String(item.month), weekday: String(item.weekday), command: String(item.command)}; return this.confirmOperation({warningKey: 'warningCron', action: 'cron.delete', target: `cron:${line}`, summary: `${[item.minute,item.hour,item.day,item.month,item.weekday].join(' ')}\n${item.command}`, perform: async confirmation => { await this.api.request(this.hostPath(`/cron/${line}`), {method: 'DELETE', body: {existing, confirmation}}); await this.render(); }}); }

  fullBackup() {
    this.openForm('confirm', `<div class="notice warning"><strong>${escapeHtml(t('warning'))}</strong>${escapeHtml(t('warningFullBackup'))}</div><pre class="code">${escapeHtml(t('fullBackup'))}</pre>`, 'confirm', async () => {
      const result = await this.api.request(this.hostPath('/backups/full'), {method: 'POST', body: {}});
      this.closeDialog();
      await this.pageBackups();
      this.toast(t(result.ambiguous ? 'fullBackupAmbiguous' : result.pending ? 'fullBackupPending' : 'success'));
      this.haptic(result.pending ? 'selection' : 'success');
    });
  }

  databaseBackup() {
    this.openForm('databaseBackup', `<div class="notice"><strong>${escapeHtml(t('info'))}</strong>${escapeHtml(t('backupLiveProgress'))}</div><div class="field"><label>${escapeHtml(t('databaseName'))}</label><input name="database" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('tables'))}</label><input name="tables" dir="ltr"><span class="hint">${escapeHtml(t('commaTablesHint'))}</span></div><div id="job-progress"></div>`, 'create', async form => {
      const result = await this.api.request(this.hostPath('/backups/database'), {method: 'POST', body: {database: form.elements.database.value, tables: form.elements.tables.value.split(',').map(value => value.trim()).filter(Boolean)}});
      $('#dialog-body').innerHTML = `<div class="notice"><strong>${escapeHtml(t('databaseBackup'))}</strong>${escapeHtml(t('backupLiveProgress'))}</div><div id="job-progress">${this.progressHtml(0, t('queued'))}</div>`;
      $('#dialog-actions').innerHTML = '';
      this.dialogSubmit = null;
      await this.pollJob(Number(result.job_id), false);
      this.closeDialog();
      await this.pageBackups();
      this.toast(t('success'));
    });
  }

  directoryBackup() {
    const root = String(this.activeHost()?.root_path || '.').replace(/\/$/, '');
    const suggested = root === '.' ? './public_html' : `${root}/public_html`;
    this.openForm('directoryBackup', `<div class="notice"><strong>${escapeHtml(t('info'))}</strong>${escapeHtml(t('directoryBackupSourceHint'))}</div><div class="field"><label>${escapeHtml(t('source'))}</label><input name="directory" value="${escapeAttr(suggested)}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('destination'))}</label><input name="destination" dir="ltr"><span class="hint">${escapeHtml(t('optionalBackupPath'))}</span></div><div id="job-progress"></div>`, 'create', async form => {
      const result = await this.api.request(this.hostPath('/backups/directory'), {method: 'POST', body: {directory: form.elements.directory.value, destination: form.elements.destination.value.trim() || null}});
      $('#dialog-body').innerHTML = `<div class="notice"><strong>${escapeHtml(t('directoryBackup'))}</strong>${escapeHtml(t('backupLiveProgress'))}</div><div id="job-progress">${this.progressHtml(0, t('queued'))}</div>`;
      $('#dialog-actions').innerHTML = '';
      this.dialogSubmit = null;
      await this.pollJob(Number(result.job_id), false);
      this.closeDialog();
      await this.pageBackups();
      this.toast(t('success'));
    });
  }

  async followBackupJob(jobId) {
    if (!Number.isInteger(jobId) || jobId < 1) throw new ApiError({code: 'invalid_job', message: t('failed')}, 422);
    this.openInfo('trackProgress', `<div class="notice"><strong>${escapeHtml(t('info'))}</strong>${escapeHtml(t('backupLiveProgress'))}</div><div id="job-progress">${this.progressHtml(0, t('queued'))}</div>`);
    await this.pollJob(jobId, false);
    this.closeDialog();
    await this.pageBackups();
    this.toast(t('success'));
  }

  async downloadBackup(kind, id) {
    const item = this.backupItem(kind, id);
    if (!item?.actions?.download) throw new ApiError({code: 'backup_download_unavailable', message: t('failed'), help_slug: 'backup.overview'}, 409);
    if (kind === 'file_version') {
      const result = await this.api.request(this.hostPath(`/backups/file/${id}/download`));
      await this.downloadWhenReady(result.download);
      return;
    }
    if (String(item.provider_ref || '').startsWith('local:')) {
      await this.api.download(this.hostPath(`/backups/${id}/download`), String(item.provider_ref).slice(6));
      return;
    }
    const result = await this.api.request(this.hostPath(`/backups/${id}/download`));
    await this.downloadWhenReady(result.download);
  }

  restoreBackup(kind, id) {
    const item = this.backupItem(kind, id);
    if (!item?.actions?.restore) throw new ApiError({code: 'backup_not_restorable', message: t('failed'), help_slug: 'backup.overview'}, 409);
    const restoreKind = String(item.actions.restore_kind || item.type || '');
    const warningKey = {file: 'backupRestoreFileWarning', directory: 'backupRestoreDirectoryWarning', database: 'backupRestoreDatabaseWarning', deployment: 'backupRestoreDeploymentWarning'}[restoreKind] || 'warningBackupRestore';
    if (kind === 'file_version') {
      return this.confirmOperation({
        warningKey,
        action: 'backup.restore',
        target: `file:${id}`,
        summary: `${t('file')}: ${item.target}\n${t('backupVersion')}: #${Number(item.metadata?.version_number || 0)}`,
        preview: {version_id: id, target: item.target},
        perform: async confirmation => {
          await this.api.request(this.hostPath(`/backups/file/${id}/restore`), {method: 'POST', body: {confirmation}});
          await this.pageBackups();
        },
      });
    }
    if (restoreKind === 'deployment') {
      return this.confirmOperation({
        warningKey,
        action: 'backup.restore',
        target: `${id}:`,
        summary: `${t('deploy')} #${Number(item.metadata?.deployment_id || 0)}\n${t('destination')}: ${item.target || '—'}`,
        preview: {backup_id: id, deployment_id: Number(item.metadata?.deployment_id || 0)},
        closeOnSuccess: false,
        perform: async confirmation => {
          this.dialogSubmit = null;
          const result = await this.api.request(this.hostPath(`/backups/${id}/restore`), {method: 'POST', body: {destination: '', confirmation}});
          $('#dialog-actions').innerHTML = '';
          await this.pollDeployment(Number(result.job_id), Number(result.deployment_id), true);
          await this.pageBackups();
          return false;
        },
      });
    }
    const source = String(item.target || '');
    const defaultDestination = restoreKind === 'directory' ? (source.replace(/\/+$/, '').replace(/\/[^/]+$/, '') || '/') : source;
    const extra = restoreKind === 'database' ? `<div class="notice warning">${escapeHtml(t('backupRestorePreBackup'))}</div>` : '';
    this.openForm('restore', `<div class="notice danger"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t(warningKey))}</div>${extra}<div class="field"><label>${escapeHtml(t('destination'))}</label><input name="destination" value="${escapeAttr(defaultDestination)}" required dir="ltr"><span class="hint">${escapeHtml(t('restoreDestinationHint'))}</span></div>`, 'restore', async form => {
      const destination = form.elements.destination.value.trim();
      const target = `${id}:${destination}`;
      const confirmation = await this.api.request('/api/v1/confirmations', {method: 'POST', body: {account_id: this.hostId, action: 'backup.restore', target, preview: {backup_id: id, type: restoreKind, destination, pre_restore_backup: restoreKind === 'database'}}});
      const result = await this.api.request(this.hostPath(`/backups/${id}/restore`), {method: 'POST', body: {destination, confirmation: confirmation.nonce}});
      $('#dialog-body').innerHTML = `<div class="notice"><strong>${escapeHtml(t('restore'))}</strong>${escapeHtml(t('backupLiveProgress'))}</div><div id="job-progress">${this.progressHtml(0, t('queued'))}</div>`;
      $('#dialog-actions').innerHTML = '';
      this.dialogSubmit = null;
      await this.pollJob(Number(result.job_id), false);
      this.closeDialog();
      await this.pageBackups();
      this.toast(t('success'));
    }, true);
  }

  deleteBackup(kind, id) {
    const item = this.backupItem(kind, id);
    if (!item?.actions?.delete) throw new ApiError({code: 'backup_operation_active', message: t('failed'), help_slug: 'backup.overview'}, 409);
    const target = kind === 'file_version' ? `file:${id}` : String(id);
    const path = kind === 'file_version' ? `/backups/file/${id}` : `/backups/${id}`;
    this.openForm('remove', `<div class="notice danger"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningBackupDelete'))}</div><pre class="code">${escapeHtml(`${this.backupTypeText(item.type)}: ${item.target}`)}</pre><p class="hint">${escapeHtml(t('backupDeleteRecordHint'))}</p><label class="check"><input name="delete_remote" type="checkbox">${escapeHtml(t('deleteRemoteArchive'))}</label>`, 'remove', async form => {
      const deleteRemote = form.elements.delete_remote.checked;
      if (deleteRemote && (kind === 'file_version' || ['directory', 'deployment', 'full'].includes(String(item.type)))) this.assertWritableCapabilities('files');
      const confirmation = await this.api.request('/api/v1/confirmations', {method: 'POST', body: {account_id: this.hostId, action: 'backup.delete', target, preview: {backup_id: id, type: item.type, delete_remote: deleteRemote}}});
      await this.api.request(this.hostPath(path), {method: 'DELETE', body: {delete_remote: deleteRemote, confirmation: confirmation.nonce}});
      this.closeDialog();
      await this.pageBackups();
      this.toast(t('success'));
      this.haptic('success');
    }, true);
  }

  deploymentEventMetadata(event) {
    if (event?.metadata && typeof event.metadata === 'object' && !Array.isArray(event.metadata)) return event.metadata;
    if (typeof event?.metadata_json !== 'string' || event.metadata_json === '') return {};
    try { const parsed = JSON.parse(event.metadata_json); return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}; } catch { return {}; }
  }

  deploymentWizardMarkup(deployment = null, draft = {}) {
    const events = Array.isArray(deployment?.events) ? deployment.events : [];
    const latest = new Map();
    for (const event of events) latest.set(String(event.stage || ''), event);
    const eventState = stage => {
      const event = latest.get(stage);
      if (!event) return {state: 'pending', label: t('stepPending')};
      const status = String(event.status || '');
      const state = status === 'completed' ? 'completed' : status === 'skipped' ? 'skipped' : status === 'failed' ? 'failed' : ['running', 'queued'].includes(status) ? 'running' : 'pending';
      return {state, label: statusText(status)};
    };
    const selected = Boolean(deployment || draft.package);
    const destination = Boolean(deployment || draft.destination);
    const validated = draft.validated ? {state: 'completed', label: statusText('validated')} : eventState('validate');
    let complete = eventState('complete');
    if (complete.state === 'pending' && deployment && ['failed', 'rolled_back', 'rollback_failed', 'reconciliation_required'].includes(String(deployment.status))) {
      complete = {state: 'failed', label: statusText(deployment.status)};
    }
    const steps = [
      ['deployStepPackage', selected ? {state: 'completed', label: t('stepSelected')} : {state: 'running', label: t('stepPending')}],
      ['deployStepDestination', destination ? {state: 'completed', label: t('stepSelected')} : {state: selected ? 'running' : 'pending', label: t('stepPending')}],
      ['deployStepValidate', validated],
      ['deployStepBackup', eventState('backup')],
      ['deployStepExtract', eventState('extract')],
      ['deployStepDeploy', eventState('deploy')],
      ['deployStepHealth', eventState('health')],
      ['deployStepComplete', complete],
    ];
    return `<ol class="deploy-steps" aria-label="${escapeAttr(t('timeline'))}">${steps.map(([key, status], index) => `<li class="deploy-step ${escapeAttr(status.state)}" data-state="${escapeAttr(status.state)}" ${status.state === 'running' ? 'aria-current="step"' : ''}><span class="deploy-step-index">${index + 1}</span><span><strong>${escapeHtml(t(key))}</strong><small>${escapeHtml(status.label)}</small></span></li>`).join('')}</ol>`;
  }

  renderDeploymentDraftSteps(form) {
    const root = $('#deployment-draft-steps', form);
    if (!root) return;
    root.innerHTML = this.deploymentWizardMarkup(null, {package: Boolean(form.elements.file.files[0]), destination: form.elements.destination.value.trim() !== ''});
  }

  deploymentDetailsMarkup(deployment, job = null) {
    const events = Array.isArray(deployment.events) ? deployment.events : [];
    const packageMetadata = deployment.package_metadata && typeof deployment.package_metadata === 'object' ? deployment.package_metadata : {};
    const notices = [
      deployment.status === 'rolled_back' ? `<div class="notice warning"><strong>${escapeHtml(statusText('rolled_back'))}</strong>${escapeHtml(t(deployment.rollback_job_id ? 'deploymentManualRollback' : 'deploymentSafeRollback'))}</div>` : '',
      deployment.requires_attention ? `<div class="notice danger"><strong>${escapeHtml(t('needsAttention'))}</strong>${escapeHtml(t('deploymentAttention'))}</div>` : '',
      deployment.status === 'failed' ? `<div class="notice danger"><strong>${escapeHtml(statusText('failed'))}</strong>${escapeHtml(t('deploymentFailureHelp'))}</div>` : '',
    ].join('');
    const jobProgress = job ? `<div id="job-progress">${this.progressHtml(Number(job.progress || 0), `${statusText(job.status)} · ${Number(job.progress || 0)}%`)}</div>` : '';
    const eventRows = events.map(event => {
      const messageKey = String(event.message_key || '');
      const translated = t(messageKey);
      const message = translated === messageKey ? `${stageText(event.stage)} · ${statusText(event.status)}` : translated;
      const metadata = this.deploymentEventMetadata(event);
      const details = Object.keys(metadata).length ? `<details class="advanced-only event-metadata"><summary>${escapeHtml(t('eventDetails'))}</summary><pre class="code">${escapeHtml(formatJson(metadata))}</pre></details>` : '';
      return `<article class="timeline-item state-${escapeAttr(String(event.status || 'pending'))}"><div class="timeline-heading"><strong>${escapeHtml(stageText(event.stage))}</strong><span class="badge ${this.deploymentBadgeClass(String(event.status || ''))}">${escapeHtml(statusText(event.status))}</span></div><p>${escapeHtml(message)}</p><small>${escapeHtml(dateText(event.created_at))}</small>${details}</article>`;
    }).join('');
    const technical = {switch_state: deployment.switch_state, stage_path: deployment.stage_path, backup_ref: deployment.backup_ref, rollback_path: deployment.rollback_path, reconciliation: deployment.reconciliation || {}, recovery_attempts: Number(deployment.recovery_attempts || 0)};
    const rollback = deployment.rollback_available ? `<button class="button warning" data-action="deployment-rollback" data-id="${Number(deployment.id)}">${escapeHtml(t('rollback'))}</button>` : '';
    return `${notices}${jobProgress}<section class="deployment-summary card"><div class="page-head"><div><h3>${escapeHtml(deployment.package_name)}</h3><div class="badge-row"><span class="badge ${this.deploymentBadgeClass(deployment.status)}">${escapeHtml(statusText(deployment.status))}</span>${deployment.is_current ? `<span class="badge ok">${escapeHtml(t('currentRelease'))}</span>` : ''}</div></div><small>#${Number(deployment.id)}</small></div><dl class="key-values"><dt>${escapeHtml(t('destination'))}</dt><dd dir="ltr">${escapeHtml(deployment.destination)}</dd><dt>${escapeHtml(t('filesCount'))}</dt><dd>${Number(packageMetadata.files || 0) || '—'}</dd><dt>${escapeHtml(t('backupSize'))}</dt><dd>${deployment.backup_size ? escapeHtml(bytes(deployment.backup_size)) : '—'}</dd><dt>${escapeHtml(t('healthStatus'))}</dt><dd>${deployment.health_status !== null && deployment.health_status !== undefined ? `HTTP ${Number(deployment.health_status)}` : (deployment.health_check_url ? '—' : escapeHtml(t('stepSkipped')))}</dd><dt>${escapeHtml(t('createdAt'))}</dt><dd>${escapeHtml(dateText(deployment.created_at))}</dd><dt>${escapeHtml(t('completedAt'))}</dt><dd>${escapeHtml(dateText(deployment.completed_at || deployment.rolled_back_at))}</dd><dt>${escapeHtml(t('errorCode'))}</dt><dd dir="ltr">${escapeHtml(deployment.error_code || '—')}</dd></dl>${rollback}</section><div class="notice"><strong>${escapeHtml(t('timeline'))}</strong>${escapeHtml(t('deploymentLiveTracking'))}</div>${this.deploymentWizardMarkup(deployment)}<h3>${escapeHtml(t('timeline'))}</h3><div class="timeline">${eventRows || `<p class="muted">${escapeHtml(t('stepPending'))}</p>`}</div><details class="advanced-only"><summary>${escapeHtml(t('technicalDetails'))}</summary><pre class="code">${escapeHtml(formatJson(technical))}</pre></details>`;
  }

  renderDeploymentDialog(deployment, job = null) {
    $('#dialog-title').textContent = `${t('timeline')} · #${Number(deployment.id)}`;
    $('#dialog-body').innerHTML = this.deploymentDetailsMarkup(deployment, job);
    $('#dialog-actions').innerHTML = `<button type="button" class="button" data-dialog-close>${escapeHtml(t('close'))}</button>`;
    this.dialogSubmit = null;
    this.applyCapabilityPolicy(this.dialog);
  }

  async pollDeployment(jobId, deploymentId, rollbackOperation = false) {
    let job;
    let deployment;
    for (;;) {
      [job, deployment] = await Promise.all([
        this.api.request(`/api/v1/jobs/${jobId}`),
        this.api.request(this.hostPath(`/deployments/${deploymentId}`)),
      ]);
      this.renderDeploymentDialog(deployment, job);
      if (['completed', 'failed'].includes(job.status)) break;
      await new Promise(resolve => setTimeout(resolve, 1500));
    }
    if (this.route === 'deploy') await this.pageDeploy();
    if (job.status === 'failed' && deployment.status !== 'rolled_back') {
      throw new ApiError({code: deployment.error_code || job.last_error_code || 'deployment_failed', message: t('deploymentFailureHelp'), help_slug: 'deploy.rollback'}, 422);
    }
    if (job.status === 'failed') {
      this.toast(t('deploymentSafeRollback'));
      this.haptic('selection');
    } else {
      this.toast(rollbackOperation ? t('deployment.rollback_completed') : t('success'));
      this.haptic('success');
    }
    return deployment;
  }

  deploymentPackage() {
    const root = this.activeHost()?.root_path || '';
    this.openForm('startDeploy', `<div class="notice danger"><strong>${escapeHtml(t('danger'))}</strong>${escapeHtml(t('warningDeploy'))}</div><div id="deployment-draft-steps">${this.deploymentWizardMarkup()}</div><div class="field"><label>${escapeHtml(t('package'))}</label><input name="file" type="file" accept=".zip,application/zip" required></div><div class="field"><label>${escapeHtml(t('destination'))}</label><input name="destination" value="${escapeAttr(`${root}/public_html`)}" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('healthUrl'))}</label><input name="health_url" type="url" value="${escapeAttr(this.activeHost()?.main_domain ? `https://${this.activeHost().main_domain}/` : '')}" pattern="https://.*" dir="ltr"></div><div id="job-progress"></div>`, 'validatePackage', async form => {
      const file = form.elements.file.files[0];
      if (!file) return;
      const destination = form.elements.destination.value.trim();
      const healthUrl = form.elements.health_url.value.trim() || null;
      const payload = new FormData();
      payload.append('file', file, file.name);
      this.updateProgress(0, t('uploadProgress', {percent: 0}));
      const packageResult = await this.api.upload(this.hostPath('/deployment-packages'), payload, percent => this.updateProgress(percent, t('uploadProgress', {percent})));
      this.closeDialog();
      const target = `${packageResult.id}:${destination}`;
      const metadata = packageResult.metadata || {};
      await this.confirmOperation({
        warningKey: 'warningDeploy',
        action: 'deployment.run',
        target,
        summary: `${t('package')}: ${packageResult.name}\n${t('destination')}: ${destination}\n${t('filesCount')}: ${metadata.files ?? '—'}\n${t('compressedSize')}: ${bytes(metadata.compressed_bytes)}\n${t('uncompressedSize')}: ${bytes(metadata.uncompressed_bytes)}\n${t('backupState')}: ${t('on')}\n${t('healthState')}: ${healthUrl || t('off')}`,
        preview: {package: packageResult.name, destination, files: metadata.files, backup: true, health_check: Boolean(healthUrl)},
        details: this.deploymentWizardMarkup(null, {package: true, destination: true, validated: true}),
        closeOnSuccess: false,
        perform: async confirmation => {
          this.dialogSubmit = null;
          const deployment = await this.api.request(this.hostPath('/deployments'), {method: 'POST', body: {package_id: packageResult.id, destination, health_check_url: healthUrl, confirmation}});
          $('#dialog-actions').innerHTML = '';
          await this.pollDeployment(deployment.job_id, deployment.deployment_id, false);
          return false;
        },
      });
    });
    const form = this.dialogForm;
    form.elements.file.addEventListener('change', () => this.renderDeploymentDraftSteps(form));
    form.elements.destination.addEventListener('input', () => this.renderDeploymentDraftSteps(form));
    this.renderDeploymentDraftSteps(form);
  }

  async deploymentDetails(id, replaceDialog = false) {
    const deployment = await this.api.request(this.hostPath(`/deployments/${id}`));
    if (replaceDialog) this.renderDeploymentDialog(deployment);
    else this.openInfo('timeline', this.deploymentDetailsMarkup(deployment));
  }

  rollbackDeployment(id) {
    const deployment = this.deployments.find(item => Number(item.id) === id);
    const summary = `${t('deploy')} #${id}\n${t('package')}: ${deployment?.package_name || '—'}\n${t('destination')}: ${deployment?.destination || '—'}\n${t('rollbackPoints')}: ${this.rollbackKindText(deployment?.rollback_kind)}`;
    return this.confirmOperation({
      warningKey: 'warningRollback',
      action: 'deployment.rollback',
      target: String(id),
      summary,
      preview: {deployment_id: id, destination: deployment?.destination || null, rollback_kind: deployment?.rollback_kind || null},
      closeOnSuccess: false,
      perform: async confirmation => {
        this.dialogSubmit = null;
        const result = await this.api.request(this.hostPath(`/deployments/${id}/rollback`), {method: 'POST', body: {confirmation}});
        $('#dialog-actions').innerHTML = '';
        await this.pollDeployment(result.job_id, id, true);
        return false;
      },
    });
  }

  customLog() { this.openForm('logs', `<div class="field"><label>${escapeHtml(t('path'))}</label><input name="path" required dir="ltr"></div><div class="field"><label>${escapeHtml(t('tailLines'))}</label><select name="lines"><option>50</option><option selected>100</option></select></div><div class="field"><label>${escapeHtml(t('search'))}</label><input name="q"></div>`, 'open', async form => { const path = form.elements.path.value, lines = form.elements.lines.value, q = form.elements.q.value; this.closeDialog(); await this.openLog(path, lines, q); }); }
  async openLog(path, lines = 100, q = '') { const result = await this.api.request(query(this.hostPath('/logs/tail'), {path, lines, q})); const output = $('#log-output'); const html = `<article class="card"><h2>${escapeHtml(result.path)}</h2>${result.truncated ? `<div class="notice warning">${escapeHtml(t('warning'))}: ${escapeHtml(t('maxSafeLogReached'))}</div>` : ''}<pre class="code">${escapeHtml((result.lines || []).join('\n'))}</pre></article>`; if (output) { output.innerHTML = html; output.scrollIntoView({behavior: 'smooth'}); } else this.openInfo('logs', html); }
  changePhpVersion() { const vhosts = this.namedObjects(this.phpData?.vhosts, 'domain').map(item => item.vhost || item.domain || item.name).filter(Boolean), versions = asList(this.phpData?.installed_versions, ['versions', 'items']).map(item => typeof item === 'string' ? item : item.version || item.name).filter(Boolean); this.openForm('phpVersion', `<div class="notice warning"><strong>${escapeHtml(t('warning'))}</strong>${escapeHtml(t('phpVersionWarning'))}</div><div class="field"><label>${escapeHtml(t('phpVersion'))}</label><select name="version">${versions.map(version => `<option>${escapeHtml(version)}</option>`).join('')}<option value="inherit">inherit</option></select></div><div class="list">${vhosts.map(host => `<label class="check"><input name="vhosts" type="checkbox" value="${escapeAttr(host)}">${escapeHtml(host)}</label>`).join('')}</div>`, 'save', async form => { const selected = $$('input[name="vhosts"]:checked', form).map(input => input.value), version = form.elements.version.value, target = `${version}:${selected.join(',')}`; this.closeDialog(); await this.confirmOperation({warningKey: 'warningFileDelete', action: 'php.version', target, summary: `${version}\n${selected.join('\n')}`, perform: async confirmation => { await this.api.request(this.hostPath('/php/version'), {method: 'PUT', body: {vhosts: selected, version, confirmation}}); await this.render(); }}); }); }
  changePhpIni() { const defaults = {memory_limit: '256M', max_execution_time: '30', upload_max_filesize: '20M', post_max_size: '24M'}, defaultVhost = this.activeHost()?.main_domain || ''; this.openForm('iniDirectives', `<div class="notice warning"><strong>${escapeHtml(t('warning'))}</strong>${escapeHtml(t('phpIniWarning'))}</div><div class="field"><label>${escapeHtml(t('scope'))}</label><select name="type"><option value="home">home</option><option value="vhost">vhost</option></select></div><div class="field"><label>${escapeHtml(t('domain'))} / vhost</label><input name="vhost" value="${escapeAttr(defaultVhost)}" dir="ltr"><span class="hint">${escapeHtml(t('vhostHint'))}</span></div><div class="field"><label>${escapeHtml(t('iniDirectives'))} (JSON)</label><textarea name="directives" rows="8" dir="ltr">${escapeHtml(formatJson(defaults))}</textarea></div>`, 'save', async form => { const type = form.elements.type.value, vhost = type === 'vhost' ? form.elements.vhost.value.trim().toLowerCase() : '', directives = this.parseJson(form.elements.directives.value, 'ini_directives'), signature = Object.keys(directives).sort().map(key => `${key}\0${String(directives[key])}\n`).join(''), target = `${type}:${vhost}:${await this.sha256(signature)}`; this.closeDialog(); await this.confirmOperation({warningKey: 'warningFileDelete', action: 'php.ini', target, summary: `${type}${vhost ? `: ${vhost}` : ''}\n${formatJson(directives)}`, preview: {directives: Object.keys(directives), vhost}, perform: async confirmation => { await this.api.request(this.hostPath('/php/ini'), {method: 'PATCH', body: {type, vhost: vhost || null, directives, confirmation}}); await this.render(); }}); }); }

  async revokeSession(id) { await this.api.request(`/api/v1/security/sessions/${id}`, {method: 'DELETE', body: {}}); if (id === Number(this.currentSessionId)) this.sessionLost(); else await this.render(); }
  async ackSecurity(id) { await this.api.request(`/api/v1/security/events/${id}/acknowledge`, {method: 'POST', body: {}}); await this.render(); }
  async changeLanguage(value) { await this.api.request('/api/v1/settings', {method: 'PATCH', body: {language: value}}); this.user.language = value; setLanguage(value); await this.refreshDashboard(false); await this.render(); }
  async changeMode(mode) { await this.api.request('/api/v1/settings', {method: 'PATCH', body: {ux_mode: mode}}); this.user.ux_mode = mode; document.body.classList.toggle('advanced', mode === 'advanced'); await this.render(); }
  logout() { return this.confirmClient('warningToken', t('logout'), async () => { try { await this.api.request('/api/v1/auth/logout', {method: 'POST', body: {}}); } finally { this.api.clearSession(); this.sessionLost(); } }); }

  async adminUserStatus(id, status) { await this.api.request(`/api/v1/admin/users/${id}/status`, {method: 'PATCH', body: {status}}); await this.render(); }
  async adminUserPlan(id) { const plans = (await this.api.request('/api/v1/admin/plans')).plans || []; this.openForm('assignPlan', `<div class="field"><label>${escapeHtml(t('plan'))}</label><select name="plan">${plans.map(plan => `<option value="${escapeAttr(plan.slug)}">${escapeHtml(plan[language() === 'fa' ? 'name_fa' : 'name_en'])}</option>`).join('')}</select></div><div class="field"><label>${escapeHtml(t('endsAtOptional'))}</label><input name="ends_at" type="datetime-local"></div>`, 'save', async form => { await this.api.request(`/api/v1/admin/users/${id}/plan`, {method: 'PUT', body: {plan: form.elements.plan.value, ends_at: form.elements.ends_at.value ? new Date(form.elements.ends_at.value).toISOString().slice(0, 19).replace('T', ' ') : null}}); this.closeDialog(); await this.render(); }); }
  adminPlanEdit(index) { const plan = this.adminPlans[index]; this.openForm('plans', `<div class="field"><label>${escapeHtml(t('persianName'))}</label><input name="name_fa" value="${escapeAttr(plan.name_fa)}" required></div><div class="field"><label>${escapeHtml(t('englishName'))}</label><input name="name_en" value="${escapeAttr(plan.name_en)}" required></div>${[['host_limit', plan.host_limit], ['max_upload_bytes', plan.max_upload_bytes], ['daily_operation_limit', plan.daily_operation_limit]].map(([name, value]) => `<div class="field"><label>${escapeHtml(name)}</label><input name="${name}" type="number" min="0" value="${Number(value)}"></div>`).join('')}${[['database_manager', plan.database_manager], ['sql_console', plan.sql_console], ['backup_enabled', plan.backup_enabled], ['deployment_enabled', plan.deployment_enabled]].map(([name, value]) => `<label class="check"><input name="${name}" type="checkbox" ${Number(value) ? 'checked' : ''}>${escapeHtml(name)}</label>`).join('')}`, 'save', async form => { const body = {name_fa: form.elements.name_fa.value, name_en: form.elements.name_en.value, host_limit: Number(form.elements.host_limit.value), max_upload_bytes: Number(form.elements.max_upload_bytes.value), daily_operation_limit: Number(form.elements.daily_operation_limit.value), database_manager: form.elements.database_manager.checked ? 1 : 0, sql_console: form.elements.sql_console.checked ? 1 : 0, backup_enabled: form.elements.backup_enabled.checked ? 1 : 0, deployment_enabled: form.elements.deployment_enabled.checked ? 1 : 0}; await this.api.request(`/api/v1/admin/plans/${encodeURIComponent(plan.slug)}`, {method: 'PATCH', body}); this.closeDialog(); await this.render(); }); }
  adminBroadcast() { this.openForm('broadcasts', `<div class="field"><label>${escapeHtml(t('messageFa'))}</label><textarea name="message_fa" maxlength="4096" required dir="rtl"></textarea></div><div class="field"><label>${escapeHtml(t('messageEn'))}</label><textarea name="message_en" maxlength="4096" required dir="ltr"></textarea></div><div class="field"><label>${escapeHtml(t('language'))}</label><select name="language"><option value="">${escapeHtml(t('all'))}</option><option value="fa">فارسی</option><option value="en">English</option></select></div><div class="field"><label>${escapeHtml(t('plan'))}</label><input name="plan" dir="ltr"><span class="hint">${escapeHtml(t('optionalPlanSlug'))}</span></div>`, 'sendBroadcast', async form => { const filter = {}; if (form.elements.language.value) filter.language = form.elements.language.value; if (form.elements.plan.value) filter.plan = form.elements.plan.value; await this.api.request('/api/v1/admin/broadcasts', {method: 'POST', body: {message_fa: form.elements.message_fa.value, message_en: form.elements.message_en.value, filter}}); this.closeDialog(); await this.render(); }); }
  async adminRetryJob(id) { await this.api.request(`/api/v1/admin/failed-jobs/${id}/retry`, {method: 'POST', body: {}}); await this.render(); }
  async saveAdminSettings(form) { const body = Object.fromEntries([...new FormData(form)].map(([key, value]) => [key, Number(value)])); await this.api.request('/api/v1/admin/settings', {method: 'PATCH', body}); this.toast(t('success')); await this.render(); }
  async saveMaintenance(form) { await this.api.request('/api/v1/admin/maintenance', {method: 'PUT', body: {enabled: form.elements.enabled.checked, message_fa: form.elements.message_fa.value, message_en: form.elements.message_en.value}}); this.toast(t('success')); await this.render(); }
  showJson(value) { this.openInfo('details', `<pre class="code">${escapeHtml(formatJson(value))}</pre>`); }

  parseJson(value, code) { try { return JSON.parse(value); } catch { throw new ApiError({code: `invalid_${code}`, message: t('invalidJson')}, 422); } }
  cached(key) { const item = this.cache.get(key); if (!item || item.expires < Date.now()) { this.cache.delete(key); return null; } return item.value; }
  remember(key, value, milliseconds) { this.cache.set(key, {value, expires: Date.now() + milliseconds}); }
  haptic(type = 'selection') { try { if (type === 'success') tg?.HapticFeedback?.notificationOccurred?.('success'); else tg?.HapticFeedback?.selectionChanged?.(); } catch {} }
  toast(message, error = false) { const item = document.createElement('div'); item.className = `toast ${error ? 'error' : ''}`; item.textContent = message; $('#toast-region').appendChild(item); setTimeout(() => item.remove(), 3500); }

  openContextHelp() { const map = {dashboard: 'start.overview', files: 'files.browse', editor: 'files.edit', databases: 'database.overview', database: 'database.tables', table: 'database.rows', sql: 'database.sql', domains: 'domains.overview', email: 'email.overview', ssl: 'ssl.overview', cron: 'cron.overview', backups: 'backup.overview', deploy: 'deploy.start', usage: 'usage.overview', logs: 'logs.overview', php: 'php.overview', security: 'security.token', settings: 'settings.overview', admin: 'admin.overview', help: 'help.using'}; this.navigate('help', {slug: map[this.route] || 'start.overview'}); }
  operationError(error) { this.haptic('error'); if (error?.name === 'AbortError') return; const apiError = error instanceof ApiError ? error : new ApiError({code: 'client_error', message: error?.message || t('clientError')}, 500); const guidance = apiError.guidance?.[language()] || {}; this.openInfo('errorTitle', `<div class="error-card"><h2>${escapeHtml(guidance.title || t('errorTitle'))}</h2>${guidance.causes?.length ? `<h3>${escapeHtml(t('likelyCauses'))}</h3><ul>${guidance.causes.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}${guidance.actions?.length ? `<h3>${escapeHtml(t('suggestedActions'))}</h3><ul>${guidance.actions.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : `<p>${escapeHtml(t('emptyGuidance'))}</p>`}<p class="muted">${escapeHtml(apiError.code)}${apiError.requestId ? ` · ${escapeHtml(t('requestId'))}: ${escapeHtml(apiError.requestId)}` : ''}</p>${apiError.helpSlug ? `<button class="button secondary" data-route="help" data-slug="${escapeAttr(apiError.helpSlug)}">${escapeHtml(t('help'))}</button>` : ''}</div>`); }
  renderError(error, retry = null) { if (error?.name === 'AbortError') return; const apiError = error instanceof ApiError ? error : new ApiError({code: 'client_error', message: error?.message || ''}, 500), guidance = apiError.guidance?.[language()] || {}; this.content.innerHTML = `<section class="error-card"><h2>${escapeHtml(guidance.title || t('errorTitle'))}</h2><p>${escapeHtml(apiError.message)}</p>${guidance.causes?.length ? `<ul>${guidance.causes.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}<div class="toolbar">${retry ? `<button class="button" id="retry-button">${escapeHtml(t('retry'))}</button>` : ''}${apiError.helpSlug ? `<button class="button secondary" data-route="help" data-slug="${escapeAttr(apiError.helpSlug)}">${escapeHtml(t('help'))}</button>` : ''}</div><small>${escapeHtml(apiError.code)} · ${escapeHtml(apiError.requestId || '')}</small></section>`; if (retry) $('#retry-button').onclick = retry; }
  renderFatal(error) { this.setTitle('app'); const outside = error?.code === 'telegram_context_required'; this.content.innerHTML = `<section class="error-card"><h2>${escapeHtml(outside ? t('authOutside') : t('authExpired'))}</h2><p>${escapeHtml(error?.message || t('emptyGuidance'))}</p><div class="notice danger"><strong>فارسی / English</strong>${escapeHtml(t('warningToken'))}</div></section>`; document.querySelector('#app')?.setAttribute('aria-busy', 'false'); }
  sessionLost() { clearTimeout(this.rotationTimer); this.api.clearSession(); this.hideMainButton(); this.renderFatal(new ApiError({code: 'session_expired', message: t('authExpired')}, 401)); }
}

const app = new ManagerApp();
app.boot();
