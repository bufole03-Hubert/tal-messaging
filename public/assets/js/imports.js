/* TAL Messaging — Imports CSV : envoi → mappage → traitement par lots → rapport. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, pct, dateFr, modal, field, textInput, selectInput, pager, empty, toastErr } = window.TALUI;
  const { api, upload, toast, url } = window.TAL;
  const root = $('#imports-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  let page = 1;
  let groups = [];
  let orgs = [];

  const STATUS = { uploaded: ['Mappage en attente', 'badge'], processing: ['En cours', 'badge badge-brand'], completed: ['Terminé', 'badge badge-ok'],
    failed: ['Échec', 'badge badge-danger'], cancelled: ['Annulé', 'badge badge-warn'] };
  const badge = (s) => { const [l, c] = STATUS[s] || [s, 'badge']; return h('span', { class: c }, l); };

  /* -------------------------------------------------------- Historique */
  async function loadHistory() {
    try {
      const rows = await api('GET', '/api/contact-imports?page=' + page + '&per_page=15');
      const body = clear($('#i-rows'));
      for (const i of rows) {
        body.append(h('tr', { dataset: { row: i.id }, class: 'cursor-pointer', onclick: () => openReport(i.id) },
          h('td', {}, h('p', { class: 'max-w-[14rem] truncate font-medium' }, i.filename), cfg.global ? h('p', { class: 'text-xs text-mute' }, i.organization_name) : null),
          h('td', {}, badge(i.status)),
          h('td', { class: 'hidden text-right tabular-nums sm:table-cell' }, fmt(i.total_rows)),
          h('td', { class: 'hidden text-right tabular-nums md:table-cell' }, fmt(i.created_rows)),
          h('td', { class: 'hidden text-right tabular-nums md:table-cell' }, fmt(i.duplicate_rows)),
          h('td', { class: 'hidden text-right tabular-nums md:table-cell' }, fmt(i.invalid_rows)),
          h('td', { class: 'hidden whitespace-nowrap text-mute lg:table-cell' }, dateFr(i.created_at)),
          h('td', {}, icon('chevron-right', 'text-mute'))));
      }
      clear($('#i-state'));
      if (!rows.length) $('#i-state').append(empty('Aucun import pour le moment.', 'file-import'));
      clear($('#i-pager')).append(pager(rows.meta, (p) => { page = p; loadHistory(); }));
    } catch (err) { toastErr(err); }
  }

  /* ----------------------------------------------------------- Assistant */
  const wiz = $('#wizard');

  function stepUpload() {
    if (!wiz) return;
    const org = cfg.global ? selectInput('organization_id', [['', 'Choisir l’organisation…'], ...orgs.map((o) => [o.id, o.name])]) : null;
    const input = h('input', { type: 'file', accept: '.csv,.txt,text/csv', class: 'sr-only', id: 'imp-file' });
    const zone = h('label', { class: 'dropzone', for: 'imp-file' }, icon('cloud-upload', 'text-4xl text-brand'),
      h('span', { class: 'font-semibold' }, 'Déposez votre fichier CSV ici'), h('span', { class: 'text-sm text-mute' }, 'ou cliquez pour le choisir'));
    const err = h('p', { class: 'mt-3 hidden text-sm text-red-600', role: 'alert' });
    clear(wiz).append(org ? h('div', { class: 'mb-4 max-w-sm' }, field('Organisation', org)) : null, zone, input, err);

    async function send(file) {
      if (!file) return;
      if (org && !org.value) { err.textContent = 'Choisissez d’abord l’organisation.'; err.classList.remove('hidden'); return; }
      err.classList.add('hidden');
      zone.classList.add('opacity-60');
      zone.querySelector('span').textContent = 'Analyse du fichier…';
      const fd = new FormData();
      fd.append('file', file);
      if (org) fd.append('organization_id', org.value);
      try { stepMap(await upload('/api/contact-imports', fd)); loadHistory(); } catch (e) {
        err.textContent = e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');
        err.classList.remove('hidden');
        stepUpload();
        $('#wizard p[role="alert"]').textContent = err.textContent;
        $('#wizard p[role="alert"]').classList.remove('hidden');
      }
    }
    input.addEventListener('change', () => send(input.files[0]));
    ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.remove('is-over'); }));
    zone.addEventListener('drop', (e) => send(e.dataTransfer.files[0]));
  }

  function colSelect(name, columns, selected, optional = true) {
    return selectInput(name, [...(optional ? [['', '— Ne pas importer —']] : [['', 'Choisir la colonne…']]), ...columns.map((c, i) => [i, c])], selected ?? '');
  }

  function stepMap(info) {
    const m = info.suggested_mapping;
    const sel = {
      phone: colSelect('phone', info.columns, m.phone, false), first_name: colSelect('first_name', info.columns, m.first_name),
      last_name: colSelect('last_name', info.columns, m.last_name), email: colSelect('email', info.columns, m.email),
      country: colSelect('country', info.columns, m.country), tags: colSelect('tags', info.columns, m.tags),
    };
    const customs = h('div', { class: 'space-y-2' });
    const customRows = [];
    const addCustom = () => {
      const key = textInput('key', '', { placeholder: 'ex. ville', maxlength: 40, class: 'input' });
      const col = colSelect('col', info.columns, '', false);
      const row = h('div', { class: 'grid gap-2 sm:grid-cols-[1fr_1fr_auto]' }, key, col,
        h('button', { type: 'button', class: 'btn btn-ghost btn-icon', 'aria-label': 'Retirer', onclick: () => { row.remove(); customRows.splice(customRows.indexOf(entry), 1); } }, icon('x')));
      const entry = { key, col };
      customRows.push(entry);
      customs.append(row);
    };

    const mode = (v, l, d, checked) => h('label', { class: 'flex cursor-pointer items-start gap-2.5 rounded-xl p-3 ring-1 ring-line' },
      h('input', { type: 'radio', name: 'dup', value: v, checked, class: 'mt-1' }), h('span', {}, h('span', { class: 'block text-sm font-semibold' }, l), h('span', { class: 'block text-xs text-mute' }, d)));
    const country = selectInput('default_country', Object.entries(cfg.countries), cfg.defaultCountry);
    const groupSel = selectInput('group_id', [['', 'Aucun'], ...groups.map((g) => [g.id, g.name])]);
    const newGroup = textInput('new_group_name', '', { placeholder: 'Ou créer un groupe : ex. Clients Lubumbashi', maxlength: 100 });
    const tags = textInput('tags', '', { placeholder: 'ex. salon-2026, vip' });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });

    const preview = h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {}, info.columns.map((c, i) => h('th', {}, h('span', { class: 'text-mute' }, i + 1 + '. '), c)))),
      h('tbody', {}, info.preview.map((r) => h('tr', {}, info.columns.map((_, i) => h('td', { class: 'max-w-[12rem] truncate' }, r[i] ?? '')))))));

    clear(wiz).append(
      h('div', { class: 'mb-4 flex flex-wrap items-center justify-between gap-2' },
        h('p', { class: 'text-sm' }, icon('file-text', 'mr-1 text-mute'), h('strong', {}, info.filename), ' — ' + fmt(info.total_rows) + ' lignes détectées'),
        h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: async () => { await api('POST', '/api/contact-imports/' + info.id + '/cancel').catch(() => {}); stepUpload(); loadHistory(); } }, icon('x'), 'Annuler')),
      h('p', { class: 'label' }, 'Aperçu (5 premières lignes)'), preview,
      h('h4', { class: 'mb-3 mt-6 text-sm font-bold' }, '1. Associez vos colonnes'),
      h('div', { class: 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3' },
        field('Numéro de téléphone *', sel.phone), field('Prénom', sel.first_name), field('Nom', sel.last_name),
        field('E-mail', sel.email), field('Pays', sel.country), field('Étiquettes (séparées par ; ou ,)', sel.tags)),
      h('div', { class: 'mt-4' }, h('p', { class: 'label' }, 'Champs personnalisés'), customs,
        h('button', { type: 'button', class: 'btn btn-soft btn-sm mt-2', onclick: addCustom }, icon('plus'), 'Ajouter un champ')),
      h('h4', { class: 'mb-3 mt-6 text-sm font-bold' }, '2. Que faire des numéros déjà présents ?'),
      h('div', { class: 'grid gap-3 md:grid-cols-3' },
        mode('skip', 'Ignorer', 'Garde le contact existant, signale le doublon.', true),
        mode('update', 'Mettre à jour', 'Les valeurs du fichier remplacent celles de la base.', false),
        mode('merge', 'Compléter', 'Ne remplit que les champs vides du contact existant.', false)),
      h('h4', { class: 'mb-3 mt-6 text-sm font-bold' }, '3. Options'),
      h('div', { class: 'grid gap-4 sm:grid-cols-2 lg:grid-cols-4' },
        field('Pays des numéros sans indicatif', country), field('Ajouter à un groupe', groupSel), field('Nouveau groupe', newGroup), field('Étiquettes pour tous', tags)),
      err,
      h('div', { class: 'mt-6 flex justify-end' }, h('button', { type: 'button', class: 'btn btn-primary', onclick: async (e) => {
        const num = (s) => (s.value === '' ? null : Number(s.value));
        const mapping = { phone: num(sel.phone), first_name: num(sel.first_name), last_name: num(sel.last_name), email: num(sel.email), country: num(sel.country), tags: num(sel.tags),
          custom: customRows.filter((r) => r.key.value.trim() && r.col.value !== '').map((r) => ({ key: r.key.value.trim(), column: Number(r.col.value) })) };
        const options = { duplicate_mode: wiz.querySelector('input[name=dup]:checked').value, default_country: country.value,
          group_id: groupSel.value ? Number(groupSel.value) : null, new_group_name: groupSel.value ? '' : newGroup.value.trim(),
          tags: tags.value.split(/[,;]/).map((t) => t.trim()).filter(Boolean) };
        e.target.disabled = true;
        try { await api('POST', '/api/contact-imports/' + info.id + '/start', { mapping, options }); stepRun(info.id, info.total_rows); loadHistory(); } catch (ex) {
          err.textContent = ex.message + (ex.fields ? ' ' + Object.values(ex.fields).flat().join(' ') : '');
          err.classList.remove('hidden');
          e.target.disabled = false;
        }
      } }, icon('player-play'), 'Lancer l’import')));
    addCustomIfEmpty();
    function addCustomIfEmpty() { /* aucun champ personnalisé par défaut */ }
  }

  /* -------------------------------------------------------- Traitement */
  async function stepRun(id, total) {
    const bar = h('div', { class: 'progress-bar' });
    bar.style.width = '0%';
    const label = h('p', { class: 'text-sm font-semibold' }, 'Import en cours…');
    const detail = h('p', { class: 'text-xs text-mute' }, '0 / ' + fmt(total) + ' lignes');
    let cancelled = false;
    clear(wiz).append(h('div', { class: 'mx-auto max-w-xl space-y-3 py-4' }, label, h('div', { class: 'progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100' }, bar), detail,
      h('p', { class: 'text-xs text-mute' }, 'Vous pouvez laisser cette page ouverte : le traitement se fait par lots. Fermer la page met l’import en pause (reprise possible depuis l’historique).'),
      h('button', { type: 'button', class: 'btn btn-outline btn-sm', onclick: async () => { cancelled = true; await api('POST', '/api/contact-imports/' + id + '/cancel').catch(() => {}); } }, icon('player-stop'), 'Annuler')));
    let st;
    try {
      do {
        st = await api('POST', '/api/contact-imports/' + id + '/process');
        bar.style.width = st.percent + '%';
        detail.textContent = fmt(st.processed_rows) + ' / ' + fmt(st.total_rows) + ' lignes · ' + fmt(st.created_rows) + ' créés · ' + fmt(st.duplicate_rows) + ' doublons · ' + fmt(st.invalid_rows) + ' invalides';
      } while (st.status === 'processing' && !cancelled);
    } catch (err) { toastErr(err); loadHistory(); stepUpload(); return; }
    loadHistory();
    stepUpload();
    if (st.status === 'completed') { toast('Import terminé.'); openReport(id); } else if (cancelled || st.status === 'cancelled') toast('Import annulé.', false);
  }

  /* ----------------------------------------------------------- Rapport */
  async function openReport(id) {
    let imp;
    try { imp = await api('GET', '/api/contact-imports/' + id); } catch (err) { toastErr(err); return; }
    const total = Math.max(1, imp.processed_rows);
    const validRate = imp.processed_rows ? ((imp.processed_rows - imp.invalid_rows) / total) * 100 : null;
    const tile = (l, v, cls = '') => h('div', { class: 'kpi' }, h('p', { class: 'kpi-label' }, l), h('p', { class: 'kpi-value ' + cls }, fmt(v)));
    const list = h('div', {});
    let kind = ''; let ep = 1;
    const kinds = selectInput('kind', [['', 'Toutes les erreurs'], ['invalid', 'Numéros invalides'], ['duplicate', 'Doublons']]);
    async function loadErrors() {
      try {
        const rows = await api('GET', '/api/contact-imports/' + id + '/errors?per_page=15&page=' + ep + (kind ? '&kind=' + kind : ''));
        clear(list);
        if (!rows.length) { list.append(empty('Aucune ligne rejetée.', 'circle-check')); return; }
        list.append(h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' },
          h('thead', {}, h('tr', {}, h('th', {}, 'Ligne'), h('th', {}, 'Type'), h('th', {}, 'Numéro saisi'), h('th', {}, 'Motif'))),
          h('tbody', {}, rows.map((r) => h('tr', {}, h('td', { class: 'tabular-nums' }, r.line_number),
            h('td', {}, h('span', { class: r.kind === 'invalid' ? 'badge badge-danger' : 'badge badge-warn' }, r.kind === 'invalid' ? 'Invalide' : 'Doublon')),
            h('td', { class: 'font-mono text-[13px]' }, r.phone_raw || '—'), h('td', { class: 'text-mute' }, r.reason)))))), pager(rows.meta, (p) => { ep = p; loadErrors(); }));
      } catch (err) { toastErr(err); }
    }
    kinds.addEventListener('change', () => { kind = kinds.value; ep = 1; loadErrors(); });
    const body = h('div', { class: 'space-y-5' },
      h('div', { class: 'flex flex-wrap items-center gap-2 text-sm' }, badge(imp.status), h('span', { class: 'text-mute' }, imp.filename + ' · ' + dateFr(imp.created_at) + (imp.created_by_name ? ' · ' + imp.created_by_name : ''))),
      h('div', { class: 'grid grid-cols-2 gap-3 md:grid-cols-4' }, tile('Lignes lues', imp.processed_rows), tile('Créés', imp.created_rows, 'text-emerald-600'), tile('Mis à jour', imp.updated_rows), tile('Doublons', imp.duplicate_rows)),
      h('div', { class: 'grid grid-cols-2 gap-3 md:grid-cols-4' }, tile('Invalides', imp.invalid_rows, imp.invalid_rows ? 'text-red-600' : ''),
        h('div', { class: 'kpi' }, h('p', { class: 'kpi-label' }, 'Numéros valides'), h('p', { class: 'kpi-value' }, pct(validRate === null ? null : Math.round(validRate * 10) / 10)))),
      (imp.invalid_rows || imp.duplicate_rows) ? h('div', { class: 'space-y-3' },
        h('div', { class: 'flex flex-wrap items-end justify-between gap-3' }, h('div', { class: 'w-full sm:w-64' }, field('Afficher', kinds)),
          h('a', { class: 'btn btn-outline', href: url('/api/contact-imports/' + id + '/errors.csv') }, icon('download'), 'Télécharger les lignes rejetées (CSV)')), list) : null);
    modal({ title: 'Rapport d’import', large: true, body, actions: [{ label: 'Fermer' }, ...(imp.created_rows || imp.updated_rows ? [{ label: 'Voir les contacts importés', class: 'btn-primary', onclick: () => { window.location.href = url('/contacts?import_id=' + id); } }] : []),
      ...(imp.status === 'processing' && cfg.canManage ? [{ label: 'Reprendre', class: 'btn-primary', onclick: (e, close) => { close(); stepRun(id, imp.total_rows); } }] : [])] });
    if (imp.invalid_rows || imp.duplicate_rows) loadErrors();
  }

  /* -------------------------------------------------------------- Init */
  Promise.all([
    api('GET', '/api/contact-groups?per_page=100').then((g) => { groups = g; }).catch(() => {}),
    cfg.global ? api('GET', '/api/organizations?per_page=100').then((o) => { orgs = o; }).catch(() => {}) : Promise.resolve(),
  ]).finally(() => { stepUpload(); loadHistory(); });
})();
