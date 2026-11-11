<?php
/** @var array<string,array<string,mixed>> $countries */
$cfg = [
    'canManage' => $canManage, 'global' => $global, 'defaultCountry' => $defaultCountry, 'maxMb' => $maxMb, 'maxRows' => $maxRows,
    'countries' => array_map(static fn(array $c): string => (string) $c['name'], $countries),
];
$heading = 'Imports CSV';
$lead = 'Importez vos contacts depuis un fichier CSV : numéros normalisés, doublons détectés, rapport complet.';
require __DIR__ . '/../partials/contacts-header.php';
?>
<div id="imports-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <?php if ($canManage): ?>
    <section class="card mb-8" aria-labelledby="wizard-title">
      <div class="mb-4 flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand"><?= icon('file-import', 'text-xl') ?></span>
        <div>
          <h3 id="wizard-title" class="text-lg font-bold">Nouvel import</h3>
          <p class="text-xs text-mute">CSV (UTF-8 ou Windows-1252), <?= (int) $maxMb ?> Mo et <?= number_format($maxRows, 0, ',', ' ') ?> lignes maximum. Exportez votre feuille Excel en CSV.</p>
        </div>
      </div>
      <div id="wizard"></div>
    </section>
  <?php endif; ?>

  <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-mute">Historique des imports</h3>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Fichier</th><th>Statut</th><th class="hidden text-right sm:table-cell">Lignes</th><th class="hidden text-right md:table-cell">Créés</th><th class="hidden text-right md:table-cell">Doublons</th><th class="hidden text-right md:table-cell">Invalides</th><th class="hidden lg:table-cell">Date</th><th class="w-12"></th></tr></thead>
      <tbody id="i-rows"></tbody>
    </table>
    <div id="i-state"></div>
  </div>
  <div id="i-pager"></div>
</div>
