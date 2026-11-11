/* TAL Messaging — page Contacts : liste filtrable (AJAX), fiche, édition, actions groupées, doublons, export. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, pct, debounce, dateFr, opLabel, SOURCE, statusBadge, fullName, modal, confirmBox, field, textInput, selectInput, pager, empty, kpi, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#contacts-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);

  const state = { page: 1, per: 25, sort: 'created_at', dir: 'desc', selected: new Set(), allMatching: false, total: 0, rows: [], groups: [] };
  const filterIds = { operator: 'f-operator', status: 'f-status', country: 'f-country', source: 'f-source', group_id: 'f-group', tag: 'f-tag', organization_id: 'f-organization' };

  /* ---------------------------------------------------------- Filtres */
  function filters() {
    const f = {};
    const q = $('#f-q').value.trim();
    if (q) f.q = q;
    for (const [key, id] of Object.entries(filterIds)) {
      const el = document.getElementById(id);
      if (el && el.value) f[key] = el.value;
    }
    return f;
  }

  function query(extra = {}) {
    const p = new URLSearchParams(Object.assign(filters(), state.importId ? { import_id: state.importId } : {}, extra));
    return p.toString();
  }

  /** Règles équivalentes aux filtres, pour « tout sélectionner » côté serveur. */
  function rulesFromFilters() {
    const f = filters();
    const c = [];
    for (const k of ['country', 'operator', 'status', 'source']) if (f[k]) c.push({ field: k, op: 'in', value: [f[k]] });
    if (f.tag) c.push({ field: 'tag', op: 'in', value: [f.tag] });
    if (f.group_id) c.push({ field: 'group', op: 'in', value: [f.group_id] });
    if (f.q) c.push({ field: 'search', op: 'contains', value: f.q });
    if (state.importId) c.push({ field: 'import', op: 'is', value: Number(state.importId) });
    return { match: 'all', conditions: c };
  }

  function updateFilterBadge() {
    const n = Object.keys(filters()).filter((k) => k !== 'q').length;
    const b = $('#filters-count');
    b.textContent = String(n);
    b.classList.toggle('hidden', n === 0);
  }

  /* ------------------------------------------------------------ Liste */
  let seq = 0;
  async function load(resetPage = false) {
    if (resetPage) state.page = 1;
    const mine = ++seq;
    updateFilterBadge();
    try {
      const rows = await api('GET', '/api/contacts?' + query({ page: state.page, per_page: state.per, sort: state.sort, dir: state.dir }));
      if (mine !== seq) return; // une requête plus récente a pris le relais
      state.rows = rows;
      state.total = rows.meta.total;
      render(rows.meta);
    } catch (err) { toastErr(err); }
  }

  function render(meta) {
    const body = clear($('#rows'));
    const state_ = clear($('#state'));
    for (const c of state.rows) body.append(row(c));
    if (!state.rows.length) state_.append(empty('Aucun contact ne correspond à votre recherche.', 'address-book-off'));
    clear($('#pager')).append(pager(meta, (p) => { state.page = p; load(); }));
    $('#check-all').checked = state.rows.length > 0 && state.rows.every((c) => state.selected.has(c.id));
    renderBulk();
  }

  function row(c) {
    const check = h('input', { type: 'checkbox', class: 'check', 'aria-label': 'Sélectionner ' + c.phone_e164, checked: state.selected.has(c.id),
      onclick: (e) => { e.stopPropagation(); toggle(c.id, e.target.checked); } });
    const chips = [...c.groups.map((g) => h('span', { class: 'badge badge-brand' }, icon('users-group', 'text-xs'), g.name)),
      ...c.tags.map((t) => h('span', { class: 'chip' }, '#' + t))];
    return h('tr', { dataset: { row: c.id }, class: 'cursor-pointer', onclick: () => openCard(c.id) },
      h('td', {}, check),
      h('td', {},
        h('p', { class: 'font-medium' }, fullName(c) || '—'),
        c.email ? h('p', { class: 'text-xs text-mute' }, c.email) : null,
        global() ? h('p', { class: 'text-xs text-mute' }, c.organization_name) : null),
      h('td', { class: 'whitespace-nowrap font-mono text-[13px]' }, c.phone_e164),
      h('td', { class: 'hidden md:table-cell' }, opLabel(c.operator)),
      h('td', { class: 'hidden lg:table-cell' }, h('div', { class: 'flex max-w-xs flex-wrap gap-1' }, chips)),
      h('td', {}, statusBadge(c.status)),
      h('td', { class: 'hidden whitespace-nowrap text-mute xl:table-cell' }, dateFr(c.created_at)));
  }

  const global = () => cfg.global;

  /* -------------------------------------------------------- Sélection */
  function toggle(id, on) {
    state.allMatching = false;
    if (on) state.selected.add(id); else state.selected.delete(id);
    $('#check-all').checked = state.rows.every((c) => state.selected.has(c.id));
    renderBulk();
  }

  function renderBulk() {
    const bar = $('#bulkbar');
    const n = state.allMatching ? state.total : state.selected.size;
    bar.classList.toggle('hidden', n === 0);
    bar.classList.toggle('flex', n > 0);
    if (n === 0) return;
    $('#bulk-label').textContent = fmt(n) + (n > 1 ? ' contacts sélectionnés' : ' contact sélectionné');
    const all = $('#bulk-all');
    const pageFull = state.rows.length > 0 && state.rows.every((c) => state.selected.has(c.id));
    if (!state.allMatching && pageFull && state.total > state.selected.size) {
      all.textContent = 'Sélectionner les ' + fmt(state.total) + ' contacts du filtre';
      all.classList.remove('hidden');
    } else if (state.allMatching) {
      all.textContent = 'Annuler la sélection';
      all.classList.remove('hidden');
    } else all.classList.add('hidden');
  }

  function selection() {
    return state.allMatching ? { rules: rulesFromFilters() } : { ids: [...state.selected] };
  }

  async function bulk(action, params = {}) {
    const res = await api('POST', '/api/contacts/bulk', Object.assign({ action }, selection(), params));
    toast(fmt(res.affected) + ' contact(s) mis à jour.');
    state.selected.clear();
    state.allMatching = false;
    await Promise.all([load(), loadKpis(), loadFacets()]);
  }

  /* ------------------------------------------------------------- KPIs */
  async function loadKpis() {
    try {
      const s = await api('GET', '/api/contacts/stats');
      const t = s.totals;
      const box = clear($('#kpis'));
      const known = t.total ? Math.round(((t.total - (s.by_operator.find((o) => o.key === 'inconnu')?.count || 0)) / t.total) * 100) : null;
      box.append(
        kpi('Contacts', fmt(t.total), fmt(t.active) + ' actifs · ' + fmt(t.blocked) + ' bloqués'),
        kpi('Groupes', fmt(t.groups), fmt(t.segments) + ' segments'),
        kpi('Opérateur identifié', known === null ? '—' : pct(known), 'des numéros de la base'),
        kpi('Numéros valides (imports 90 j)', pct(s.imports_90d.valid_rate), fmt(s.imports_90d.duplicates) + ' doublons évités'));
    } catch (_) { /* indicateurs facultatifs */ }
  }

  async function loadFacets() {
    try {
      const [groups, tags] = await Promise.all([api('GET', '/api/contact-groups?per_page=100'), api('GET', '/api/contacts/tags')]);
      state.groups = groups;
      fillSelect($('#f-group'), 'Tous', groups.map((g) => [g.id, g.name + ' (' + g.contacts_count + ')']));
      fillSelect($('#f-tag'), 'Toutes', tags.map((t) => [t.tag, '#' + t.tag + ' (' + t.count + ')']));
    } catch (_) { /* facettes facultatives */ }
  }

  function fillSelect(sel, first, options) {
    const keep = sel.value;
    clear(sel).append(h('option', { value: '' }, first), ...options.map(([v, l]) => h('option', { value: v }, l)));
    sel.value = keep;
  }

  async function loadOrgs() {
    if (!cfg.global) return;
    const orgs = await api('GET', '/api/organizations?per_page=100');
    state.orgs = orgs;
    fillSelect($('#f-organization'), 'Toutes', orgs.map((o) => [o.id, o.name]));
  }

  /* ------------------------------------------------- Fiche + historique */
  const ACTIONS = { created: 'Créé', updated: 'Modifié', deleted: 'Supprimé', import_update: 'Mis à jour par import', group_added: 'Ajouté à un groupe',
    group_removed: 'Retiré d’un groupe', tag_added: 'Étiquette ajoutée', tag_removed: 'Étiquette retirée', merged: 'Fusion', merged_into: 'Fusionné dans un autre contact' };
  const FIELDS = { first_name: 'Prénom', last_name: 'Nom', email: 'E-mail', status: 'Statut', phone: 'Téléphone', attributes: 'Attributs', tags: 'Étiquettes', groups: 'Groupes', country: 'Pays', operator: 'Opérateur' };

  function changeLine(ch) {
    if (!ch) return '';
    return Object.entries(ch).map(([k, v]) => {
      const label = FIELDS[k] || k;
      if (Array.isArray(v) && v.length === 2) return label + ' : ' + short(v[0]) + ' → ' + short(v[1]);
      return label + ' : ' + short(v);
    }).join(' · ');
  }
  const short = (v) => (v === null || v === undefined || v === '' ? '∅' : typeof v === 'object' ? JSON.stringify(v).slice(0, 60) : String(v).slice(0, 60));

  async function openCard(id) {
    let c; let hist;
    try { [c, hist] = await Promise.all([api('GET', '/api/contacts/' + id), api('GET', '/api/contacts/' + id + '/history')]); } catch (err) { toastErr(err); return; }
    const attrs = Object.entries(c.attributes || {});
    const info = (label, value) => h('div', {}, h('dt', { class: 'text-xs uppercase tracking-wider text-mute' }, label), h('dd', { class: 'mt-0.5 text-sm font-medium' }, value || '—'));
    const body = h('div', { class: 'space-y-5' },
      h('dl', { class: 'grid grid-cols-2 gap-4' },
        info('Téléphone', c.phone_e164), info('Statut', statusBadge(c.status)),
        info('Nom', fullName(c)), info('E-mail', c.email),
        info('Pays', cfg.countries[c.country] || c.country), info('Opérateur', opLabel(c.operator)),
        info('Origine', SOURCE[c.source] || c.source), info('Ajouté le', dateFr(c.created_at))),
      h('div', {}, h('p', { class: 'label' }, 'Groupes'), c.groups.length ? h('div', { class: 'flex flex-wrap gap-1' }, c.groups.map((g) => h('span', { class: 'badge badge-brand' }, g.name))) : h('p', { class: 'text-sm text-mute' }, 'Aucun groupe')),
      h('div', {}, h('p', { class: 'label' }, 'Étiquettes'), c.tags.length ? h('div', { class: 'flex flex-wrap gap-1' }, c.tags.map((t) => h('span', { class: 'chip' }, '#' + t))) : h('p', { class: 'text-sm text-mute' }, 'Aucune étiquette')),
      attrs.length ? h('div', {}, h('p', { class: 'label' }, 'Champs personnalisés'), h('dl', { class: 'grid grid-cols-2 gap-2 text-sm' }, attrs.flatMap(([k, v]) => [h('dt', { class: 'text-mute' }, k), h('dd', {}, String(v))]))) : null,
      h('div', {}, h('p', { class: 'label' }, 'Historique'),
        hist.length ? h('ol', { class: 'space-y-2 border-l border-line pl-4' }, hist.map((e) => h('li', { class: 'text-sm' },
          h('p', { class: 'font-medium' }, ACTIONS[e.action] || e.action, h('span', { class: 'ml-2 text-xs font-normal text-mute' }, dateFr(e.created_at) + (e.user ? ' · ' + e.user : ''))),
          e.changes ? h('p', { class: 'text-xs text-mute' }, changeLine(e.changes)) : null))) : h('p', { class: 'text-sm text-mute' }, 'Aucun événement.')));
    const actions = [{ label: 'Fermer', class: 'btn-outline' }];
    if (cfg.canManage) {
      actions.unshift({ label: 'Supprimer', class: 'btn-danger', onclick: async (e, close) => {
        if (await confirmBox('Supprimer ce contact ? Il pourra être recréé avec le même numéro.', { label: 'Supprimer', danger: true })) {
          try { await api('DELETE', '/api/contacts/' + id); close(); toast('Contact supprimé.'); load(); loadKpis(); } catch (err) { toastErr(err); }
        }
      } }, { label: 'Modifier', class: 'btn-primary', onclick: (e, close) => { close(); openForm(c); } });
    }
    modal({ title: fullName(c) || c.phone_e164, body, actions, large: true });
  }

  /* ----------------------------------------------- Création / édition */
  const attrsToText = (a) => Object.entries(a || {}).map(([k, v]) => k + '=' + v).join('\n');
  function textToAttrs(t) {
    const out = {};
    for (const line of t.split('\n')) {
      const i = line.indexOf('=');
      if (i > 0) out[line.slice(0, i).trim().toLowerCase()] = line.slice(i + 1).trim();
    }
    return out;
  }

  function openForm(c = null) {
    const edit = c !== null;
    const orgSel = cfg.global && !edit ? selectInput('organization_id', [['', 'Choisir…'], ...(state.orgs || []).map((o) => [o.id, o.name])], $('#f-organization')?.value || '') : null;
    const groupBoxes = state.groups.map((g) => h('label', { class: 'flex items-center gap-2 text-sm' },
      h('input', { type: 'checkbox', class: 'check', name: 'group', value: g.id, checked: edit && c.groups.some((x) => x.id === g.id) }), g.name));
    const phone = textInput('phone', c ? c.phone_e164 : '', { placeholder: '+243 81 234 5678 ou 081 234 5678', required: true, inputmode: 'tel' });
    const country = selectInput('country', [['', 'Pays par défaut (RDC)'], ...Object.entries(cfg.countries).map(([k, n]) => [k, n])], '');
    const form = h('form', { class: 'space-y-4', novalidate: true },
      orgSel ? field('Organisation', orgSel) : null,
      field('Numéro de téléphone', phone, 'Format libre : il sera converti au format international.'),
      edit ? null : field('Pays du numéro (si sans indicatif)', country),
      h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Prénom', textInput('first_name', c?.first_name)), field('Nom', textInput('last_name', c?.last_name))),
      field('E-mail', textInput('email', c?.email, { type: 'email' })),
      field('Statut', selectInput('status', [['active', 'Actif'], ['blocked', 'Bloqué (exclu des envois)']], c?.status || 'active')),
      field('Étiquettes', textInput('tags', (c?.tags || []).join(', '), { placeholder: 'vip, kinshasa' }), 'Séparées par des virgules.'),
      state.groups.length ? h('div', {}, h('p', { class: 'label' }, 'Groupes'), h('div', { class: 'grid gap-2 sm:grid-cols-2' }, groupBoxes)) : null,
      h('div', {}, h('label', { class: 'label' }, 'Champs personnalisés'),
        h('textarea', { class: 'input !h-24 py-2.5 font-mono text-[13px]', name: 'attributes', placeholder: 'ville=Lubumbashi\nsociete=ACME' }, attrsToText(c?.attributes)),
        h('p', { class: 'hint' }, 'Une ligne par champ : clé=valeur.')),
      h('p', { class: 'hidden text-sm text-red-600', id: 'contact-error', role: 'alert' }));

    const m = modal({ title: edit ? 'Modifier le contact' : 'Nouveau contact', body: form, actions: [
      { label: 'Annuler', class: 'btn-outline' },
      { label: edit ? 'Enregistrer' : 'Créer', class: 'btn-primary', onclick: async (e, close) => {
        const fd = new FormData(form);
        const body = {
          first_name: fd.get('first_name'), last_name: fd.get('last_name'), email: fd.get('email'), status: fd.get('status'),
          tags: String(fd.get('tags') || '').split(',').map((t) => t.trim()).filter(Boolean),
          group_ids: fd.getAll('group').map(Number),
          attributes: textToAttrs(String(fd.get('attributes') || '')),
        };
        if (!edit || fd.get('phone') !== c.phone_e164) body.phone = fd.get('phone');
        if (!edit && fd.get('country')) body.country = fd.get('country');
        if (orgSel) body.organization_id = fd.get('organization_id');
        e.target.disabled = true;
        try {
          if (edit) await api('PUT', '/api/contacts/' + c.id, body); else await api('POST', '/api/contacts', body);
          close(); toast(edit ? 'Contact mis à jour.' : 'Contact créé.');
          load(); loadKpis(); loadFacets();
        } catch (err) {
          const box = $('#contact-error', m.box);
          box.textContent = err.message + (err.fields ? ' ' + Object.values(err.fields).flat().join(' ') : '');
          box.classList.remove('hidden');
          e.target.disabled = false;
        }
      } },
    ] });
    form.addEventListener('submit', (e) => { e.preventDefault(); $('.btn-primary', m.foot).click(); });
  }

  /* ---------------------------------------------------- Actions groupées */
  async function pickGroup(title, label, action) {
    if (!state.groups.length) { toast('Créez d’abord un groupe (onglet Groupes).', false); return; }
    const sel = selectInput('g', state.groups.map((g) => [g.id, g.name]));
    modal({ title, body: field('Groupe', sel), actions: [{ label: 'Annuler' }, { label, class: 'btn-primary', onclick: async (e, close) => {
      try { await bulk(action, { group_id: Number(sel.value) }); close(); } catch (err) { toastErr(err); }
    } }] });
  }

  async function onBulk(action) {
    if (action === 'group_add') return pickGroup('Ajouter à un groupe', 'Ajouter', 'group_add');
    if (action === 'group_remove') return pickGroup('Retirer d’un groupe', 'Retirer', 'group_remove');
    if (action === 'tag_add') {
      const inp = textInput('tag', '', { placeholder: 'ex. vip' });
      return modal({ title: 'Ajouter une étiquette', body: field('Étiquette', inp), actions: [{ label: 'Annuler' }, { label: 'Ajouter', class: 'btn-primary', onclick: async (e, close) => {
        try { await bulk('tag_add', { tag: inp.value }); close(); } catch (err) { toastErr(err); }
      } }] });
    }
    const n = state.allMatching ? state.total : state.selected.size;
    const msg = { delete: 'Supprimer ' + fmt(n) + ' contact(s) ?', block: 'Bloquer ' + fmt(n) + ' contact(s) ? Ils seront exclus des envois.', unblock: 'Débloquer ' + fmt(n) + ' contact(s) ?' }[action];
    if (await confirmBox(msg, { label: action === 'delete' ? 'Supprimer' : 'Confirmer', danger: action === 'delete' })) {
      try { await bulk(action); } catch (err) { toastErr(err); }
    }
  }

  /* ----------------------------------------------------------- Doublons */
  async function openDuplicates() {
    const out = h('div', { class: 'space-y-4' });
    const by = selectInput('by', [['phone_tail', 'Même numéro (formats différents)'], ['email', 'Même e-mail'], ['name', 'Même nom complet']], 'phone_tail');
    const m = modal({ title: 'Doublons dans la base', large: true, body: h('div', { class: 'space-y-4' }, field('Rechercher par', by), out), actions: [{ label: 'Fermer' }] });
    async function scan() {
      clear(out).append(h('p', { class: 'text-sm text-mute' }, 'Analyse…'));
      try {
        const groups = await api('GET', '/api/contacts/duplicates?by=' + by.value);
        clear(out);
        if (!groups.length) { out.append(empty('Aucun doublon détecté.', 'circle-check')); return; }
        for (const g of groups) out.append(dupGroup(g, scan));
      } catch (err) { clear(out); toastErr(err); }
    }
    by.addEventListener('change', scan);
    scan();
    return m;
  }

  function dupGroup(g, refresh) {
    const name = 'keep-' + Math.random().toString(36).slice(2);
    const rows = g.contacts.map((c, i) => h('label', { class: 'flex items-center gap-3 rounded-xl bg-surface2 px-3 py-2 text-sm' },
      h('input', { type: 'radio', name, value: c.id, checked: i === 0 }),
      h('span', { class: 'font-mono text-[13px]' }, c.phone_e164),
      h('span', { class: 'flex-1 truncate' }, fullName(c) || c.email || '—'),
      h('span', { class: 'text-xs text-mute' }, dateFr(c.created_at))));
    const box = h('div', { class: 'space-y-2 rounded-2xl p-3 ring-1 ring-line' },
      h('p', { class: 'text-xs font-semibold uppercase tracking-wider text-mute' }, g.count + ' contacts · clé « ' + g.key + ' »'), rows,
      h('div', { class: 'flex justify-end' }, h('button', { type: 'button', class: 'btn btn-soft btn-sm', onclick: async (e) => {
        const keep = Number(box.querySelector('input:checked').value);
        const merge = g.contacts.map((c) => c.id).filter((id) => id !== keep);
        e.target.disabled = true;
        try { await api('POST', '/api/contacts/merge', { keep_id: keep, merge_ids: merge }); toast('Contacts fusionnés.'); load(); loadKpis(); refresh(); } catch (err) { toastErr(err); e.target.disabled = false; }
      } }, icon('arrows-join'), 'Fusionner vers le contact choisi')));
    return box;
  }

  /* ------------------------------------------------------------- Init */
  const reload = debounce(() => load(true), 300);
  $('#f-q').addEventListener('input', reload);
  for (const id of Object.values(filterIds)) document.getElementById(id)?.addEventListener('change', () => { state.selected.clear(); state.allMatching = false; load(true); });
  $('#btn-filters').addEventListener('click', (e) => {
    const box = $('#filters');
    const open = box.classList.contains('hidden');
    box.classList.toggle('hidden', !open);
    box.classList.toggle('grid', open);
    e.currentTarget.setAttribute('aria-expanded', String(open));
  });
  $('#btn-reset').addEventListener('click', () => {
    $('#f-q').value = '';
    for (const id of Object.values(filterIds)) { const el = document.getElementById(id); if (el) el.value = ''; }
    load(true);
  });
  $('#check-all').addEventListener('change', (e) => {
    state.allMatching = false;
    for (const c of state.rows) { if (e.target.checked) state.selected.add(c.id); else state.selected.delete(c.id); }
    render({ page: state.page, per_page: state.per, total: state.total });
  });
  $('#bulk-all').addEventListener('click', () => { state.allMatching = !state.allMatching; if (!state.allMatching) state.selected.clear(); render({ page: state.page, per_page: state.per, total: state.total }); });
  document.querySelectorAll('[data-bulk]').forEach((b) => b.addEventListener('click', () => onBulk(b.dataset.bulk)));
  document.querySelectorAll('[data-sort]').forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.sort;
    state.dir = state.sort === k && state.dir === 'asc' ? 'desc' : 'asc';
    state.sort = k;
    load(true);
  }));
  $('#btn-new')?.addEventListener('click', () => openForm());
  $('#btn-duplicates')?.addEventListener('click', openDuplicates);
  $('#btn-export')?.addEventListener('click', () => { window.location.href = url('/api/contacts/export?' + query()); });

  const preset = new URLSearchParams(location.search);
  Promise.all([loadFacets(), loadOrgs()]).finally(() => {
    if (preset.get('group_id') && $('#f-group')) { $('#f-group').value = preset.get('group_id'); $('#btn-filters').click(); }
    if (preset.get('import_id')) state.importId = preset.get('import_id');
    load();
  });
  loadKpis();
})();
