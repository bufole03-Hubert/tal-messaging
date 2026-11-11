/* TAL Messaging — page Segments : liste + constructeur de règles avec compteur en direct. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, debounce, dateFr, opLabel, OPERATORS, SOURCE, modal, confirmBox, field, textInput, selectInput, empty, toastErr, fullName } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#segments-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  let groups = [];
  let orgs = [];

  const COUNTRIES = cfg.countries || {};
  // Valeurs proposées pour les champs à liste.
  const LABELS = { country: 'Pays', operator: 'Opérateur', status: 'Statut', source: 'Origine', group: 'Groupe', tag: 'Étiquette', name: 'Nom contient', phone: 'Téléphone',
    created_at: 'Date d’ajout', has_email: 'A un e-mail', no_group: 'Sans groupe', search: 'Recherche libre', import: 'Import n°' };
  const OPS = { in: 'est', not_in: 'n’est pas', contains: 'contient', starts_with: 'commence par', after: 'après le', before: 'avant le', is: 'est' };
  const FIELDS = {
    operator: ['in', 'not_in'], status: ['in', 'not_in'], source: ['in', 'not_in'], country: ['in', 'not_in'], group: ['in', 'not_in'], tag: ['in', 'not_in'],
    phone: ['starts_with', 'contains'], name: ['contains'], created_at: ['after', 'before'], has_email: ['is'], no_group: ['is'],
  };

  function options(field_) {
    switch (field_) {
      case 'operator': return Object.entries(OPERATORS);
      case 'status': return [['active', 'Actif'], ['blocked', 'Bloqué']];
      case 'source': return Object.entries(SOURCE);
      case 'country': return Object.entries(COUNTRIES);
      case 'group': return groups.map((g) => [String(g.id), g.name]);
      default: return null;
    }
  }

  /* --------------------------------------------------- Constructeur */
  function builder(initial, onChange) {
    const rules = JSON.parse(JSON.stringify(initial || { match: 'all', conditions: [] }));
    const box = h('div', { class: 'space-y-3' });

    function valueControl(c) {
      const t = c.field;
      if (t === 'has_email' || t === 'no_group') {
        return h('span', { class: 'text-sm text-mute' }, 'vrai');
      }
      if (t === 'created_at') {
        return h('input', { class: 'input', type: 'date', value: c.value || '', 'aria-label': 'Date', onchange: (e) => { c.value = e.target.value; onChange(); } });
      }
      const opts = options(t);
      if (opts) {
        const set = new Set((Array.isArray(c.value) ? c.value : []).map(String));
        return h('div', { class: 'flex max-h-28 flex-wrap gap-x-4 gap-y-1.5 overflow-y-auto rounded-xl bg-surface2 px-3 py-2' }, opts.map(([v, l]) => h('label', { class: 'flex items-center gap-1.5 text-sm' },
          h('input', { type: 'checkbox', class: 'check', checked: set.has(String(v)), onchange: (e) => {
            if (e.target.checked) set.add(String(v)); else set.delete(String(v));
            c.value = [...set];
            onChange();
          } }), l)));
      }
      const isList = t === 'tag';
      return h('input', { class: 'input', type: 'text', maxlength: 100, value: isList ? (c.value || []).join(', ') : (c.value || ''), placeholder: isList ? 'vip, kinshasa' : '', 'aria-label': 'Valeur',
        oninput: (e) => { c.value = isList ? e.target.value.split(',').map((x) => x.trim()).filter(Boolean) : e.target.value; onChange(); } });
    }

    function defaultValue(f) { return f === 'has_email' || f === 'no_group' ? true : (options(f) || f === 'tag') ? [] : ''; }

    function draw() {
      clear(box);
      box.append(h('div', { class: 'flex items-center gap-2 text-sm' }, 'Les contacts qui correspondent à',
        (() => { const s = selectInput('match', [['all', 'toutes'], ['any', 'au moins une']], rules.match); s.className = 'input !h-9 !w-auto'; s.addEventListener('change', () => { rules.match = s.value; onChange(); }); return s; })(),
        'des conditions suivantes :'));
      rules.conditions.forEach((c, i) => {
        const fsel = selectInput('f', Object.keys(FIELDS).map((k) => [k, LABELS[k]]), c.field);
        fsel.addEventListener('change', () => { c.field = fsel.value; c.op = FIELDS[c.field][0]; c.value = defaultValue(c.field); draw(); onChange(); });
        const osel = selectInput('o', FIELDS[c.field].map((o) => [o, OPS[o]]), c.op);
        osel.addEventListener('change', () => { c.op = osel.value; onChange(); });
        box.append(h('div', { class: 'grid items-start gap-2 rounded-2xl p-3 ring-1 ring-line sm:grid-cols-[10rem_9rem_1fr_auto]' },
          fsel, osel, valueControl(c),
          h('button', { type: 'button', class: 'btn btn-ghost btn-icon btn-sm', 'aria-label': 'Supprimer la condition', onclick: () => { rules.conditions.splice(i, 1); draw(); onChange(); } }, icon('trash'))));
      });
      box.append(h('button', { type: 'button', class: 'btn btn-soft btn-sm', onclick: () => {
        if (rules.conditions.length >= 20) return;
        rules.conditions.push({ field: 'operator', op: 'in', value: [] }); draw(); onChange();
      } }, icon('plus'), 'Ajouter une condition'));
    }
    draw();
    return { el: box, rules: () => rules };
  }

  /* Les règles envoyées ne gardent que les conditions complètes. */
  function cleanRules(r) {
    return { match: r.match, conditions: r.conditions.filter((c) => !(Array.isArray(c.value) ? c.value.length === 0 : c.value === '' || c.value === null)) };
  }

  /* ------------------------------------------------------- Formulaire */
  function openForm(seg = null) {
    const org = cfg.global && !seg ? selectInput('organization_id', [['', 'Choisir…'], ...orgs.map((o) => [o.id, o.name])]) : null;
    const name = textInput('name', seg?.name, { maxlength: 100 });
    const desc = textInput('description', seg?.description, { maxlength: 255 });
    const counter = h('p', { class: 'text-2xl font-bold tabular-nums' }, '—');
    const sample = h('ul', { class: 'mt-2 space-y-1 text-sm text-mute' });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    let seq = 0;
    const refresh = debounce(async () => {
      const mine = ++seq;
      try {
        const body = { rules: cleanRules(b.rules()) };
        if (org) { if (!org.value) { counter.textContent = '—'; clear(sample); return; } body.organization_id = org.value; }
        else if (cfg.global && seg) body.organization_id = seg.organization_id;
        const r = await api('POST', '/api/contacts/count', body);
        if (mine !== seq) return;
        counter.textContent = fmt(r.count) + (r.count > 1 ? ' contacts' : ' contact');
        clear(sample).append(...r.sample.map((c) => h('li', {}, (fullName(c) || '—') + ' · ' + c.phone_e164)));
      } catch (e) { counter.textContent = '—'; }
    }, 350);
    const b = builder(seg?.rules, refresh);
    org?.addEventListener('change', refresh);
    const form = h('form', { class: 'space-y-4', novalidate: true },
      org ? field('Organisation', org) : null,
      h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Nom du segment', name), field('Description (facultatif)', desc)),
      b.el,
      h('div', { class: 'rounded-2xl bg-brand/10 p-4' }, h('p', { class: 'text-xs font-semibold uppercase tracking-wider text-brand' }, 'Contacts correspondants'), counter, sample), err);
    const m = modal({ title: seg ? 'Modifier le segment' : 'Nouveau segment', large: true, body: form, actions: [{ label: 'Annuler' },
      { label: seg ? 'Enregistrer' : 'Créer le segment', class: 'btn-primary', onclick: async (e, close) => {
        const body = { name: name.value.trim(), description: desc.value.trim(), rules: cleanRules(b.rules()) };
        if (org) body.organization_id = org.value;
        e.target.disabled = true;
        try {
          if (seg) await api('PUT', '/api/segments/' + seg.id, body); else await api('POST', '/api/segments', body);
          close(); toast(seg ? 'Segment mis à jour.' : 'Segment créé.'); load();
        } catch (ex) { err.textContent = ex.message + (ex.fields ? ' ' + Object.values(ex.fields).flat().join(' ') : ''); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
    form.addEventListener('submit', (e) => e.preventDefault());
    refresh();
  }

  function describe(rules) {
    if (!rules.conditions.length) return 'Tous les contacts';
    return rules.conditions.map((c) => {
      let v = c.value;
      if (c.field === 'group') v = (Array.isArray(v) ? v : []).map((id) => groups.find((g) => String(g.id) === String(id))?.name || '#' + id);
      if (c.field === 'operator') v = (Array.isArray(v) ? v : []).map(opLabel);
      if (c.field === 'has_email' || c.field === 'no_group') v = '';
      return (LABELS[c.field] || c.field) + ' ' + (OPS[c.op] || c.op) + ' ' + (Array.isArray(v) ? v.join(' / ') : v);
    }).join(rules.match === 'any' ? ' OU ' : ' ET ');
  }

  async function load() {
    try {
      const segs = await api('GET', '/api/segments?per_page=100');
      const list = clear($('#s-list'));
      for (const s of segs) {
        list.append(h('article', { class: 'card flex flex-col gap-3 !p-5', dataset: { row: s.id } },
          h('div', { class: 'flex items-start justify-between gap-3' },
            h('div', { class: 'min-w-0' }, h('h3', { class: 'truncate text-base font-bold' }, s.name), s.description ? h('p', { class: 'truncate text-xs text-mute' }, s.description) : null),
            h('span', { class: 'badge badge-brand shrink-0' }, s.contacts_count === null ? 'Règles à corriger' : fmt(s.contacts_count) + ' contact' + (s.contacts_count > 1 ? 's' : ''))),
          h('p', { class: 'line-clamp-3 text-sm text-mute' }, describe(s.rules)),
          h('div', { class: 'mt-auto flex items-center justify-between' },
            h('span', { class: 'text-xs text-mute' }, 'Créé le ' + dateFr(s.created_at)),
            cfg.canManage ? h('div', { class: 'flex gap-1' },
              h('button', { type: 'button', class: 'btn btn-ghost btn-icon btn-sm', 'aria-label': 'Modifier', onclick: () => openForm(s) }, icon('pencil')),
              h('button', { type: 'button', class: 'btn btn-ghost btn-icon btn-sm', 'aria-label': 'Supprimer', onclick: () => remove(s) }, icon('trash'))) : null)));
      }
      clear($('#s-state'));
      if (!segs.length) $('#s-state').append(empty('Aucun segment. Créez-en un pour cibler une partie de votre base.', 'filter-off'));
    } catch (err) { toastErr(err); }
  }

  async function remove(s) {
    if (!(await confirmBox('Supprimer le segment « ' + s.name + ' » ?', { label: 'Supprimer', danger: true }))) return;
    try { await api('DELETE', '/api/segments/' + s.id); toast('Segment supprimé.'); load(); } catch (err) { toastErr(err); }
  }

  $('#s-new')?.addEventListener('click', () => openForm());
  Promise.all([
    api('GET', '/api/contact-groups?per_page=100').then((g) => { groups = g; }).catch(() => {}),
    cfg.global ? api('GET', '/api/organizations?per_page=100').then((o) => { orgs = o; }).catch(() => {}) : Promise.resolve(),
    api('GET', '/api/contacts/stats').then(() => {}).catch(() => {}),
  ]).finally(load);
})();
