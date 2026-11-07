<?php
/** @var array<string,string> $tokens */
/** @var array{default_mode:string,palette:array<string,array<string,string>>} $theme */
$modes = ['light' => ['Clair', 'sun'], 'dark' => ['Sombre', 'moon']];
?>
<div data-appearance data-initial="<?= e(json_encode($theme, JSON_UNESCAPED_UNICODE)) ?>">

  <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
    <div>
      <h2 class="text-2xl font-bold md:text-3xl">Apparence de la plateforme</h2>
      <p class="mt-1 text-sm text-mute">Définissez la palette claire et sombre appliquée à tous les utilisateurs. Aucun dégradé : uniquement des aplats.</p>
    </div>
    <div class="flex gap-2">
      <button type="button" class="btn btn-outline" data-action="theme-reset"><?= icon('restore') ?>Réinitialiser</button>
      <button type="button" class="btn btn-primary" data-action="theme-save"><?= icon('device-floppy') ?>Enregistrer</button>
    </div>
  </div>

  <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
    <div class="space-y-6">

      <section class="card">
        <h3 class="text-base font-bold">Mode par défaut</h3>
        <p class="mb-4 mt-1 text-sm text-mute">Appliqué tant qu’un utilisateur n’a pas choisi son propre mode avec le bouton du header.</p>
        <select id="default-mode" data-ui="select" aria-label="Mode par défaut">
          <option value="system" <?= $theme['default_mode'] === 'system' ? 'selected' : '' ?>>Automatique (selon l’appareil)</option>
          <option value="light" <?= $theme['default_mode'] === 'light' ? 'selected' : '' ?>>Toujours clair</option>
          <option value="dark" <?= $theme['default_mode'] === 'dark' ? 'selected' : '' ?>>Toujours sombre</option>
        </select>
      </section>

      <section class="card">
        <h3 class="text-base font-bold">Préréglages de couleur principale</h3>
        <p class="mb-4 mt-1 text-sm text-mute">Un clic met à jour la couleur principale en clair et en sombre. Vous pouvez ensuite affiner chaque couleur.</p>
        <div id="presets" class="flex flex-wrap gap-2"></div>
      </section>

      <section class="card">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
          <h3 class="text-base font-bold">Palette</h3>
          <div class="seg" role="group" aria-label="Mode édité">
            <?php foreach ($modes as $m => [$label, $ico]): ?>
              <button type="button" class="seg-btn" data-palette-tab="<?= e($m) ?>" aria-pressed="<?= $m === 'light' ? 'true' : 'false' ?>"><?= icon($ico, 'text-base') ?><?= e($label) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <?php foreach ($modes as $m => [$label, $ico]): ?>
          <div data-palette-group="<?= e($m) ?>" class="space-y-3 <?= $m === 'light' ? '' : 'hidden' ?>">
            <?php foreach ($tokens as $token => $tokenLabel): ?>
              <div class="flex items-center gap-3">
                <label class="swatch">
                  <input type="color" value="<?= e(strtolower($theme['palette'][$m][$token])) ?>" data-mode="<?= e($m) ?>" data-token="<?= e($token) ?>" aria-label="<?= e($tokenLabel) ?> (<?= e($label) ?>)">
                </label>
                <p class="min-w-0 flex-1 truncate text-sm font-medium"><?= e($tokenLabel) ?></p>
                <input type="text" class="input h-9 w-28 font-mono text-xs uppercase" maxlength="7" spellcheck="false" autocomplete="off"
                       value="<?= e($theme['palette'][$m][$token]) ?>" data-mode="<?= e($m) ?>" data-token="<?= e($token) ?>" aria-label="Code hexadécimal <?= e($tokenLabel) ?> (<?= e($label) ?>)">
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </section>
    </div>

    <!-- Aperçus en direct (les deux modes côte à côte) -->
    <div class="space-y-6 xl:sticky xl:top-0 xl:self-start">
      <?php foreach ($modes as $m => [$label, $ico]): ?>
        <section>
          <p class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-mute"><?= icon($ico, 'text-base') ?>Aperçu · <?= e($label) ?></p>
          <div data-preview="<?= e($m) ?>" class="<?= $m === 'dark' ? 'dark ' : '' ?>overflow-hidden rounded-2xl bg-canvas text-ink ring-1 ring-line">
            <div class="flex">
              <div class="hidden w-40 shrink-0 space-y-1 border-r border-line bg-surface p-3 sm:block">
                <div class="mb-3 flex items-center gap-2">
                  <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-brand text-brandfg"><?= icon('send', 'text-sm') ?></span>
                  <span class="text-sm font-bold">TAL</span>
                </div>
                <div class="flex items-center gap-2 rounded-lg bg-brand/10 px-2.5 py-2 text-xs font-medium text-brand"><?= icon('chart', 'text-base') ?>CEO</div>
                <div class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs font-medium text-mute"><?= icon('server', 'text-base') ?>CTO</div>
                <div class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs font-medium text-mute"><?= icon('shield', 'text-base') ?>Sécurité</div>
              </div>
              <div class="min-w-0 flex-1 space-y-3 p-4">
                <div class="flex items-center justify-between">
                  <p class="font-display text-base font-bold">Tableau de bord</p>
                  <span class="rounded-full bg-brand/10 px-2.5 py-0.5 text-xs font-medium text-brand">Nouveau</span>
                </div>
                <div class="rounded-xl bg-surface p-3 ring-1 ring-line">
                  <p class="text-xs text-mute">Messages envoyés</p>
                  <p class="font-display text-2xl font-bold">12 480</p>
                  <p class="mt-1 text-xs text-mute">+8,2 % cette semaine</p>
                </div>
                <div class="flex flex-wrap gap-2">
                  <span class="inline-flex h-9 items-center rounded-xl bg-brand px-4 text-sm font-semibold text-brandfg">Action</span>
                  <span class="inline-flex h-9 items-center rounded-xl bg-surface px-4 text-sm font-semibold ring-1 ring-inset ring-line">Secondaire</span>
                  <span class="inline-flex h-9 items-center rounded-xl bg-surface2 px-4 text-sm font-medium text-mute">Neutre</span>
                </div>
              </div>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
</div>
