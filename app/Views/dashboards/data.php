<?php
/** @var array<string,mixed> $dashboard */
/** @var array<string,int> $retention */
$cfg = ['global' => $global, 'canRetention' => $canRetention, 'canPurge' => $canPurge, 'retention' => $retention];
$links = [
    ['/contacts', 'Contacts', 'address-book'], ['/contacts/imports', 'Imports CSV', 'file-import'],
    ['/contacts/segments', 'Segments & listes', 'filter'], ['/contacts/groups', 'Groupes', 'users-group'],
];
?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div class="flex items-start gap-4">
    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-brandfg"><?= icon((string) $dashboard['icon'], 'text-3xl') ?></span>
    <div>
      <h2 class="text-2xl font-bold md:text-3xl">Dashboard <?= e($dashboard['label']) ?></h2>
      <p class="mt-1 text-sm text-mute"><?= e($dashboard['description']) ?></p>
    </div>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php foreach ($links as [$href, $label, $ic]): ?>
      <a class="btn btn-outline btn-sm" href="<?= e(url($href)) ?>"><?= icon($ic) ?><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div id="data-app" data-config="<?= e((string) json_encode($cfg)) ?>">
  <div id="d-kpis" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4"></div>

  <div class="grid gap-6 lg:grid-cols-2">
    <section class="card"><h3 class="mb-4 text-base font-bold">Nouveaux contacts (30 jours)</h3><div id="d-growth"></div></section>
    <section class="card"><h3 class="mb-4 text-base font-bold">Qualité des données</h3><div id="d-quality" class="space-y-4"></div></section>
    <section class="card"><h3 class="mb-4 text-base font-bold">Par opérateur</h3><div id="d-operators"></div></section>
    <section class="card"><h3 class="mb-4 text-base font-bold">Par pays</h3><div id="d-countries"></div></section>
    <section class="card"><h3 class="mb-4 text-base font-bold">Par origine</h3><div id="d-sources"></div></section>
    <?php if ($global): ?>
      <section class="card"><h3 class="mb-4 text-base font-bold">Contacts par organisation</h3><div id="d-orgs"></div></section>
    <?php endif; ?>
  </div>

  <h3 class="mb-3 mt-8 text-xs font-semibold uppercase tracking-wider text-mute">Derniers imports</h3>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Fichier</th><?php if ($global): ?><th class="hidden md:table-cell">Organisation</th><?php endif; ?><th>Statut</th><th class="text-right">Lignes</th><th class="hidden text-right sm:table-cell">Créés</th><th class="hidden text-right sm:table-cell">Doublons</th><th class="hidden text-right sm:table-cell">Invalides</th><th class="hidden lg:table-cell">Date</th></tr></thead>
      <tbody id="d-imports"></tbody>
    </table>
    <div id="d-imports-state"></div>
  </div>

  <?php if ($canRetention): ?>
    <section class="card mt-8" aria-labelledby="ret-title">
      <div class="mb-4 flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand"><?= icon('clock-hour-4', 'text-xl') ?></span>
        <div><h3 id="ret-title" class="text-lg font-bold">Conservation des données</h3><p class="text-xs text-mute">Durées après lesquelles les données sont supprimées lors de la purge.</p></div>
      </div>
      <form id="retention-form" class="grid gap-4 sm:grid-cols-3" novalidate>
        <div><label class="label" for="r-files">Fichiers d’import (jours)</label><input class="input" type="number" min="1" max="3650" id="r-files" name="import_files_days" value="<?= (int) $retention['import_files_days'] ?>"></div>
        <div><label class="label" for="r-errors">Rapports d’erreurs (jours)</label><input class="input" type="number" min="1" max="3650" id="r-errors" name="import_errors_days" value="<?= (int) $retention['import_errors_days'] ?>"></div>
        <div><label class="label" for="r-deleted">Contacts supprimés (jours)</label><input class="input" type="number" min="1" max="3650" id="r-deleted" name="deleted_contacts_days" value="<?= (int) $retention['deleted_contacts_days'] ?>"></div>
        <div class="flex flex-wrap items-center gap-3 sm:col-span-3">
          <button type="submit" class="btn btn-primary"><?= icon('device-floppy') ?>Enregistrer</button>
          <?php if ($canPurge): ?><button type="button" class="btn btn-outline" id="btn-purge"><?= icon('trash') ?>Lancer la purge maintenant</button><?php endif; ?>
          <span class="text-xs text-mute">La purge s’exécute aussi automatiquement : <code class="font-mono">php scripts/purge-data.php</code> (tâche planifiée quotidienne).</span>
        </div>
      </form>
    </section>
  <?php endif; ?>
</div>
