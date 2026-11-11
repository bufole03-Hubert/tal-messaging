/* TAL Messaging — Dashboard Data & Compliance. */
(() => {
  'use strict';
  const { $, h, clear, fmt, pct, dateFr, opLabel, SOURCE, kpi, bars, spark, empty, confirmBox, toastErr } = window.TALUI;
  const { api, toast } = window.TAL;
  const root = $('#data-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const STATUS = { uploaded: 'En attente', processing: 'En cours', completed: 'Terminé', failed: 'Échec', cancelled: 'Annulé' };

  const qualityRow = (label, value, total, hint) => {
    const p = total ? Math.round((value / total) * 100) : 0;
    const fill = h('div', { class: 'bar-fill' });
    fill.style.width = p + '%';
    return h('div', {}, h('div', { class: 'mb-1 flex justify-between text-sm' }, h('span', {}, label), h('span', { class: 'font-semibold tabular-nums' }, total ? p + ' %' : '—')),
      h('div', { class: 'bar-track' }, fill), hint ? h('p', { class: 'mt-1 text-xs text-mute' }, hint) : null);
  };

  async function load() {
    let s;
    try { s = await api('GET', '/api/data/stats'); } catch (err) { toastErr(err); return; }
    const t = s.totals;
    const imp = s.imports_90d;
    clear($('#d-kpis')).append(
      kpi('Contacts', fmt(t.total), fmt(t.active) + ' actifs · ' + fmt(t.blocked) + ' bloqués'),
      kpi('Imports (90 j)', fmt(imp.imports), fmt(imp.rows) + ' lignes traitées'),
      kpi('Numéros valides', pct(imp.valid_rate), fmt(imp.invalid) + ' invalides'),
      kpi('Doublons évités', fmt(imp.duplicates), imp.duplicate_rate === null ? '' : pct(imp.duplicate_rate) + ' des lignes'));

    clear($('#d-growth')).append(spark(s.growth), h('p', { class: 'mt-2 text-xs text-mute' }, fmt(s.growth.reduce((a, g) => a + g.count, 0)) + ' contacts ajoutés sur la période'));
    const unknownOp = s.by_operator.find((o) => o.key === 'inconnu')?.count || 0;
    clear($('#d-quality')).append(
      qualityRow('Avec e-mail', t.with_email, t.total), qualityRow('Avec nom', t.with_name, t.total),
      qualityRow('Opérateur identifié', t.total - unknownOp, t.total, 'Les préfixes opérateurs sont configurables dans config/countries.php.'),
      qualityRow('Contacts actifs', t.active, t.total));
    clear($('#d-operators')).append(bars(s.by_operator.map((o) => ({ label: o.key === 'inconnu' ? 'Inconnu' : opLabel(o.key), count: o.count })), t.total));
    clear($('#d-countries')).append(bars(s.by_country.map((o) => ({ label: o.key, count: o.count })), t.total));
    clear($('#d-sources')).append(bars(s.by_source.map((o) => ({ label: SOURCE[o.key] || o.key, count: o.count })), t.total));
    if (cfg.global && $('#d-orgs')) clear($('#d-orgs')).append(bars((s.top_organizations || []).map((o) => ({ label: o.name, count: o.count })), t.total));

    const body = clear($('#d-imports'));
    for (const i of s.recent_imports) {
      body.append(h('tr', {}, h('td', { class: 'max-w-[14rem] truncate font-medium' }, i.filename),
        cfg.global ? h('td', { class: 'hidden md:table-cell' }, i.organization_name) : null,
        h('td', {}, STATUS[i.status] || i.status), h('td', { class: 'text-right tabular-nums' }, fmt(i.processed_rows)),
        h('td', { class: 'hidden text-right tabular-nums sm:table-cell' }, fmt(i.created_rows)), h('td', { class: 'hidden text-right tabular-nums sm:table-cell' }, fmt(i.duplicate_rows)),
        h('td', { class: 'hidden text-right tabular-nums sm:table-cell' }, fmt(i.invalid_rows)), h('td', { class: 'hidden whitespace-nowrap text-mute lg:table-cell' }, dateFr(i.created_at))));
    }
    clear($('#d-imports-state'));
    if (!s.recent_imports.length) $('#d-imports-state').append(empty('Aucun import récent.', 'file-import'));
  }

  $('#retention-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.submitter || e.target.querySelector('button[type=submit]');
    const body = Object.fromEntries(new FormData(e.target).entries());
    btn.disabled = true;
    try { await api('PUT', '/api/data/retention', body); toast('Règles de conservation enregistrées.'); } catch (err) { toastErr(err); } finally { btn.disabled = false; }
  });

  $('#btn-purge')?.addEventListener('click', async () => {
    if (!(await confirmBox('Supprimer définitivement les données dont la durée de conservation est dépassée ? Cette action est irréversible.', { label: 'Lancer la purge', danger: true }))) return;
    try {
      const r = await api('POST', '/api/data/purge');
      toast(fmt(r.import_files) + ' fichier(s), ' + fmt(r.import_errors) + ' erreur(s), ' + fmt(r.contacts) + ' contact(s) purgés.');
      load();
    } catch (err) { toastErr(err); }
  });

  load();
})();
