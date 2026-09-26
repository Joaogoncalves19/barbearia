/* Banner de instalação do PWA.
 * - Android/Chrome/Edge: usa o evento beforeinstallprompt -> botão "Instalar".
 * - iOS/Safari: mostra instruções (Compartilhar -> Adicionar à Tela de Início).
 * - Não aparece se o app já está instalado nem se foi dispensado há pouco.
 */
(function () {
  'use strict';

  var DISMISS_KEY = 'pwa_install_dismissed_at';
  var DISMISS_DAYS = 14;
  var THEME = (window.__pwaThemeColor && /^#[0-9a-fA-F]{3,6}$/.test(window.__pwaThemeColor))
    ? window.__pwaThemeColor : '#111827';
  var ICON = (window.__pwaIcon || 'uploads/pwa-icon-192.png');
  var deferredPrompt = null;

  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
      window.navigator.standalone === true;
  }
  function isiOS() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent) && !window.MSStream;
  }
  function isSafari() {
    var ua = window.navigator.userAgent;
    return /safari/i.test(ua) && !/crios|fxios|edgios|android/i.test(ua);
  }
  function recentlyDismissed() {
    try {
      var t = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
      return t && (Date.now() - t) < DISMISS_DAYS * 864e5;
    } catch (e) { return false; }
  }
  function rememberDismiss() {
    try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) {}
  }

  function injectStyles() {
    if (document.getElementById('pwa-install-styles')) return;
    var css = '' +
      '#pwa-install-banner{position:fixed;left:50%;transform:translateX(-50%) translateY(140%);' +
      'bottom:calc(16px + env(safe-area-inset-bottom));z-index:2147483000;width:min(440px,calc(100vw - 24px));' +
      'background:#fff;color:#1e293b;border:1px solid #e2e8f0;border-radius:16px;' +
      'box-shadow:0 12px 40px rgba(2,6,23,.18);padding:14px 16px;display:flex;gap:12px;align-items:center;' +
      'font-family:inherit;opacity:0;transition:transform .35s cubic-bezier(.2,.8,.2,1),opacity .35s}' +
      '#pwa-install-banner.pwa-show{transform:translateX(-50%) translateY(0);opacity:1}' +
      '#pwa-install-banner .pwa-ic{width:44px;height:44px;border-radius:12px;flex:0 0 auto;object-fit:cover;' +
      'background:' + THEME + '}' +
      '#pwa-install-banner .pwa-tx{flex:1 1 auto;min-width:0}' +
      '#pwa-install-banner .pwa-tt{font-weight:700;font-size:15px;line-height:1.2;margin:0 0 2px}' +
      '#pwa-install-banner .pwa-ds{font-size:12.5px;line-height:1.35;color:#64748b;margin:0}' +
      '#pwa-install-banner .pwa-ds b{color:#334155}' +
      '#pwa-install-banner .pwa-act{display:flex;gap:8px;align-items:center;flex:0 0 auto}' +
      '#pwa-install-banner .pwa-btn{border:0;cursor:pointer;font:inherit;font-weight:700;font-size:13.5px;' +
      'padding:9px 14px;border-radius:10px;background:' + THEME + ';color:#fff;white-space:nowrap}' +
      '#pwa-install-banner .pwa-btn:hover{filter:brightness(1.08)}' +
      '#pwa-install-banner .pwa-x{border:0;background:transparent;cursor:pointer;color:#94a3b8;font-size:20px;' +
      'line-height:1;padding:4px 6px;border-radius:8px}' +
      '#pwa-install-banner .pwa-x:hover{background:#f1f5f9;color:#475569}' +
      '#pwa-install-banner.pwa-ios{flex-direction:column;align-items:stretch;text-align:left}' +
      '#pwa-install-banner.pwa-ios .pwa-row{display:flex;gap:12px;align-items:center}' +
      '@media (prefers-color-scheme:dark){' +
      '#pwa-install-banner{background:#0f172a;color:#e2e8f0;border-color:#1e293b;box-shadow:0 12px 40px rgba(0,0,0,.5)}' +
      '#pwa-install-banner .pwa-ds{color:#94a3b8}#pwa-install-banner .pwa-ds b{color:#cbd5e1}' +
      '#pwa-install-banner .pwa-x:hover{background:#1e293b;color:#cbd5e1}}';
    var s = document.createElement('style');
    s.id = 'pwa-install-styles';
    s.textContent = css;
    document.head.appendChild(s);
  }

  function show(el) {
    document.body.appendChild(el);
    requestAnimationFrame(function () { requestAnimationFrame(function () { el.classList.add('pwa-show'); }); });
  }
  function hide(el) {
    el.classList.remove('pwa-show');
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
  }

  function buildInstallBanner() {
    var el = document.createElement('div');
    el.id = 'pwa-install-banner';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Instalar aplicativo');
    el.innerHTML =
      '<img class="pwa-ic" src="' + ICON + '" alt="">' +
      '<div class="pwa-tx"><p class="pwa-tt">Instalar o app</p>' +
      '<p class="pwa-ds">Acesse mais rápido, direto da tela inicial do seu celular.</p></div>' +
      '<div class="pwa-act">' +
      '<button type="button" class="pwa-btn" id="pwa-install-btn">Instalar</button>' +
      '<button type="button" class="pwa-x" id="pwa-close-btn" aria-label="Fechar">&times;</button></div>';
    el.querySelector('#pwa-close-btn').addEventListener('click', function () { rememberDismiss(); hide(el); });
    el.querySelector('#pwa-install-btn').addEventListener('click', function () {
      if (!deferredPrompt) { hide(el); return; }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function () { deferredPrompt = null; hide(el); });
    });
    return el;
  }

  function buildiOSBanner() {
    var el = document.createElement('div');
    el.id = 'pwa-install-banner';
    el.className = 'pwa-ios';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Instalar aplicativo');
    el.innerHTML =
      '<div class="pwa-row"><img class="pwa-ic" src="' + ICON + '" alt="">' +
      '<div class="pwa-tx"><p class="pwa-tt">Instalar o app</p>' +
      '<p class="pwa-ds">Toque em <b>Compartilhar</b> ' +
      '<svg width="13" height="13" viewBox="0 0 24 24" style="vertical-align:-2px" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M8 8l4-4 4 4"/><path d="M4 12v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6"/></svg>' +
      ' e depois em <b>Adicionar à Tela de Início</b>.</p></div>' +
      '<button type="button" class="pwa-x" id="pwa-close-btn" aria-label="Fechar">&times;</button></div>';
    el.querySelector('#pwa-close-btn').addEventListener('click', function () { rememberDismiss(); hide(el); });
    return el;
  }

  function init() {
    if (isStandalone() || recentlyDismissed()) return;
    injectStyles();

    // Android / desktop Chromium
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferredPrompt = e;
      if (isStandalone() || recentlyDismissed()) return;
      if (!document.getElementById('pwa-install-banner')) show(buildInstallBanner());
    });

    // iOS Safari: sem beforeinstallprompt -> instruções após um instante
    if (isiOS() && isSafari()) {
      setTimeout(function () {
        if (isStandalone() || recentlyDismissed()) return;
        if (!document.getElementById('pwa-install-banner')) show(buildiOSBanner());
      }, 2500);
    }

    window.addEventListener('appinstalled', function () {
      rememberDismiss();
      var b = document.getElementById('pwa-install-banner');
      if (b) hide(b);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
