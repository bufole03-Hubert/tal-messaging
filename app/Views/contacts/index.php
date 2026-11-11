<?php
/** @var array<string,array<string,mixed>> $countries */
/** @var array<int,string> $operators */
$cfg = [
    'canManage' => $canManage, 'canExport' => $canExport, 'global' => $global,
    'countries' => array_map(static fn(array $c): string => (string) $c['name'], $countries),
    'operators' => $operators,
];
$heading = 'Contacts';
$lead = 'Votre base de contacts : recherche, filtres, étiquettes, groupes et historique.';
require __DIR__ . '/../partials/contacts-header.php';
?>
<div id="contacts-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">

  <div id="kpis" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-live="polite"></div>

  <div class="card !p-4 md:!p-5">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
      <div class="field w-full lg:max-w-sm">
        <?= icon('search', 'field-icon') ?>
        <input class="input" type="search" id="f-q" placeholder="Nom, numéro ou e-mail" autocomplete="off" aria-label="Rechercher un contact">
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn btn-outline" id="btn-filters" aria-expanded="false"><?= icon('adjustments-horizontal') ?>Filtres<span class="badge badge-brand hidden" id="filters-count"></span></button>
        <?php if ($canManage): ?>
          <button type="button" class="btn btn-outline" id="btn-duplicates"><?= icon('copy') ?>Doublons</button>
        <?php endif; ?>
        <?php if ($canExport): ?>
          <button type="button" class="btn btn-outline" id="btn-export"><?= icon('download') ?>Exporter</button>
        <?php endif; ?>
        <?php if ($canManage): ?>
          <button type="button" class="btn btn-primary" id="btn-new"><?= icon('plus') ?>Nouveau contact</button>
        <?php endif; ?>
      </div>
    </div>

    <div id="filters" class="mt-4 hidden grid-cols-1 gap-3 border-t border-line pt-4 sm:grid-cols-2 lg:grid-cols-4">
      <?php if ($global): ?>
        <div><label class="label" for="f-organization">Organisation</label><select class="input" id="f-organization"><option value="">Toutes</option></select></div>
      <?php endif; ?>
      <div><label class="label" for="f-operator">Opérateur</label>
        <select class="input" id="f-operator"><option value="">Tous</option>
          <?php foreach ($operators as $o): ?><option value="<?= e($o) ?>"><?= e(ucfirst($o)) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="label" for="f-status">Statut</label>
        <select class="input" id="f-status"><option value="">Tous</option><option value="active">Actif</option><option value="blocked">Bloqué</option></select></div>
      <div><label class="label" for="f-country">Pays</label>
        <select class="input" id="f-country"><option value="">Tous</option>
          <?php foreach ($countries as $code => $c): ?><option value="<?= e($code) ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="label" for="f-source">Origine</label>
        <select class="input" id="f-source"><option value="">Toutes</option><option value="manual">Manuel</option><option value="import">Import</option><option value="api">API</option></select></div>
      <div><label class="label" for="f-group">Groupe</label><select class="input" id="f-group"><option value="">Tous</option></select></div>
      <div><label class="label" for="f-tag">Étiquette</label><select class="input" id="f-tag"><option value="">Toutes</option></select></div>
      <div class="flex items-end"><button type="button" class="btn btn-ghost" id="btn-reset"><?= icon('x') ?>Réinitialiser</button></div>
    </div>
  </div>

  <div id="bulkbar" class="mt-4 hidden flex-wrap items-center gap-2 rounded-2xl bg-brand/10 px-4 py-3 text-sm" role="region" aria-label="Actions groupées">
    <span id="bulk-label" class="font-semibold text-brand"></span>
    <button type="button" class="btn btn-ghost btn-sm hidden" id="bulk-all"></button>
    <span class="flex-1"></span>
    <button type="button" class="btn btn-outline btn-sm" data-bulk="group_add"><?= icon('users-plus') ?>Ajouter à un groupe</button>
    <button type="button" class="btn btn-outline btn-sm" data-bulk="group_remove"><?= icon('users-minus') ?>Retirer d’un groupe</button>
    <button type="button" class="btn btn-outline btn-sm" data-bulk="tag_add"><?= icon('tag') ?>Étiqueter</button>
    <button type="button" class="btn btn-outline btn-sm" data-bulk="block"><?= icon('ban') ?>Bloquer</button>
    <button type="button" class="btn btn-outline btn-sm" data-bulk="unblock"><?= icon('circle-check') ?>Débloquer</button>
    <button type="button" class="btn btn-danger btn-sm" data-bulk="delete"><?= icon('trash') ?>Supprimer</button>
  </div>

  <div class="tbl-wrap mt-4">
    <table class="tbl">
      <thead>
        <tr>
          <th class="w-10"><input type="checkbox" class="check" id="check-all" aria-label="Tout sélectionner"></th>
          <th><button type="button" class="font-semibold uppercase tracking-wider" data-sort="name">Contact</button></th>
          <th><button type="button" class="font-semibold uppercase tracking-wider" data-sort="phone">Téléphone</button></th>
          <th class="hidden md:table-cell">Opérateur</th>
          <th class="hidden lg:table-cell">Groupes / étiquettes</th>
          <th>Statut</th>
          <th class="hidden xl:table-cell"><button type="button" class="font-semibold uppercase tracking-wider" data-sort="created_at">Ajouté le</button></th>
        </tr>
      </thead>
      <tbody id="rows"></tbody>
    </table>
    <div id="state"></div>
  </div>
  <div id="pager"></div>
</div>
