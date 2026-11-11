<?php
/** @var array<string,array<string,mixed>> $countries */
$cfg = ['canManage' => $canManage, 'global' => $global, 'countries' => array_map(static fn(array $c): string => (string) $c['name'], $countries)];
$heading = 'Segments';
$lead = 'Des filtres enregistrés : le nombre de contacts se met à jour automatiquement à mesure que votre base évolue.';
require __DIR__ . '/../partials/contacts-header.php';
?>
<div id="segments-app" data-config="<?= e((string) json_encode($cfg)) ?>">
  <?php if ($canManage): ?>
    <div class="mb-4 flex justify-end"><button type="button" class="btn btn-primary" id="s-new"><?= icon('plus') ?>Nouveau segment</button></div>
  <?php endif; ?>
  <div id="s-list" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"></div>
  <div id="s-state"></div>
</div>
