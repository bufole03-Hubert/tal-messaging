/* TAL Messaging — JS local (aucune dépendance externe). Toutes les requêtes passent par AJAX/fetch. */
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const csrfMeta = () => $('meta[name="csrf-token"]');

  // Application installée dans un sous-dossier (ex. /tal-messaging/public) : toutes les URL internes sont préfixées.
  const BASE = ($('meta[name="base-url"]')?.content || '').replace(/\/$/, '');
  const u = (path) => (/^https?:\/\//.test(path) ? path : BASE + (path.startsWith('/') ? path : '/' + path));

  /* ------------------------------------------------------------------ API */
  async function api(method, url, body) {
    const res = await fetch(u(url), {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfMeta()?.content || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    let payload = null;
    try { payload = await res.json(); } catch (_) { /* corps vide */ }
    if (!res.ok) {
      const err = new Error(payload?.error?.message || 'Erreur réseau.');
      err.code = payload?.error?.code;
      err.status = res.status;
      err.fields = payload?.error?.details?.fields;
      if (res.status === 401 && err.code === 'AUTH_REQUIRED' && !location.pathname.endsWith('/login')) {
        location.href = u('/login');
      }
      throw err;
    }
    return payload?.data ?? payload;
  }

  function updateCsrf(token) {
    if (token && csrfMeta()) csrfMeta().content = token;
  }

  /* ---------------------------------------------------------------- Toasts */
  function toast(message, ok = true) {
    const box = $('#toast');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err');
    const icon = document.createElement('i');
    icon.className = 'ti ' + (ok ? 'ti-circle-check' : 'ti-alert-circle');
    const text = document.createElement('span');
    text.textContent = message;
    el.append(icon, text);
    box.appendChild(el);
    setTimeout(() => el.remove(), 5000);
  }

  function showError(err, form) {
    let msg = err.message;
    if (err.fields) msg += ' ' + Object.values(err.fields).flat().join(' ');
    const box = $('#form-error');
    if (box && form && !form.closest('.hidden')) {
      const target = $('[data-msg]', box) || box;
      target.textContent = msg;
      box.classList.remove('hidden');
    } else {
      toast(msg, false);
    }
  }

  async function withBusy(form, fn) {
    const btn = $('button[type="submit"]', form);
    if (btn) btn.disabled = true;
    $('#form-error')?.classList.add('hidden');
    try { await fn(); } catch (err) { showError(err, form); } finally { if (btn) btn.disabled = false; }
  }

  const formData = (form) => Object.fromEntries(new FormData(form).entries());

  /* ----------------------------------------------------------------- Thème */
  const THEME_KEY = 'tal-theme';
  const mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  const currentMode = () => document.documentElement.getAttribute('data-theme-mode') || 'system';

  function applyMode(mode, persist = true) {
    const root = document.documentElement;
    const dark = mode === 'dark' || (mode === 'system' && !!mq && mq.matches);
    root.classList.toggle('dark', dark);
    root.setAttribute('data-theme-mode', mode);
    if (persist) { try { localStorage.setItem(THEME_KEY, mode); } catch (_) { /* ignoré */ } }
    $$('[data-theme-set]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.themeSet === mode)));
    const iconName = mode === 'light' ? 'sun' : mode === 'dark' ? 'moon' : 'device-desktop';
    $$('[data-theme-cycle] .ti').forEach((i) => { i.className = 'ti ti-' + iconName + ' text-lg'; });
    document.dispatchEvent(new CustomEvent('themechange'));
  }

  if (mq) {
    const onChange = () => { if (currentMode() === 'system') applyMode('system', false); };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
  }

  /* --------------------------------------------------------------- Sidebar */
  const sidebarOpen = () => !$('#sidebar')?.classList.contains('-translate-x-full');
  function setSidebar(open) {
    const sb = $('#sidebar');
    if (!sb) return;
    sb.classList.toggle('-translate-x-full', !open);
    sb.classList.toggle('translate-x-0', open);
    $('#sidebar-backdrop')?.classList.toggle('hidden', !open);
    document.body.classList.toggle('overflow-hidden', open);
  }
  function toggleCollapse() {
    const root = document.documentElement;
    const collapsed = root.getAttribute('data-sidebar') === 'collapsed';
    if (collapsed) root.removeAttribute('data-sidebar'); else root.setAttribute('data-sidebar', 'collapsed');
    try { localStorage.setItem('tal-sidebar', collapsed ? 'expanded' : 'collapsed'); } catch (_) { /* ignoré */ }
  }

  /* ------------------------------------------------------------- Dropdowns */
  function closeDropdowns(except) {
    $$('[data-dropdown]').forEach((d) => {
      if (d === except) return;
      $('[data-dropdown-menu]', d)?.classList.add('hidden');
      $('[data-dropdown-toggle]', d)?.setAttribute('aria-expanded', 'false');
    });
  }

  /* ------------------------------------------- Sélecteur moderne (<select>) */
  function enhanceSelect(sel) {
    if (sel.dataset.enhanced) return;
    sel.dataset.enhanced = '1';

    const wrap = document.createElement('div');
    wrap.className = 'ui-select';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ui-select-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    const label = document.createElement('span');
    label.className = 'truncate';
    const chevron = document.createElement('i');
    chevron.className = 'ti ti-selector text-mute';
    btn.append(label, chevron);
    const list = document.createElement('ul');
    list.className = 'ui-select-menu hidden';
    list.setAttribute('role', 'listbox');

    sel.parentNode.insertBefore(wrap, sel);
    wrap.append(btn, list, sel);
    sel.classList.add('sr-only');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    let active = -1;
    const options = Array.from(sel.options);

    function render() {
      list.textContent = '';
      options.forEach((o, i) => {
        const li = document.createElement('li');
        li.className = 'ui-select-opt' + (i === active ? ' is-active' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', String(o.value === sel.value));
        li.dataset.index = String(i);
        const t = document.createElement('span');
        t.textContent = o.textContent;
        li.appendChild(t);
        if (o.value === sel.value) {
          const c = document.createElement('i');
          c.className = 'ti ti-check';
          li.appendChild(c);
        }
        list.appendChild(li);
      });
      label.textContent = sel.options[sel.selectedIndex]?.textContent || '';
    }
    const isOpen = () => !list.classList.contains('hidden');
    function open() {
      closeSelects(wrap);
      active = Math.max(0, sel.selectedIndex);
      render();
      list.classList.remove('hidden');
      btn.setAttribute('aria-expanded', 'true');
    }
    function close() {
      list.classList.add('hidden');
      btn.setAttribute('aria-expanded', 'false');
    }
    function choose(i) {
      sel.value = options[i].value;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      close();
      btn.focus();
    }

    btn.addEventListener('click', () => (isOpen() ? close() : open()));
    btn.addEventListener('keydown', (e) => {
      if (!isOpen()) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); open(); }
        return;
      }
      if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % options.length; render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + options.length) % options.length; render(); }
      else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(active); }
      else if (e.key === 'Escape' || e.key === 'Tab') { close(); }
    });
    list.addEventListener('mousedown', (e) => e.preventDefault());
    list.addEventListener('click', (e) => {
      const li = e.target.closest('[role="option"]');
      if (li) choose(Number(li.dataset.index));
    });
    sel.addEventListener('change', render);
    wrap._close = close;
    render();
  }
  function closeSelects(except) {
    $$('.ui-select').forEach((w) => { if (w !== except && w._close) w._close(); });
  }

  /* ------------------------------------------------- Page « Apparence » */
  const PRESETS = [
    { name: 'Indigo', light: ['#4F46E5', '#FFFFFF'], dark: ['#818CF8', '#0B0D12'] },
    { name: 'Émeraude', light: ['#059669', '#FFFFFF'], dark: ['#34D399', '#06130E'] },
    { name: 'Rose', light: ['#E11D48', '#FFFFFF'], dark: ['#FB7185', '#16060A'] },
    { name: 'Ciel', light: ['#0284C7', '#FFFFFF'], dark: ['#38BDF8', '#06121A'] },
    { name: 'Violet', light: ['#7C3AED', '#FFFFFF'], dark: ['#A78BFA', '#0E0817'] },
    { name: 'Ambre', light: ['#B45309', '#FFFFFF'], dark: ['#FBBF24', '#1A1204'] },
  ];

  function initAppearance() {
    const root = $('[data-appearance]');
    if (!root) return;
    const state = JSON.parse(root.dataset.initial);
    const HEX = /^#[0-9a-f]{6}$/i;
    const rgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16)).join(' ');

    function paintPreview(mode) {
      const el = $('[data-preview="' + mode + '"]', root);
      if (!el) return;
      Object.entries(state.palette[mode]).forEach(([t, v]) => el.style.setProperty('--c-' + t, rgb(v)));
    }
    function syncInputs() {
      $$('input[data-token]', root).forEach((inp) => {
        const v = state.palette[inp.dataset.mode][inp.dataset.token];
        if (inp.type === 'color') inp.value = v.toLowerCase(); else { inp.value = v; inp.removeAttribute('aria-invalid'); }
      });
      paintPreview('light');
      paintPreview('dark');
    }

    // Édition d'une couleur (sélecteur natif ou saisie hexadécimale)
    root.addEventListener('input', (e) => {
      const inp = e.target.closest('input[data-token]');
      if (!inp) return;
      let v = inp.value.trim();
      if (inp.type !== 'color' && v && v[0] !== '#') v = '#' + v;
      if (!HEX.test(v)) { if (inp.type !== 'color') inp.setAttribute('aria-invalid', 'true'); return; }
      v = v.toUpperCase();
      state.palette[inp.dataset.mode][inp.dataset.token] = v;
      $$('input[data-token="' + inp.dataset.token + '"][data-mode="' + inp.dataset.mode + '"]', root).forEach((o) => {
        if (o === inp) { o.removeAttribute('aria-invalid'); return; }
        o.value = o.type === 'color' ? v.toLowerCase() : v;
        o.removeAttribute('aria-invalid');
      });
      paintPreview(inp.dataset.mode);
    });

    // Préréglages de couleur principale
    const presetBox = $('#presets', root);
    PRESETS.forEach((p) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'preset';
      const dot = document.createElement('span');
      dot.className = 'preset-dot';
      dot.style.setProperty('background-color', p.light[0]);
      b.append(dot, document.createTextNode(p.name));
      b.addEventListener('click', () => {
        ['light', 'dark'].forEach((m) => { state.palette[m].brand = p[m][0]; state.palette[m].brandfg = p[m][1]; });
        syncInputs();
      });
      presetBox.appendChild(b);
    });

    // Onglets clair / sombre
    $$('[data-palette-tab]', root).forEach((tab) => tab.addEventListener('click', () => {
      $$('[data-palette-tab]', root).forEach((t) => t.setAttribute('aria-pressed', String(t === tab)));
      $$('[data-palette-group]', root).forEach((g) => g.classList.toggle('hidden', g.dataset.paletteGroup !== tab.dataset.paletteTab));
    }));

    // Mode par défaut
    const modeSel = $('#default-mode', root);
    modeSel.addEventListener('change', () => { state.default_mode = modeSel.value; });

    function refreshThemeLink() {
      const link = $('link[href*="theme.css"]');
      if (link) link.setAttribute('href', link.getAttribute('href').split('?')[0] + '?v=' + Date.now());
      document.documentElement.setAttribute('data-theme-default', state.default_mode);
    }

    $('[data-action="theme-save"]', root).addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        const r = await api('PUT', '/api/theme', { default_mode: state.default_mode, palette: state.palette });
        state.palette = r.palette; state.default_mode = r.default_mode;
        refreshThemeLink();
        toast('Thème enregistré pour toute la plateforme.');
      } catch (err) {
        toast(err.message + (err.fields ? ' ' + Object.values(err.fields).flat().join(' ') : ''), false);
      } finally { btn.disabled = false; }
    });

    $('[data-action="theme-reset"]', root).addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        const r = await api('POST', '/api/theme/reset');
        state.palette = r.palette; state.default_mode = r.default_mode;
        modeSel.value = state.default_mode;
        modeSel.dispatchEvent(new Event('change', { bubbles: true }));
        syncInputs();
        refreshThemeLink();
        toast('Thème réinitialisé.');
      } catch (err) { toast(err.message, false); } finally { btn.disabled = false; }
    });

    syncInputs();
  }

  /* ------------------------------------------------- 2FA : QR code de configuration */
  // Les repères d'angle prennent la couleur de la marque (palette du super admin) uniquement si elle est
  // assez sombre sur fond blanc ; sinon ils restent anthracite pour garantir la lecture par tous les scanners.
  function qrEyeClass() {
    const raw = getComputedStyle(document.documentElement).getPropertyValue('--c-brand').trim();
    const [r, g, b] = raw.split(/[\s,]+/).map(Number);
    if ([r, g, b].some((v) => !Number.isFinite(v))) return 'fill-slate-900';
    const lin = (c) => { const v = c / 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    const lum = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    return 1.05 / (lum + 0.05) >= 5 ? 'fill-brand' : 'fill-slate-900';
  }

  function renderTwofaSetup(r) {
    const secret = String(r.secret || '');
    const secretEl = $('#twofa-secret');
    secretEl.dataset.raw = secret; // la copie envoie la clé brute, sans espaces
    secretEl.textContent = secret.replace(/(.{4})/g, '$1 ').trim();

    const st = $('#twofa-server-time');
    if (st && r.server_time) st.textContent = r.server_time;

    const open = $('#twofa-open-app');
    if (open) open.href = r.otpauth_uri; // lien otpauth:// : ouvre directement l'appli sur le téléphone

    const svgBox = $('#twofa-qr-svg');
    const fail = () => { $('#twofa-qr-skeleton')?.classList.add('hidden'); $('#twofa-qr-error')?.classList.remove('hidden'); };
    try {
      if (!window.TalQR) throw new Error('qr.js indisponible');
      const out = window.TalQR.svg(r.otpauth_uri, { level: 'Q', logo: true, eyeClass: qrEyeClass(), label: 'QR code à scanner avec votre application d’authentification' });
      svgBox.innerHTML = out.svg;
      const logo = $('#twofa-qr-logo');
      if (logo) {
        // Le badge occupe la zone dégagée moins une marge blanche d'environ 1 module.
        logo.style.width = logo.style.height = ((out.logoRatio - 2 / (out.size + 4)) * 100).toFixed(1) + '%';
        logo.classList.replace('hidden', 'flex');
      }
      $('#twofa-qr-skeleton')?.classList.add('hidden');
      $('#twofa-qr-error')?.classList.add('hidden');
    } catch (_) {
      fail();
    }
  }

  /* -------------------------------------------------------- Formulaires */
  const handlers = {
    async login(form) {
      const r = await api('POST', '/api/auth/login', formData(form));
      if (r.requires_2fa) {
        form.classList.add('hidden');
        const f2 = $('#twofa-form');
        f2.classList.remove('hidden');
        $('#code', f2).focus();
        return;
      }
      updateCsrf(r.csrf_token);
      location.href = u(r.redirect || '/');
    },
    async 'login-2fa'(form) {
      const r = await api('POST', '/api/auth/2fa/verify-login', formData(form));
      updateCsrf(r.csrf_token);
      location.href = u(r.redirect || '/');
    },
    async password(form) {
      await api('POST', '/api/auth/password', formData(form));
      toast('Mot de passe mis à jour.');
      form.reset();
      setTimeout(() => { location.href = u('/'); }, 800);
    },
    async '2fa-enable'(form) {
      const r = await api('POST', '/api/auth/2fa/enable', formData(form));
      updateCsrf(r.csrf_token);
      $('#twofa-setup')?.classList.add('hidden');
      $('#recovery-codes').textContent = r.recovery_codes.join('\n');
      $('#recovery').classList.remove('hidden');
      toast('Double authentification activée.');
    },
    async '2fa-disable'(form) {
      await api('POST', '/api/auth/2fa/disable', formData(form));
      toast('Double authentification désactivée.');
      setTimeout(() => location.reload(), 800);
    },
  };

  document.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-form]');
    if (!form) return;
    e.preventDefault();
    const h = handlers[form.dataset.form];
    if (h) withBusy(form, () => h(form));
  });

  /* ------------------------------------------------------------- Clics */
  document.addEventListener('click', async (e) => {
    if (!e.target.closest('.ui-select')) closeSelects();
    const dd = e.target.closest('[data-dropdown]');
    if (!dd) closeDropdowns();

    const el = e.target.closest('[data-action],[data-toggle],[data-theme-set],[data-theme-cycle],[data-dropdown-toggle],[data-toggle-password],[data-copy]');
    if (!el) return;

    if (el.dataset.toggle === 'sidebar') { setSidebar(!sidebarOpen()); return; }
    if (el.dataset.toggle === 'collapse') { toggleCollapse(); return; }

    if (el.hasAttribute('data-theme-set')) { applyMode(el.dataset.themeSet); return; }
    if (el.hasAttribute('data-theme-cycle')) {
      const order = ['light', 'dark', 'system'];
      applyMode(order[(order.indexOf(currentMode()) + 1) % order.length]);
      return;
    }

    if (el.hasAttribute('data-dropdown-toggle')) {
      const wrap = el.closest('[data-dropdown]');
      const menu = $('[data-dropdown-menu]', wrap);
      const willOpen = menu.classList.contains('hidden');
      closeDropdowns(wrap);
      menu.classList.toggle('hidden', !willOpen);
      el.setAttribute('aria-expanded', String(willOpen));
      return;
    }

    if (el.hasAttribute('data-toggle-password')) {
      const input = $('input', el.closest('.field'));
      const icon = $('.ti', el);
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      icon.className = 'ti ' + (show ? 'ti-eye-off' : 'ti-eye');
      return;
    }

    if (el.hasAttribute('data-copy')) {
      const target = $(el.dataset.copy);
      if (target && navigator.clipboard) {
        try { await navigator.clipboard.writeText(target.dataset.raw || target.textContent.trim()); toast('Copié dans le presse-papiers.'); }
        catch (_) { toast('Copie impossible.', false); }
      }
      return;
    }

    if (el.dataset.action === 'logout') {
      try { const r = await api('POST', '/api/auth/logout'); location.href = u(r.redirect || '/login'); }
      catch (err) { toast(err.message, false); }
      return;
    }
    if (el.dataset.action === '2fa-setup') {
      try {
        const r = await api('POST', '/api/auth/2fa/setup');
        renderTwofaSetup(r);
        $('#twofa-setup').classList.remove('hidden');
        el.classList.add('hidden');
      } catch (err) { toast(err.message, false); }
      return;
    }
    if (el.dataset.action === '2fa-cancel') {
      // Abandon : on masque le QR et on efface la clé affichée (une nouvelle clé sera générée à la prochaine tentative).
      $('#twofa-setup').classList.add('hidden');
      $('#twofa-qr-svg').innerHTML = '';
      $('#twofa-secret').textContent = '';
      delete $('#twofa-secret').dataset.raw;
      $('#e_code')?.form?.reset();
      $('[data-action="2fa-setup"]')?.classList.remove('hidden');
    }
  });

  document.addEventListener('input', (e) => {
    if (e.target.matches('#e_code, #d_code')) e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeDropdowns(); closeSelects(); if (sidebarOpen() && window.innerWidth < 768) setSidebar(false); }
  });

  /* -------------------------------------------------------------- Init */
  $$('select[data-ui="select"]').forEach(enhanceSelect);
  applyMode(currentMode(), false);
  initAppearance();

  window.TAL = { api, toast, url: u };
})();
