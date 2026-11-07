<?php
/** @var array<string,mixed> $user */
/** @var array<string,array<string,mixed>> $dashboards */
use App\Services\ThemeService;

$current = $slug ?? '';
$page = $page ?? '';
$themeDefault = ThemeService::settings()['default_mode'];
$initials = mb_strtoupper(mb_substr((string) $user['first_name'], 0, 1) . mb_substr((string) $user['last_name'], 0, 1));
$canAppearance = in_array('manage_appearance', $user['permissions'], true);
?>
<!DOCTYPE html>
<html lang="fr" class="h-full" data-theme-default="<?= e($themeDefault) ?>">
<head>
  <?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="h-full">
<div class="flex h-full">

  <!-- Voile (mobile) -->
  <div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-black/50 backdrop-blur-sm md:hidden" data-toggle="sidebar"></div>

  <!-- Sidebar -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-line bg-surface transition-all duration-200 md:static md:z-auto md:w-64 md:translate-x-0">
    <div class="brand-row flex h-16 shrink-0 items-center justify-between gap-2 px-5">
      <a href="<?= e(url('/')) ?>" class="flex items-center gap-2.5">
        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-brandfg"><?= icon('send', 'text-lg') ?></span>
        <span class="collapsible font-display text-[17px] font-bold tracking-tight">TAL Messaging</span>
      </a>
      <button type="button" class="btn btn-ghost btn-icon btn-sm md:hidden" data-toggle="sidebar" aria-label="Fermer le menu"><?= icon('x', 'text-lg') ?></button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="Navigation principale">
      <p class="nav-title">Espaces</p>
      <div class="space-y-1">
        <?php foreach ($dashboards as $s => $d): ?>
          <a href="<?= e(url('/' . $s)) ?>" class="nav-item" title="<?= e($d['label']) ?>" <?= $current === $s ? 'aria-current="page"' : '' ?>>
            <?= icon((string) $d['icon']) ?><span class="collapsible"><?= e($d['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <p class="nav-title">Compte</p>
      <div class="space-y-1">
        <a href="<?= e(url('/security')) ?>" class="nav-item" title="Sécurité" <?= $page === 'security' ? 'aria-current="page"' : '' ?>>
          <?= icon('shield') ?><span class="collapsible">Sécurité</span>
        </a>
        <?php if ($canAppearance): ?>
          <a href="<?= e(url('/appearance')) ?>" class="nav-item" title="Apparence" <?= $page === 'appearance' ? 'aria-current="page"' : '' ?>>
            <?= icon('palette') ?><span class="collapsible">Apparence</span>
          </a>
        <?php endif; ?>
      </div>
    </nav>

    <div class="hidden shrink-0 border-t border-line p-3 md:block">
      <button type="button" class="nav-item w-full" data-toggle="collapse" title="Réduire / agrandir le menu">
        <?= icon('chevrons-left', 'transition-transform') ?><span class="collapsible">Réduire le menu</span>
      </button>
    </div>
  </aside>

  <div class="flex min-w-0 flex-1 flex-col">
    <!-- Header -->
    <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between gap-3 border-b border-line bg-surface/85 px-4 backdrop-blur md:px-8">
      <div class="flex min-w-0 items-center gap-3">
        <button type="button" class="btn btn-outline btn-icon md:hidden" data-toggle="sidebar" aria-label="Ouvrir le menu"><?= icon('menu') ?></button>
        <div class="min-w-0">
          <p class="hidden text-xs text-mute sm:block">TAL Messaging</p>
          <h1 class="truncate text-lg font-bold leading-tight"><?= e($title ?? '') ?></h1>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <!-- Mode d'affichage -->
        <div class="seg hidden sm:inline-flex" role="group" aria-label="Thème">
          <button type="button" class="seg-btn !px-2.5" data-theme-set="light" aria-pressed="false" title="Clair"><?= icon('sun', 'text-base') ?></button>
          <button type="button" class="seg-btn !px-2.5" data-theme-set="system" aria-pressed="false" title="Automatique"><?= icon('system', 'text-base') ?></button>
          <button type="button" class="seg-btn !px-2.5" data-theme-set="dark" aria-pressed="false" title="Sombre"><?= icon('moon', 'text-base') ?></button>
        </div>
        <button type="button" class="btn btn-outline btn-icon sm:hidden" data-theme-cycle aria-label="Changer de thème"><?= icon('sun', 'text-lg') ?></button>

        <!-- Menu utilisateur -->
        <div class="relative" data-dropdown>
          <button type="button" class="flex items-center gap-2.5 rounded-xl p-1.5 pr-2.5 transition hover:bg-surface2" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand/10 text-sm font-bold text-brand"><?= e($initials) ?></span>
            <span class="hidden text-left leading-tight md:block">
              <span class="block max-w-[10rem] truncate text-sm font-semibold"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></span>
              <span class="block max-w-[10rem] truncate text-xs text-mute"><?= e($user['role_name']) ?></span>
            </span>
            <?= icon('chevron-down', 'hidden text-mute md:inline') ?>
          </button>
          <div class="menu right-0 hidden" role="menu" data-dropdown-menu>
            <div class="px-3 py-2.5">
              <p class="truncate text-sm font-semibold"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></p>
              <p class="truncate text-xs text-mute"><?= e($user['email']) ?></p>
              <p class="mt-1 truncate text-xs text-mute"><?= e($user['role_name']) ?><?= $user['org_name'] ? ' · ' . e($user['org_name']) : '' ?></p>
            </div>
            <div class="menu-sep"></div>
            <a class="menu-item" role="menuitem" href="<?= e(url('/security')) ?>"><?= icon('shield') ?>Sécurité du compte</a>
            <?php if ($canAppearance): ?>
              <a class="menu-item" role="menuitem" href="<?= e(url('/appearance')) ?>"><?= icon('palette') ?>Apparence</a>
            <?php endif; ?>
            <div class="menu-sep"></div>
            <button type="button" class="menu-item" role="menuitem" data-action="logout"><?= icon('logout') ?>Déconnexion</button>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1 overflow-y-auto p-4 md:p-8">
      <div class="mx-auto w-full max-w-6xl animate-fade-up"><?= $content ?></div>
    </main>

    <!-- Footer -->
    <footer class="flex shrink-0 flex-col items-center justify-between gap-2 border-t border-line bg-surface px-4 py-3 text-xs text-mute sm:flex-row md:px-8">
      <p>© <?= date('Y') ?> TAL Messaging · Tous droits réservés</p>
      <p class="flex items-center gap-4">
        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Système opérationnel</span>
        <a class="hover:text-ink" href="<?= e(url('/security')) ?>">Sécurité</a>
      </p>
    </footer>
  </div>
</div>

<div id="toast" class="pointer-events-none fixed bottom-4 right-4 z-50 w-[calc(100%-2rem)] max-w-sm" role="status" aria-live="polite"></div>
<script src="<?= asset('js/app.js') ?>" defer></script>
</body>
</html>
