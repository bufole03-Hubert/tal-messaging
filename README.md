# TAL Messaging — Architecture & Module 1 (Core Foundation)

PHP 8.1+ MVC sans framework ni dépendance Composer · MySQL 8 · Tailwind CSS **local** (build npm) · icônes Tabler (police locale) · AJAX (fetch) · aucune ressource CDN.

## Arborescence

```
tal-messaging/
├── public/                 # seul dossier exposé (document root)
│   ├── index.php  .htaccess
│   └── assets/{css/app.css (généré), js/app.js, js/qr.js, js/theme-init.js, fonts/ et vendor/ (copiés par npm)}
├── app/
│   ├── Core/               # App, Router, Request/Response, Session (DB), Validator, ErrorHandler
│   ├── Middleware/         # session, auth, tenant, perm, csrf, guest, audit
│   ├── Tenancy/            # TenantContext (isolation fail-closed)
│   ├── Repositories/       # TenantRepository (filtre organization_id imposé) + dérivés
│   ├── Services/           # AuthService, AuditService, FeatureFlagService
│   ├── Controllers/  Views/  Support/  Modules/ (modules futurs)
├── config/                 # app, database, permissions (matrice), dashboards, routes
├── database/               # migrations/*.sql, migrate.php, seed.php
├── resources/css/input.css # source Tailwind
├── docker/  Dockerfile  docker-compose.yml
├── scripts/init-env.php    # génère .env avec secrets aléatoires
└── tests/                  # run.php (unitaires), e2e.php
```

## Démarrage — Docker

```bash
php scripts/init-env.php          # crée .env (APP_KEY, mots de passe DB aléatoires)
docker compose up --build         # MySQL + app, migrations et seed automatiques
```
Application : http://localhost:8080 · santé : `/health`. Les identifiants seed sont affichés **une seule fois** dans les logs : `docker compose logs web | grep -A6 IDENTIFIANTS`.

## Démarrage — XAMPP (Windows, config par défaut, sans Docker)

1. Placer le projet dans `C:\xampp\htdocs\tal-messaging`, démarrer **Apache** et **MySQL** dans le panneau XAMPP.
2. Créer la base (phpMyAdmin ou console) : `CREATE DATABASE tal_messaging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Dans le dossier du projet : `php scripts/init-env.php`, puis éditer `.env` : `DB_HOST=127.0.0.1`, `DB_USER=root`, `DB_PASSWORD=` (vide par défaut sous XAMPP).
4. `npm install` (installe Tailwind, les icônes Tabler et les polices, puis les copie dans `public/assets`) puis `npm run build`.
5. `php database/migrate.php && php database/seed.php` (relancer le seed après chaque mise à jour : il synchronise les permissions).
6. Ouvrir **http://localhost/tal-messaging/public/** (ou http://localhost/tal-messaging/, qui redirige vers `public/`).
<img width="1366" height="768" alt="public_page" src="https://github.com/user-attachments/assets/b0fa575f-be3a-49e0-9a49-cc07c9aeeb66" />

L'application détecte seule son sous-dossier (`/tal-messaging/public`) : routage, redirections, cookies, CSS/JS et appels AJAX en tiennent compte.
Si besoin, forcer le préfixe avec `APP_BASE_PATH` dans `.env` (`/` pour une installation à la racine, ex. VirtualHost).
Prérequis Apache : `mod_rewrite` activé et `AllowOverride All` (valeurs par défaut de XAMPP).

## Interface, thème et palette

- Icônes : police **Tabler** (`@tabler/icons-webfont`), polices **Inter** (texte) et **Plus Jakarta Sans** (titres) — tout est local, rien n'est chargé depuis un CDN.
- Thème clair / sombre / automatique (bouton dans le header, mémorisé par utilisateur). Aucun dégradé.
- Le **super admin (CEO)** règle la palette de la plateforme dans **Apparence** (`/appearance`, permission `manage_appearance`) :
  8 couleurs par mode, préréglages, aperçu en direct, mode par défaut, réinitialisation. Les couleurs sont servies par `/theme.css`.
- Les sélecteurs natifs `<select>` sont remplacés par un sélecteur moderne : ajouter `data-ui="select"` à un `<select>`.
- Ajouter une icône : `icon('nom-tabler')` (catalogue : https://tabler.io/icons).

## Tests

```bash
php tests/run.php                                   # unitaires (sans base)
E2E_PASSWORD='...' php tests/e2e.php                # e2e (serveur lancé + base seedée)
```

## Sécurité intégrée

Argon2id (bcrypt en repli) · sessions serveur en base (ID hashé, cookie HttpOnly/SameSite, régénération au login, expiration inactivité/absolue, liaison User-Agent) · CSRF sur toute écriture · 2FA TOTP maison (secret chiffré AES-256-GCM, anti-rejeu, codes de récupération, **QR code d’enrôlement généré localement dans le navigateur**, `public/assets/js/qr.js`), **obligatoire pour les comptes internes** · anti brute-force (compte + IP) · requêtes préparées uniquement · échappement systématique des vues · CSP stricte `default-src 'self'` (pas de script/style inline, pas de CDN) · en-têtes de sécurité · erreurs typées sans stack trace · audit append-only avec masquage des secrets · anti-escalade de rôles.

## Isolation multi-tenant

Le filtre `organization_id` n'est **jamais** écrit dans un contrôleur : il est imposé par `TenantRepository`, alimenté par `TenantContext` (initialisé par `TenantMiddleware` après authentification). Sans contexte → exception (fail-closed). Un utilisateur client ne peut pas élargir son périmètre même avec des permissions étendues ; `organization_id` d'une ligne n'est jamais modifiable ; un accès hors périmètre répond **404**. Les seules requêtes non scopées : résolution d'identité au login et données de référence (rôles/permissions).

Voir `API_DOC.md` pour les endpoints et `app/Modules/README.md` pour ajouter les modules suivants.
