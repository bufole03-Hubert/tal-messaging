<?php $internal = $user['role_scope'] === 'internal'; ?>
<?php $needs2fa = \App\Services\AuthService::requiresTwoFactorSetup($user); ?>
<?php if ($needs2fa || $user['must_change_password']): ?>
  <div class="alert alert-warn mb-6" role="alert">
    <?= icon('alert-triangle', 'mt-0.5 text-lg') ?>
    <p>
      <strong>Action requise avant de continuer :</strong>
      <?= $needs2fa ? 'activez la double authentification (obligatoire pour les comptes internes). ' : '' ?>
      <?= $user['must_change_password'] ? 'Changez votre mot de passe temporaire.' : '' ?>
    </p>
  </div>
<?php endif; ?>

<div class="mb-8">
  <h2 class="text-2xl font-bold md:text-3xl">Sécurité du compte</h2>
  <p class="mt-1 text-sm text-mute">Protégez votre accès avec un mot de passe robuste et la double authentification.</p>
</div>

<div class="grid gap-6 lg:grid-cols-2">
  <section class="card">
    <div class="mb-5 flex items-center gap-3">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand"><?= icon('key', 'text-xl') ?></span>
      <h3 class="text-lg font-bold">Changer le mot de passe</h3>
    </div>
    <form class="space-y-4" data-form="password" novalidate>
      <div>
        <label class="label" for="current_password">Mot de passe actuel</label>
        <div class="field">
          <?= icon('lock', 'field-icon') ?>
          <input class="input has-action" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
          <button type="button" class="field-action" data-toggle-password aria-label="Afficher ou masquer"><?= icon('eye') ?></button>
        </div>
      </div>
      <div>
        <label class="label" for="new_password">Nouveau mot de passe</label>
        <div class="field">
          <?= icon('lock-plus', 'field-icon') ?>
          <input class="input has-action" type="password" id="new_password" name="new_password" autocomplete="new-password" required>
          <button type="button" class="field-action" data-toggle-password aria-label="Afficher ou masquer"><?= icon('eye') ?></button>
        </div>
        <p class="hint">10 caractères minimum, avec majuscule, minuscule et chiffre.</p>
      </div>
      <button type="submit" class="btn btn-primary">Mettre à jour</button>
    </form>
  </section>

  <section class="card">
    <div class="mb-5 flex items-center gap-3">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand"><?= icon('device-mobile-code', 'text-xl') ?></span>
      <h3 class="text-lg font-bold">Double authentification (TOTP)</h3>
    </div>
    <?php if ($user['totp_enabled']): ?>
      <p class="badge badge-ok mb-4"><?= icon('circle-check') ?>Active sur ce compte</p>
      <?php if (!$internal || !(bool) \App\Core\Config::get('app.security.enforce_internal_2fa', true)): ?>
        <form class="space-y-4" data-form="2fa-disable" novalidate>
          <div>
            <label class="label" for="d_password">Mot de passe</label>
            <input class="input" type="password" id="d_password" name="password" autocomplete="current-password" required>
          </div>
          <div>
            <label class="label" for="d_code">Code actuel</label>
            <input class="input input-code" type="text" id="d_code" name="code" inputmode="numeric" maxlength="6" placeholder="000000" required>
          </div>
          <button type="submit" class="btn btn-outline">Désactiver la 2FA</button>
        </form>
      <?php else: ?>
        <p class="text-sm text-mute">Obligatoire pour les comptes internes : elle ne peut pas être désactivée.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="mb-4 text-sm text-mute">Protégez votre compte avec un code à 6 chiffres qui change toutes les 30 secondes, généré par une application d’authentification (Google Authenticator, Microsoft Authenticator, Authy, Aegis, FreeOTP…).</p>
      <button type="button" class="btn btn-primary" data-action="2fa-setup"><?= icon('qrcode') ?>Configurer la 2FA</button>

      <div id="twofa-setup" class="mt-6 hidden">
        <ol class="space-y-7">
          <!-- Étape 1 : QR code -->
          <li class="flex gap-3.5">
            <span class="step-dot">1</span>
            <div class="min-w-0 flex-1">
              <p class="font-semibold">Scannez le QR code</p>
              <p class="mt-0.5 text-sm text-mute">Dans l’application, appuyez sur « + » puis « Scanner un code QR », et visez l’écran.</p>

              <div class="relative mx-auto mt-5 w-full max-w-[17.5rem] p-4">
                <!-- Coins de visée aux couleurs de la marque -->
                <span class="pointer-events-none absolute left-0 top-0 h-7 w-7 rounded-tl-2xl border-l-[3px] border-t-[3px] border-brand"></span>
                <span class="pointer-events-none absolute right-0 top-0 h-7 w-7 rounded-tr-2xl border-r-[3px] border-t-[3px] border-brand"></span>
                <span class="pointer-events-none absolute bottom-0 left-0 h-7 w-7 rounded-bl-2xl border-b-[3px] border-l-[3px] border-brand"></span>
                <span class="pointer-events-none absolute bottom-0 right-0 h-7 w-7 rounded-br-2xl border-b-[3px] border-r-[3px] border-brand"></span>

                <!-- Le QR reste sombre sur fond blanc quel que soit le thème (clair/sombre), pour être lisible par tous les scanners -->
                <div class="rounded-2xl bg-white p-3.5 shadow-pop ring-1 ring-black/5">
                  <div id="twofa-qr" class="relative aspect-square w-full">
                    <div id="twofa-qr-skeleton" class="absolute inset-0 animate-pulse rounded-xl bg-slate-100"></div>
                    <div id="twofa-qr-svg" class="absolute inset-0"></div>
                    <span id="twofa-qr-logo" class="absolute left-1/2 top-1/2 hidden h-[16%] w-[16%] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-xl bg-brand text-brandfg shadow-soft"><?= icon('send', 'text-lg') ?></span>
                  </div>
                </div>
              </div>
              <p id="twofa-qr-error" class="alert alert-warn mt-4 hidden"><?= icon('alert-triangle', 'mt-0.5 text-lg') ?><span>Le QR code n’a pas pu être affiché. Utilisez la clé secrète ci-dessous.</span></p>

              <a id="twofa-open-app" href="#" class="btn btn-soft mt-4 w-full md:hidden"><?= icon('device-mobile') ?>Ouvrir dans mon application</a>
            </div>
          </li>

          <!-- Étape 2 : saisie manuelle -->
          <li class="flex gap-3.5">
            <span class="step-dot">2</span>
            <div class="min-w-0 flex-1">
              <p class="font-semibold">Impossible de scanner ?</p>
              <p class="mt-0.5 text-sm text-mute">Choisissez « Saisir une clé de configuration » et entrez cette clé secrète (type : basé sur l’heure).</p>
              <div class="mt-3 flex items-stretch gap-2">
                <code id="twofa-secret" class="block min-w-0 flex-1 break-all rounded-xl bg-surface2 px-3.5 py-3 font-mono text-sm tracking-wider"></code>
                <button type="button" class="btn btn-outline btn-icon h-auto" data-copy="#twofa-secret" aria-label="Copier la clé secrète"><?= icon('copy') ?></button>
              </div>
            </div>
          </li>

          <!-- Étape 3 : vérification -->
          <li class="flex gap-3.5">
            <span class="step-dot">3</span>
            <div class="min-w-0 flex-1">
              <p class="font-semibold">Entrez le code à 6 chiffres</p>
              <p class="mt-0.5 text-sm text-mute">Saisissez le code affiché par l’application pour confirmer l’activation. <span class="block text-xs">Heure du serveur : <span id="twofa-server-time">—</span></span></p>
              <form class="mt-3 space-y-3" data-form="2fa-enable" novalidate>
                <label class="sr-only" for="e_code">Code à 6 chiffres</label>
                <input class="input input-code" type="text" id="e_code" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" placeholder="000000" required>
                <div class="flex gap-2">
                  <button type="submit" class="btn btn-primary flex-1"><?= icon('shield-check') ?>Activer la 2FA</button>
                  <button type="button" class="btn btn-ghost" data-action="2fa-cancel">Annuler</button>
                </div>
              </form>
            </div>
          </li>
        </ol>
      </div>
      <div id="recovery" class="alert alert-warn mt-5 hidden">
        <?= icon('alert-triangle', 'mt-0.5 text-lg') ?>
        <div>
          <p class="mb-2 font-semibold">Codes de récupération — affichés une seule fois, conservez-les en lieu sûr</p>
          <pre id="recovery-codes" class="font-mono"></pre>
        </div>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php if (!$user['totp_enabled']): ?>
  <script src="<?= asset('js/qr.js') ?>" defer></script>
<?php endif; ?>
