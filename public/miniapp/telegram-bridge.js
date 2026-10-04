/*
 * Telegram cPanel Manager — self-hosted Telegram Mini App bridge.
 *
 * Implements the subset of the Telegram WebApp API this app uses on top of the
 * documented WebView event protocol, so the Mini App does not depend on
 * loading https://telegram.org/js/telegram-web-app.js (which is blocked on
 * many networks and was the reason the panel opened without authentication).
 * If the official script is already present it is used unchanged.
 */
(function () {
  'use strict';
  var global = window;
  global.Telegram = global.Telegram || {};
  if (global.Telegram.WebApp && global.Telegram.WebApp.initData !== undefined) return;

  var STORAGE_KEY = '__tcpm_tg_params';

  function parseParams(text) {
    var result = {};
    String(text || '').replace(/^[#?]/, '').split('&').forEach(function (pair) {
      if (!pair) return;
      var index = pair.indexOf('=');
      var key = index < 0 ? pair : pair.slice(0, index);
      var value = index < 0 ? '' : pair.slice(index + 1);
      try { result[decodeURIComponent(key)] = decodeURIComponent(value.replace(/\+/g, '%20')); } catch (error) { result[key] = value; }
    });
    return result;
  }

  var params = parseParams(global.location.hash);
  if (params.tgWebAppData) {
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(params)); } catch (error) {}
  } else {
    try { params = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}') || {}; } catch (error) { params = {}; }
  }
  var searchParams = parseParams(global.location.search);
  if (!params.tgWebAppData && searchParams.tgWebAppData) params = searchParams;

  var initData = params.tgWebAppData || '';
  var initDataUnsafe = {};
  if (initData) {
    var raw = parseParams(initData);
    Object.keys(raw).forEach(function (key) {
      var value = raw[key];
      if (/^(user|receiver|chat)$/.test(key)) { try { value = JSON.parse(value); } catch (error) {} }
      initDataUnsafe[key] = value;
    });
  }
  var themeParams = {};
  try { themeParams = params.tgWebAppThemeParams ? JSON.parse(params.tgWebAppThemeParams) : {}; } catch (error) { themeParams = {}; }

  var isIframe = false;
  try { isIframe = global.parent != null && global !== global.parent; } catch (error) { isIframe = true; }

  function postEvent(eventType, eventData) {
    var data = eventData === undefined ? '' : JSON.stringify(eventData);
    try {
      if (global.TelegramWebviewProxy !== undefined) { global.TelegramWebviewProxy.postEvent(eventType, data); return; }
      if (global.external && 'notify' in global.external) { global.external.notify(JSON.stringify({eventType: eventType, eventData: eventData})); return; }
      if (isIframe) { global.parent.postMessage(JSON.stringify({eventType: eventType, eventData: eventData}), '*'); }
    } catch (error) {}
  }

  var handlers = {};
  function on(type, callback) { (handlers[type] = handlers[type] || []).push(callback); }
  function off(type, callback) { handlers[type] = (handlers[type] || []).filter(function (item) { return item !== callback; }); }
  function emit(type, payload) { (handlers[type] || []).slice().forEach(function (callback) { try { callback.call(WebApp, payload); } catch (error) { setTimeout(function () { throw error; }); } }); }

  function receiveEvent(eventType, eventData) {
    if (eventType === 'theme_changed' && eventData && eventData.theme_params) {
      WebApp.themeParams = themeParams = eventData.theme_params;
      WebApp.colorScheme = schemeOf(themeParams);
      applyThemeVariables();
      emit('themeChanged');
    } else if (eventType === 'viewport_changed' && eventData) {
      if (eventData.height) WebApp.viewportHeight = eventData.height;
      WebApp.isExpanded = !!eventData.is_expanded;
      emit('viewportChanged', {isStateStable: !!eventData.is_state_stable});
    } else if (eventType === 'back_button_pressed') {
      emit('backButtonClicked');
    } else if (eventType === 'main_button_pressed') {
      emit('mainButtonClicked');
    } else if (eventType === 'popup_closed') {
      emit('popupClosed', {button_id: eventData && eventData.button_id || null});
    }
  }
  global.Telegram.WebView = {initParams: params, isIframe: isIframe, postEvent: postEvent, receiveEvent: receiveEvent, onEvent: on, offEvent: off};
  global.addEventListener('message', function (event) {
    try {
      var message = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
      if (message && message.eventType) receiveEvent(message.eventType, message.eventData);
    } catch (error) {}
  });

  function schemeOf(theme) {
    var color = String(theme.bg_color || '');
    var match = /^#?([0-9a-f]{6})$/i.exec(color);
    if (!match) return global.matchMedia && global.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    var value = parseInt(match[1], 16);
    var luminance = 0.299 * ((value >> 16) & 255) + 0.587 * ((value >> 8) & 255) + 0.114 * (value & 255);
    return luminance < 120 ? 'dark' : 'light';
  }

  function applyThemeVariables() {
    var root = document.documentElement;
    Object.keys(themeParams || {}).forEach(function (key) {
      root.style.setProperty('--tg-theme-' + key.replace(/_/g, '-'), themeParams[key]);
    });
    root.setAttribute('data-tg-scheme', WebApp.colorScheme);
  }

  function versionAtLeast(version) {
    var current = String(WebApp.version).split('.').map(Number);
    var wanted = String(version).split('.').map(Number);
    for (var i = 0; i < Math.max(current.length, wanted.length); i++) {
      var a = current[i] || 0, b = wanted[i] || 0;
      if (a !== b) return a > b;
    }
    return true;
  }

  function makeBackButton() {
    var visible = false;
    var button = {
      get isVisible() { return visible; },
      onClick: function (callback) { on('backButtonClicked', callback); return button; },
      offClick: function (callback) { off('backButtonClicked', callback); return button; },
      show: function () { visible = true; postEvent('web_app_setup_back_button', {is_visible: true}); return button; },
      hide: function () { visible = false; postEvent('web_app_setup_back_button', {is_visible: false}); return button; }
    };
    return button;
  }

  function makeMainButton() {
    var state = {text: 'Continue', color: themeParams.button_color || '#2481cc', textColor: themeParams.button_text_color || '#ffffff', isVisible: false, isActive: true, isProgressVisible: false};
    function sync() {
      postEvent('web_app_setup_main_button', {is_visible: state.isVisible, is_active: state.isActive, is_progress_visible: state.isProgressVisible, text: state.text, color: state.color, text_color: state.textColor});
    }
    var button = {
      get text() { return state.text; },
      get isVisible() { return state.isVisible; },
      get isActive() { return state.isActive; },
      setText: function (text) { state.text = String(text || '').slice(0, 64) || 'Continue'; sync(); return button; },
      setParams: function (options) { options = options || {}; if (options.text) state.text = String(options.text); if (options.color) state.color = options.color; if (options.text_color) state.textColor = options.text_color; if ('is_visible' in options) state.isVisible = !!options.is_visible; if ('is_active' in options) state.isActive = !!options.is_active; sync(); return button; },
      onClick: function (callback) { on('mainButtonClicked', callback); return button; },
      offClick: function (callback) { off('mainButtonClicked', callback); return button; },
      show: function () { state.isVisible = true; sync(); return button; },
      hide: function () { state.isVisible = false; sync(); return button; },
      enable: function () { state.isActive = true; sync(); return button; },
      disable: function () { state.isActive = false; sync(); return button; },
      showProgress: function () { state.isProgressVisible = true; sync(); return button; },
      hideProgress: function () { state.isProgressVisible = false; sync(); return button; }
    };
    return button;
  }

  var WebApp = {
    initData: initData,
    initDataUnsafe: initDataUnsafe,
    version: params.tgWebAppVersion || '6.0',
    platform: params.tgWebAppPlatform || 'unknown',
    themeParams: themeParams,
    colorScheme: schemeOf(themeParams),
    isExpanded: false,
    viewportHeight: global.innerHeight,
    bridge: 'tcpm',
    isVersionAtLeast: versionAtLeast,
    BackButton: makeBackButton(),
    MainButton: makeMainButton(),
    HapticFeedback: {
      impactOccurred: function (style) { postEvent('web_app_trigger_haptic_feedback', {type: 'impact', impact_style: style || 'light'}); },
      notificationOccurred: function (type) { postEvent('web_app_trigger_haptic_feedback', {type: 'notification', notification_type: type || 'success'}); },
      selectionChanged: function () { postEvent('web_app_trigger_haptic_feedback', {type: 'selection_change'}); }
    },
    onEvent: on,
    offEvent: off,
    ready: function () { postEvent('web_app_ready'); },
    expand: function () { postEvent('web_app_expand'); },
    close: function () { postEvent('web_app_close'); },
    requestFullscreen: function () { if (versionAtLeast('8.0')) postEvent('web_app_request_fullscreen'); },
    enableClosingConfirmation: function () { postEvent('web_app_setup_closing_behavior', {need_confirmation: true}); },
    disableClosingConfirmation: function () { postEvent('web_app_setup_closing_behavior', {need_confirmation: false}); },
    setHeaderColor: function (color) { postEvent('web_app_set_header_color', /^#/.test(color) ? {color: color} : {color_key: color}); },
    setBackgroundColor: function (color) { postEvent('web_app_set_background_color', {color: color}); },
    openLink: function (url) { if (WebApp.platform === 'unknown') { global.open(url, '_blank', 'noopener'); return; } postEvent('web_app_open_link', {url: url}); },
    openTelegramLink: function (url) { var match = /^https:\/\/t\.me(\/.*)$/.exec(String(url)); if (match && WebApp.platform !== 'unknown') postEvent('web_app_open_tg_link', {path_full: match[1]}); else global.location.href = url; },
    showAlert: function (message, callback) { global.alert(message); if (callback) callback(); },
    showConfirm: function (message, callback) { var result = global.confirm(message); if (callback) callback(result); }
  };
  global.Telegram.WebApp = WebApp;
  applyThemeVariables();
  postEvent('web_app_request_theme');
  postEvent('web_app_request_viewport');

  // Boot guard: if the application module fails to start (old browser,
  // blocked script, network failure) show a clear message instead of an
  // endless spinner.
  global.setTimeout(function () {
    if (global.__tcpmBooted) return;
    var card = document.getElementById('boot-card');
    if (!card) return;
    card.innerHTML = '<h1>بارگذاری پنل کامل نشد</h1><p>Panel failed to load. Check your connection and try again.</p><p><button type="button" class="button" id="boot-retry">تلاش دوباره · Retry</button></p>';
    var retry = document.getElementById('boot-retry');
    if (retry) retry.addEventListener('click', function () { global.location.reload(); });
  }, 20000);
})();
