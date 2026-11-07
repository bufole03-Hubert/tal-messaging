<div class="mb-8">
  <h2 class="text-3xl font-bold">Bon retour 👋</h2>
  <p class="mt-2 text-sm text-mute">Connectez-vous pour accéder à votre espace TAL Messaging.</p>
</div>

<div id="form-error" class="alert alert-danger mb-5 hidden" role="alert">
  <?= icon('alert-circle', 'mt-0.5 text-lg') ?><span data-msg></span>
</div>

<form id="login-form" class="space-y-5" data-form="login" novalidate>
  <div>
    <label class="label" for="email">Adresse e-mail</label>
    <div class="field">
      <?= icon('mail', 'field-icon') ?>
      <input class="input" type="email" id="email" name="email" placeholder="vous@entreprise.com" autocomplete="username" required autofocus>
    </div>
  </div>
  <div>
    <label class="label" for="password">Mot de passe</label>
    <div class="field">
      <?= icon('lock', 'field-icon') ?>
      <input class="input has-action" type="password" id="password" name="password" placeholder="••••••••••" autocomplete="current-password" required>
      <button type="button" class="field-action" data-toggle-password aria-label="Afficher ou masquer le mot de passe"><?= icon('eye') ?></button>
    </div>
  </div>
  <button type="submit" class="btn btn-primary h-11 w-full">Se connecter<?= icon('arrow-right') ?></button>
</form>

<form id="twofa-form" class="hidden space-y-5" data-form="login-2fa" novalidate>
  <div class="flex items-start gap-3 rounded-xl bg-brand/10 p-4 text-sm">
    <?= icon('shield-lock', 'mt-0.5 text-xl text-brand') ?>
    <p>Saisissez le code à 6 chiffres de votre application d’authentification, ou un code de récupération.</p>
  </div>
  <div>
    <label class="label" for="code">Code de vérification</label>
    <input class="input input-code" type="text" id="code" name="code" inputmode="numeric" placeholder="000000" autocomplete="one-time-code" maxlength="11" required>
  </div>
  <button type="submit" class="btn btn-primary h-11 w-full">Vérifier<?= icon('arrow-right') ?></button>
</form>

<p class="mt-8 flex items-center justify-center gap-2 text-xs text-mute"><?= icon('lock', 'text-sm') ?>Connexion sécurisée · session chiffrée</p>
