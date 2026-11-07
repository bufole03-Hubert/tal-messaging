<?php
use App\Services\ThemeService;
$themeDefault = ThemeService::settings()['default_mode'];
?>
<!DOCTYPE html>
<html lang="fr" class="h-full" data-theme-default="<?= e($themeDefault) ?>">
<head>
  <?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="h-full">
<div class="grid min-h-full lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">

  <!-- Panneau de marque (desktop) : aplat de la couleur principale, sans dégradé -->
  <aside class="relative hidden flex-col justify-between overflow-hidden bg-brand p-12 text-brandfg lg:flex">
    <div class="flex items-center gap-3">
      <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brandfg/15"><?= icon('send', 'text-2xl') ?></span>
      <span class="font-display text-xl font-bold tracking-tight">TAL Messaging</span>
    </div>
    <div class="max-w-md">
      <h2 class="font-display text-4xl font-bold leading-tight">Toutes vos communications, un seul cockpit.</h2>
      <p class="mt-4 text-base opacity-80">Campagnes, messages, contacts et équipes : pilotez votre plateforme de messagerie en toute sécurité.</p>
      <ul class="mt-8 space-y-3 text-sm">
        <li class="flex items-center gap-3"><?= icon('shield-check', 'text-xl') ?>Double authentification et audit complet</li>
        <li class="flex items-center gap-3"><?= icon('building-skyscraper', 'text-xl') ?>Isolation stricte entre organisations</li>
        <li class="flex items-center gap-3"><?= icon('bolt', 'text-xl') ?>Pensé pour la performance au quotidien</li>
      </ul>
    </div>
    <p class="text-xs opacity-70">© <?= date('Y') ?> TAL Messaging</p>
    <span class="pointer-events-none absolute -bottom-24 -right-24 h-72 w-72 rounded-full bg-brandfg/10"></span>
    <span class="pointer-events-none absolute -top-16 -right-10 h-40 w-40 rounded-full bg-brandfg/10"></span>
  </aside>

  <!-- Contenu -->
  <main class="relative flex flex-col items-center justify-center px-5 py-10 sm:px-8">
    <div class="absolute right-4 top-4">
      <button type="button" class="btn btn-outline btn-icon" data-theme-cycle aria-label="Changer de thème"><?= icon('sun', 'text-lg') ?></button>
    </div>
    <div class="mb-8 flex items-center gap-2.5 lg:hidden">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand text-brandfg"><?= icon('send', 'text-xl') ?></span>
      <span class="font-display text-xl font-bold tracking-tight">TAL Messaging</span>
    </div>
    <div class="w-full max-w-md animate-fade-up"><?= $content ?></div>
  </main>
</div>
<div id="toast" class="pointer-events-none fixed bottom-4 right-4 z-50 w-[calc(100%-2rem)] max-w-sm" role="status" aria-live="polite"></div>
<script src="<?= asset('js/app.js') ?>" defer></script>
</body>
</html>
