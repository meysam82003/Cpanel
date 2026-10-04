function readConfig() {
  try {
    const element = document.getElementById('tcpm-config');
    const parsed = element ? JSON.parse(element.textContent || '{}') : {};
    if (parsed && typeof parsed === 'object') return parsed;
  } catch {}
  return {};
}

// Runtime configuration injected by the server: the install folder and the
// routing style (pretty / pathinfo / query) are never guessed by the client.
export const CONFIG = (() => {
  const config = readConfig();
  const pathname = String(window.location.pathname || '/');
  const marker = pathname.indexOf('/miniapp');
  const fallbackBase = marker > 0 ? pathname.slice(0, marker) : '';
  const base = typeof config.base === 'string' ? config.base.replace(/\/+$/, '') : fallbackBase;
  const mode = ['pretty', 'pathinfo', 'query'].includes(config.mode) ? config.mode : 'pretty';
  return {
    base,
    entry: typeof config.entry === 'string' && config.entry ? config.entry : `${base}/index.php`,
    mode,
    version: String(config.version || ''),
    bot: String(config.bot || ''),
    https: config.https !== false,
  };
})();

/** Resolves an application route (e.g. /api/v1/hosts?x=1) to a URL for the current routing mode. */
export function appUrl(path) {
  if (typeof path !== 'string' || !path.startsWith('/')) return path;
  const index = path.indexOf('?');
  const route = index < 0 ? path : path.slice(0, index);
  const search = index < 0 ? '' : path.slice(index + 1);
  if (CONFIG.mode === 'query') return `${CONFIG.entry}?r=${encodeURIComponent(route)}${search ? `&${search}` : ''}`;
  if (CONFIG.mode === 'pathinfo') return `${CONFIG.entry}${route}${search ? `?${search}` : ''}`;
  return `${CONFIG.base}${route}${search ? `?${search}` : ''}`;
}

const resolveAppPath = appUrl;

export class ApiError extends Error {
  constructor(error = {}, status = 500) {
    super(error.message || error.message_en || 'Request failed');
    this.name = 'ApiError';
    this.status = status;
    this.code = error.code || 'request_failed';
    this.requestId = error.request_id || null;
    this.detail = error.detail || null;
    this.helpSlug = error.help_slug || null;
    this.context = error.context || {};
    this.guidance = error.guidance || {};
  }
}

export class Api {
  constructor(onAuthLost = () => {}) {
    this.token = sessionStorage.getItem('tcpm.session') || '';
    this.csrf = sessionStorage.getItem('tcpm.csrf') || '';
    this.onAuthLost = onAuthLost;
  }

  setSession(session) {
    this.token = String(session?.token || '');
    this.csrf = String(session?.csrf || '');
    sessionStorage.setItem('tcpm.session', this.token);
    sessionStorage.setItem('tcpm.csrf', this.csrf);
  }

  clearSession() {
    this.token = '';
    this.csrf = '';
    sessionStorage.removeItem('tcpm.session');
    sessionStorage.removeItem('tcpm.csrf');
  }

  async authenticate(initData, signal) {
    const result = await this.request('/api/v1/auth/telegram', {method: 'POST', body: {init_data: initData}, auth: false, signal});
    this.setSession(result.session);
    return result;
  }

  async rotateSession(signal) {
    const result = await this.request('/api/v1/auth/rotate', {method: 'POST', body: {}, signal});
    this.setSession(result.session);
    return result.session;
  }

  async request(path, options = {}) {
    this.assertPath(path);
    const method = String(options.method || 'GET').toUpperCase();
    const headers = new Headers({'Accept': 'application/json'});
    if (options.auth !== false) {
      if (!this.token) throw new ApiError({code: 'authentication_required', message: 'Authentication is required.'}, 401);
      headers.set('Authorization', `Bearer ${this.token}`);
      headers.set('X-Session-Token', this.token);
      if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) headers.set('X-CSRF-Token', this.csrf);
    }
    let body;
    if (options.form instanceof FormData) {
      body = options.form;
    } else if (options.body !== undefined) {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(options.body);
    }
    let response;
    try {
      response = await fetch(resolveAppPath(path), {method, headers, body, signal: options.signal, credentials: 'same-origin', redirect: 'follow', cache: 'no-store'});
    } catch (error) {
      if (error?.name === 'AbortError') throw error;
      throw new ApiError({code: 'network_error', message: 'Network request failed.', detail: String(error?.message || '')}, 0);
    }
    const payload = await this.parse(response);
    if (!response.ok || payload?.ok === false) {
      const error = new ApiError(payload?.error || {}, response.status);
      if (response.status === 401) this.onAuthLost(error);
      throw error;
    }
    return payload?.data ?? payload;
  }

  upload(path, form, onProgress = () => {}) {
    this.assertPath(path);
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', resolveAppPath(path), true);
      xhr.responseType = 'json';
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('Authorization', `Bearer ${this.token}`);
      xhr.setRequestHeader('X-Session-Token', this.token);
      xhr.setRequestHeader('X-CSRF-Token', this.csrf);
      xhr.upload.onprogress = event => {
        if (event.lengthComputable) onProgress(Math.min(100, Math.round((event.loaded / event.total) * 100)));
      };
      xhr.onerror = () => reject(new ApiError({code: 'network_error', message: 'Network request failed.'}, 0));
      xhr.onload = () => {
        const payload = xhr.response || this.tryJson(xhr.responseText);
        if (xhr.status < 200 || xhr.status >= 300 || payload?.ok === false) {
          const error = new ApiError(payload?.error || {}, xhr.status);
          if (xhr.status === 401) this.onAuthLost(error);
          reject(error);
          return;
        }
        onProgress(100);
        resolve(payload?.data ?? payload);
      };
      xhr.send(form);
    });
  }

  async download(path, filename = 'download.bin') {
    this.assertPath(path);
    const response = await fetch(resolveAppPath(path), {headers: {'Authorization': `Bearer ${this.token}`, 'X-Session-Token': this.token, 'Accept': '*/*'}, credentials: 'same-origin', redirect: 'follow', cache: 'no-store'});
    if (!response.ok) {
      const payload = await this.parse(response);
      throw new ApiError(payload?.error || {}, response.status);
    }
    const blob = await response.blob();
    const disposition = response.headers.get('content-disposition') || '';
    const match = disposition.match(/filename="?([^";]+)"?/i);
    const name = match?.[1] || filename;
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = name; anchor.rel = 'noopener';
    document.body.appendChild(anchor); anchor.click(); anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
  }

  async parse(response) {
    const text = await response.text();
    if (!text) return {};
    try { return JSON.parse(text); } catch {
      const snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
      throw new ApiError({code: response.status === 404 ? 'api_route_unreachable' : 'invalid_server_response', message: `The server returned a non-JSON response (HTTP ${response.status}).`, detail: snippet}, response.status);
    }
  }

  tryJson(value) {
    try { return JSON.parse(value || '{}'); } catch { return {}; }
  }

  assertPath(path) {
    if (typeof path !== 'string' || !path.startsWith('/api/v1/')) throw new TypeError('Only same-origin API v1 paths are allowed.');
  }
}

export function query(path, parameters = {}) {
  const url = new URL(path, location.origin);
  for (const [key, value] of Object.entries(parameters)) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value));
  }
  return url.pathname + url.search;
}
