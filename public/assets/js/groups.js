/* TAL Messaging — page Groupes. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, debounce, modal, confirmBox, field, textInput, selectInput, pager, empty, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#groups-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  let page = 1;
  let orgs = [];

  async function load() {
    try {
      const q = new URLSearchParams({ page, per_page: 25 });
      if ($('#g-q').value.trim()) q.set('q', $('#g-q').value.trim());
      const rows = await api('GET', '/api/contact-groups?' + q);
      const body = clear($('#g-rows'));
      for (const g of rows) {
        body.append(h('tr', { dataset: { row: g.id } },
          h('td', {}, h('p', { class: 'font-medium' }, g.name)),
          h('td', { class: 'hidden max-w-xs truncate text-mute sm:table-cell' }, g.description || '—'),
          h('td', { class: 'text-right tabular-nums' }, fmt(g.contacts_count)),
          h('td', { class: 'whitespace-nowrap text-right' },
            h('a', { class: 'btn btn-ghost btn-icon btn-sm', href: url('/contacts?group_id=' + g.id), title: 'Voir les contacts', 'aria-label': 'Voir les contacts' }, icon('eye')),
            cfg.canManage ? h('button', { type: 'button', class: 'btn btn-ghost btn-icon btn-sm', title: 'Modifier', 'aria-label': 'Modifier', onclick: () => openForm(g) }, icon('pencil')) : null,
            cfg.canManage ? h('button', { type: 'button', class: 'btn btn-ghost btn-icon btn-sm', title: 'Supprimer', 'aria-label': 'Supprimer', onclick: () => remove(g) }, icon('trash')) : null)));
      }
      clear($('#g-state'));
      if (!rows.length) $('#g-state').append(empty('Aucun groupe pour le moment.', 'users-group'));
      clear($('#g-pager')).append(pager(rows.meta, (p) => { page = p; load(); }));
    } catch (err) { toastErr(err); }
  }

  function openForm(g = null) {
    const org = cfg.global && !g ? selectInput('organization_id', [['', 'Choisir…'], ...orgs.map((o) => [o.id, o.name])]) : null;
    const name = textInput('name', g?.name, { maxlength: 100, required: true });
    const desc = textInput('description', g?.description, { maxlength: 255 });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    const m = modal({ title: g ? 'Modifier le groupe' : 'Nouveau groupe', body: h('form', { class: 'space-y-4', novalidate: true, onsubmit: (e) => { e.preventDefault(); $('.btn-primary', m.foot).click(); } },
      org ? field('Organisation', org) : null, field('Nom', name), field('Description (facultatif)', desc), err),
    actions: [{ label: 'Annuler' }, { label: g ? 'Enregistrer' : 'Créer', class: 'btn-primary', onclick: async (e, close) => {
      const body = { name: name.value.trim(), description: desc.value.trim() };
      if (org) body.organization_id = org.value;
      e.target.disabled = true;
      try {
        if (g) await api('PUT', '/api/contact-groups/' + g.id, body); else await api('POST', '/api/contact-groups', body);
        close(); toast(g ? 'Groupe mis à jour.' : 'Groupe créé.'); load();
      } catch (ex) { err.textContent = ex.message + (ex.fields ? ' ' + Object.values(ex.fields).flat().join(' ') : ''); err.classList.remove('hidden'); e.target.disabled = false; }
    } }] });
  }

  async function remove(g) {
    if (!(await confirmBox('Supprimer le groupe « ' + g.name + ' » ? Les contacts ne sont pas supprimés.', { label: 'Supprimer', danger: true }))) return;
    try { await api('DELETE', '/api/contact-groups/' + g.id); toast('Groupe supprimé.'); load(); } catch (err) { toastErr(err); }
  }

  $('#g-q').addEventListener('input', debounce(() => { page = 1; load(); }));
  $('#g-new')?.addEventListener('click', () => openForm());
  (cfg.global ? api('GET', '/api/organizations?per_page=100').then((o) => { orgs = o; }).catch(() => {}) : Promise.resolve()).finally(load);
})();
