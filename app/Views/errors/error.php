<div class="card text-center">
  <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand"><?= icon($status === 404 ? 'map-off' : ($status === 403 ? 'lock' : 'alert-triangle'), 'text-3xl') ?></span>
  <p class="mt-5 font-display text-5xl font-extrabold tracking-tight"><?= e($status) ?></p>
  <p class="mt-3 text-sm text-mute"><?= e($message) ?></p>
  <a href="<?= e(url('/')) ?>" class="btn btn-primary mt-7"><?= icon('arrow-left') ?>Retour à l’accueil</a>
</div>
