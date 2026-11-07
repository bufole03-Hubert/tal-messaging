<div class="mb-8 flex items-start gap-4">
  <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-brandfg"><?= icon((string) $dashboard['icon'], 'text-3xl') ?></span>
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">Dashboard <?= e($dashboard['label']) ?></h2>
    <p class="mt-1 text-sm text-mute md:text-base"><?= e($dashboard['description']) ?></p>
  </div>
</div>

<div class="mb-10 grid gap-4 sm:grid-cols-3">
  <div class="card">
    <div class="flex items-center justify-between"><p class="text-xs font-semibold uppercase tracking-wider text-mute">Utilisateur</p><?= icon('user', 'text-xl text-mute') ?></div>
    <p class="mt-3 text-lg font-bold"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></p>
    <p class="truncate text-sm text-mute"><?= e($user['email']) ?></p>
  </div>
  <div class="card">
    <div class="flex items-center justify-between"><p class="text-xs font-semibold uppercase tracking-wider text-mute">Rôle</p><?= icon('id-badge-2', 'text-xl text-mute') ?></div>
    <p class="mt-3 text-lg font-bold"><?= e($user['role_name']) ?></p>
    <p class="text-sm text-mute"><?= $user['role_scope'] === 'internal' ? 'Équipe TAL' : 'Compte client' ?></p>
  </div>
  <div class="card">
    <div class="flex items-center justify-between"><p class="text-xs font-semibold uppercase tracking-wider text-mute">Organisation</p><?= icon('building-skyscraper', 'text-xl text-mute') ?></div>
    <p class="mt-3 text-lg font-bold"><?= $user['org_name'] ? e($user['org_name']) : 'Périmètre global' ?></p>
    <p class="flex items-center gap-1.5 text-sm <?= $user['totp_enabled'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-mute' ?>">
      <?= icon($user['totp_enabled'] ? 'shield-check' : 'shield-off') ?><?= $user['totp_enabled'] ? '2FA active' : '2FA inactive' ?>
    </p>
  </div>
</div>

<h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-mute">Sections prévues</h3>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($dashboard['sections'] as $i => $section): ?>
    <div class="card flex items-center justify-between gap-3 !p-4">
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-surface2 text-sm font-bold text-mute"><?= $i + 1 ?></span>
        <span class="truncate font-medium"><?= e($section) ?></span>
      </div>
      <span class="badge shrink-0">À venir</span>
    </div>
  <?php endforeach; ?>
</div>
