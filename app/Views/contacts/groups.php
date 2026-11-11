<?php
$cfg = ['canManage' => $canManage, 'global' => $global];
$heading = 'Groupes';
$lead = 'Listes de contacts réutilisables pour vos campagnes. Un contact peut appartenir à plusieurs groupes.';
require __DIR__ . '/../partials/contacts-header.php';
?>
<div id="groups-app" data-config="<?= e((string) json_encode($cfg)) ?>">
  <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="field w-full sm:max-w-sm">
      <?= icon('search', 'field-icon') ?>
      <input class="input" type="search" id="g-q" placeholder="Rechercher un groupe" aria-label="Rechercher un groupe" autocomplete="off">
    </div>
    <?php if ($canManage): ?>
      <button type="button" class="btn btn-primary" id="g-new"><?= icon('plus') ?>Nouveau groupe</button>
    <?php endif; ?>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Groupe</th><th class="hidden sm:table-cell">Description</th><th class="text-right">Contacts</th><th class="w-32"></th></tr></thead>
      <tbody id="g-rows"></tbody>
    </table>
    <div id="g-state"></div>
  </div>
  <div id="g-pager"></div>
</div>
