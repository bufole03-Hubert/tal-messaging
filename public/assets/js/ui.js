/* TAL Messaging — composants UI partagés du module Contacts.
 * Tout le DOM est construit avec textContent / createElement : aucune donnée serveur n'est injectée en innerHTML. */
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /** h('div', {class:'x', dataset:{id:1}, onclick: fn}, 'texte', autreElement) */
  function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'dataset') Object.assign(el.dataset, v);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'text') el.textContent = v;
      else if (v === true) el.setAttribute(k, '');
      else el.setAttribute(k, String(v));
    }
    for (const c of children.flat()) {
      if (c === null || c === undefined || c === false) continue;
      el.append(c.nodeType ? c : document.createTextNode(String(c)));
    }
    return el;
  }

  const icon = (name, cls = '') => h('i', { class: ('ti ti-' + name + ' ' + cls).trim(), 'aria-hidden': 'true' });
  const clear = (el) => { el.textContent = ''; return el; };
  const nf = new Intl.NumberFormat('fr-FR');
  const fmt = (n) => (n === null || n === undefined ? '—' : nf.format(n));
  const pct = (n) => (n === null || n === undefined ? '—' : String(n).replace('.', ',') + ' %');

  function debounce(fn, ms = 300) {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  }

  function dateFr(s) {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T') + 'Z');
    return Number.isNaN(d.getTime()) ? s : d.toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });
  }

  const OPERATORS = { vodacom: 'Vodacom', airtel: 'Airtel', orange: 'Orange', africell: 'Africell' };
  const opLabel = (o) => (o ? (OPERATORS[o] || o) : 'Inconnu');
  const STATUS = { active: ['Actif', 'badge badge-ok'], blocked: ['Bloqué', 'badge badge-danger'] };
  const SOURCE = { manual: 'Manuel', import: 'Import', api: 'API' };

  function statusBadge(s) {
    const [label, cls] = STATUS[s] || [s, 'badge'];
    return h('span', { class: cls }, label);
  }

  function fullName(c) {
    return [c.first_name, c.last_name].filter(Boolean).join(' ');
  }

  /* ------------------------------------------------------------- Fenêtre */
  function modal({ title, body, actions = [], large = false, onClose }) {
    const previous = document.activeElement;
    const close = () => {
      document.removeEventListener('keydown', onKey);
      backdrop.remove();
      document.body.classList.remove('overflow-hidden');
      if (previous && previous.focus) previous.focus();
      if (onClose) onClose();
    };
    const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
    const foot = h('div', { class: 'modal-foot' });
    const box = h('div', { class: 'modal' + (large ? ' modal-lg' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
      h('div', { class: 'modal-head' },
        h('h3', { class: 'text-lg font-bold' }, title),
        h('button', { type: 'button', class: 'btn btn-ghost btn-icon btn-sm', 'aria-label': 'Fermer', onclick: close }, icon('x', 'text-lg'))),
      h('div', { class: 'modal-body' }, body),
      foot);
    const backdrop = h('div', { class: 'modal-backdrop', onmousedown: (e) => { if (e.target === backdrop) close(); } }, box);
    for (const a of actions) {
      foot.append(h('button', {
        type: a.submit ? 'submit' : 'button',
        class: 'btn ' + (a.class || 'btn-outline'),
        onclick: a.onclick ? (e) => a.onclick(e, close) : close,
      }, a.label));
    }
    if (!actions.length) foot.remove();
    document.body.append(backdrop);
    document.body.classList.add('overflow-hidden');
    document.addEventListener('keydown', onKey);
    const first = $('input:not([type=hidden]), select, textarea, button.btn-primary', box);
    if (first) first.focus();
    return { close, box, foot };
  }

  function confirmBox(message, { label = 'Confirmer', danger = false, title = 'Confirmation' } = {}) {
    return new Promise((resolve) => {
      let done = false;
      const finish = (v) => { if (!done) { done = true; resolve(v); } };
      modal({
        title,
        body: h('p', { class: 'text-sm' }, message),
        onClose: () => finish(false),
        actions: [
          { label: 'Annuler', class: 'btn-outline' },
          { label, class: danger ? 'btn-danger' : 'btn-primary', onclick: (e, close) => { finish(true); close(); } },
        ],
      });
    });
  }

  /* ------------------------------------------------------ Champs de formulaire */
  function field(label, input, hint) {
    return h('div', {}, h('label', { class: 'label' }, label), input, hint ? h('p', { class: 'hint' }, hint) : null);
  }
  function textInput(name, value = '', attrs = {}) {
    return h('input', Object.assign({ class: 'input', type: 'text', name, value: value ?? '', autocomplete: 'off' }, attrs));
  }
  function selectInput(name, options, value = '') {
    const sel = h('select', { class: 'input', name }, options.map(([v, l]) => h('option', { value: v, selected: String(v) === String(value) }, l)));
    return sel;
  }

  /* ---------------------------------------------------- Pagination, états */
  function pager(meta, onPage) {
    const pages = Math.max(1, Math.ceil(meta.total / meta.per_page));
    const from = meta.total === 0 ? 0 : (meta.page - 1) * meta.per_page + 1;
    const to = Math.min(meta.total, meta.page * meta.per_page);
    return h('div', { class: 'flex flex-wrap items-center justify-between gap-3 px-1 pt-4 text-sm text-mute' },
      h('span', {}, fmt(from) + '–' + fmt(to) + ' sur ' + fmt(meta.total)),
      h('div', { class: 'flex items-center gap-2' },
        h('button', { type: 'button', class: 'btn btn-outline btn-sm', disabled: meta.page <= 1, onclick: () => onPage(meta.page - 1) }, icon('chevron-left'), 'Précédent'),
        h('span', {}, meta.page + ' / ' + pages),
        h('button', { type: 'button', class: 'btn btn-outline btn-sm', disabled: meta.page >= pages, onclick: () => onPage(meta.page + 1) }, 'Suivant', icon('chevron-right'))));
  }

  function empty(message, iconName = 'inbox') {
    return h('div', { class: 'flex flex-col items-center gap-2 px-6 py-12 text-center text-mute' }, icon(iconName, 'text-4xl'), h('p', { class: 'text-sm' }, message));
  }

  function kpi(label, value, sub) {
    return h('div', { class: 'kpi' },
      h('p', { class: 'kpi-label' }, label),
      h('p', { class: 'kpi-value' }, value),
      sub ? h('p', { class: 'mt-1 text-xs text-mute' }, sub) : null);
  }

  /** Barres horizontales : items = [{label, count}] */
  function bars(items, total) {
    const max = Math.max(1, ...items.map((i) => i.count));
    if (!items.length) return empty('Aucune donnée.', 'chart-bar');
    return h('div', { class: 'space-y-2.5' }, items.map((i) => {
      const fill = h('div', { class: 'bar-fill' });
      fill.style.width = Math.max(2, Math.round((i.count / max) * 100)) + '%';
      return h('div', { class: 'flex items-center gap-3 text-sm' },
        h('span', { class: 'w-24 shrink-0 truncate text-mute sm:w-32' }, i.label),
        h('div', { class: 'bar-track' }, fill),
        h('span', { class: 'w-20 shrink-0 text-right font-medium tabular-nums' },
          fmt(i.count) + (total ? ' · ' + Math.round((i.count / total) * 100) + '%' : '')));
    }));
  }

  /** Mini histogramme (croissance) : points = [{date, count}] */
  function spark(points) {
    const max = Math.max(1, ...points.map((p) => p.count));
    return h('div', { class: 'flex h-24 items-end gap-0.5', role: 'img', 'aria-label': 'Nouveaux contacts sur 30 jours' }, points.map((p) => {
      const bar = h('div', { class: 'w-full rounded-t bg-brand/70', title: p.date + ' : ' + p.count });
      bar.style.height = Math.max(3, Math.round((p.count / max) * 100)) + '%';
      return h('div', { class: 'flex h-full flex-1 items-end' }, bar);
    }));
  }

  function toastErr(err) { window.TAL.toast(err.message + (err.fields ? ' ' + Object.values(err.fields).flat().join(' ') : ''), false); }

  window.TALUI = { $, $$, h, icon, clear, fmt, pct, debounce, dateFr, opLabel, OPERATORS, STATUS, SOURCE, statusBadge, fullName,
    modal, confirmBox, field, textInput, selectInput, pager, empty, kpi, bars, spark, toastErr };
})();
