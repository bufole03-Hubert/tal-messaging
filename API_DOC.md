# API interne — Module 1 (Core Foundation)

Authentification par **session cookie** (`tal_sid`, HttpOnly). Toute requête modifiante envoie `X-CSRF-Token` (valeur de `<meta name="csrf-token">`, renouvelée à la connexion) et `Content-Type: application/json`.

Succès : `{ "data": ... }` (listes : `{ "data": [...], "meta": { "page", "per_page", "total" } }`).
Erreur : `{ "error": { "code": "...", "message": "...", "details": { "fields": { "champ": ["..."] } } } }`.

| Code HTTP | `error.code` |
|---|---|
| 400 | BAD_REQUEST |
| 401 | AUTH_REQUIRED, INVALID_CREDENTIALS, TWO_FACTOR_INVALID, TWO_FACTOR_EXPIRED |
| 403 | FORBIDDEN, PERMISSION_DENIED, CSRF_INVALID, ACCOUNT_DISABLED, SECURITY_SETUP_REQUIRED, ROLE_ESCALATION, SELF_MODIFICATION, NO_TENANT_SCOPE, TWO_FACTOR_REQUIRED |
| 404 | NOT_FOUND (inclut tout accès hors périmètre tenant) |
| 409 | CONFLICT, EMAIL_TAKEN, LAST_OWNER, TWO_FACTOR_ALREADY_ENABLED |
| 422 | VALIDATION_FAILED |
| 429 | RATE_LIMITED |
| 500 | INTERNAL_ERROR |

## Public

| Méthode | Route | Description |
|---|---|---|
| GET | `/health` | `{"status":"ok","checks":{"database":"up"}}` (503 si base indisponible) |
| GET | `/login` | Page de connexion |
| POST | `/api/auth/login` | `{"email","password"}` → `{"data":{"requires_2fa":false,"csrf_token":"…","redirect":"/"}}` ou `{"requires_2fa":true}` |
| POST | `/api/auth/2fa/verify-login` | `{"code":"123456"}` (ou code de récupération `abcde-12345`) → comme login |

## Compte connecté (autorisé même si 2FA / changement de mot de passe à finaliser)

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/auth/me` | Profil, rôle, organisation, permissions, `security_setup_required` |
| POST | `/api/auth/logout` | Détruit la session |
| POST | `/api/auth/password` | `{"current_password","new_password"}` — révoque les autres sessions |
| POST | `/api/auth/2fa/setup` | → `{"secret","otpauth_uri"}` |
| POST | `/api/auth/2fa/enable` | `{"code":"123456"}` → `{"enabled":true,"recovery_codes":[8 codes],"csrf_token"}` |
| POST | `/api/auth/2fa/disable` | `{"password","code"}` — clients uniquement (403 pour les internes) |

## Organisations

| Méthode | Route | Permission | Notes |
|---|---|---|---|
| GET | `/api/organizations?q=&status=&page=&per_page=&sort=&dir=` | view_organization | Client : sa seule organisation |
| POST | `/api/organizations` | manage_organizations | Voir ci-dessous |
| GET | `/api/organizations/{id}` | view_organization | 404 hors périmètre |
| PUT | `/api/organizations/{id}` | manage_organizations (tous champs) ou manage_organization_settings (name, email, phone) | |
| DELETE | `/api/organizations/{id}` | manage_organizations | Suppression logique + révocation des sessions |

```json
POST /api/organizations
{ "name": "Boutique Kivu", "email": "contact@kivu.cd", "phone": "+243970000000", "country": "CD",
  "owner_email": "patron@kivu.cd", "owner_first_name": "Jean", "owner_last_name": "Mbuyi" }

201 { "data": { "organization": { "id": 3, "uuid": "…", "slug": "boutique-kivu", "status": "active", … },
                "owner_user_id": 4, "temporary_password": "Xk9…(affiché une seule fois)" } }
```

## Utilisateurs (permission `manage_team`)

| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/users?q=&status=&organization_id=&scope=internal` | `organization_id`/`scope` : contexte global uniquement |
| POST | `/api/users` | `{"email","first_name","last_name","role","organization_id?","password?"}` — client : organisation forcée ; interne : `organization_id` requis pour un rôle client ; rôle interne requiert `manage_internal_users` |
| GET | `/api/users/{id}` | |
| PUT | `/api/users/{id}` | `first_name`, `last_name`, `role`, `status` (`active`/`suspended`) |
| DELETE | `/api/users/{id}` | Suppression logique |

Règles : pas d'attribution d'un rôle supérieur au sien ; Owner attribuable uniquement par un Owner ; pas d'auto-modification de rôle/statut ; au moins un Owner actif par organisation. Un nouvel utilisateur reçoit `must_change_password = 1`.

## Rôles & permissions (lecture)

| Méthode | Route | Permission |
|---|---|---|
| GET | `/api/roles` | manage_team (rôles assignables ; matrice complète si view_roles) |
| GET | `/api/permissions` | view_roles |

## Audit

`GET /api/audit-logs?action=&user_id=&resource_type=&from=&to=&organization_id=&page=&per_page=` — permission `view_audit_logs`. Client : uniquement le journal de son organisation.

```json
{ "data": [ { "id": 42, "user_id": 2, "organization_id": 1, "action": "users.create", "resource_type": "user",
              "resource_id": "5", "ip": "41.x.x.x", "user_agent": "…", "metadata": { "status": 201 },
              "created_at": "2026-11-07 00:30:12.123" } ], "meta": { "page": 1, "per_page": 25, "total": 1 } }
```
Actions journalisées : `auth.login`, `auth.login_failed`, `auth.login_blocked`, `auth.2fa_failed`, `auth.logout`, toute écriture (`organizations.*`, `users.*`, `flags.update`, `security.*`) et chaque refus 403/404 sur une ressource (`*.denied`, ex. tentative cross-tenant).

## Feature flags

| Méthode | Route | Permission |
|---|---|---|
| GET | `/api/feature-flags/effective` | authentifié — `{"campaigns_module":false,…}` pour l'organisation courante |
| GET | `/api/feature-flags` | view_feature_flags |
| PUT | `/api/feature-flags/{key}` | manage_feature_flags — `{"enabled":true,"organization_id":3?}` (surcharge par organisation si fourni) |

## Thème (permission `manage_appearance`, réservé au super admin)

| Méthode | Route | Description |
|---|---|---|
| GET | `/theme.css` | Variables CSS du thème (public, utilisé par toutes les pages) |
| GET | `/api/theme` | Palette courante, mode par défaut et valeurs d'origine |
| PUT | `/api/theme` | `{ "default_mode": "light\|dark\|system", "palette": { "light": { "brand": "#4F46E5", … }, "dark": { … } } }` |
| POST | `/api/theme/reset` | Revient à la palette d'origine |

Jetons : `canvas`, `surface`, `surface2`, `line`, `ink`, `mute`, `brand`, `brandfg` (couleurs `#RRGGBB`).


## Contacts (permission `view_contacts` en lecture, `manage_contacts` en écriture)
Toutes les routes sont filtrées par organisation (ressource hors périmètre → 404). Un compte interne global doit fournir `organization_id` pour créer.

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/contacts` | Liste paginée. Filtres : `q`, `operator`, `status`, `country`, `source`, `tag`, `group_id`, `import_id`, `no_group`, `has_email`, `created_from`, `created_to`, `organization_id` (global) ; `sort` (`created_at`, `name`, `phone`), `dir`, `page`, `per_page` |
| POST | `/api/contacts` | Créer `{phone, country?, first_name?, last_name?, email?, status?, tags?, group_ids?, attributes?}` → 409 `CONTACT_EXISTS` si le numéro existe |
| GET/PUT/DELETE | `/api/contacts/{id}` | Fiche / modification / suppression (logique) |
| GET | `/api/contacts/{id}/history` | Historique des modifications |
| POST | `/api/contacts/bulk` | `{action: delete\|block\|unblock\|group_add\|group_remove\|tag_add\|tag_remove, ids[] ou rules, group_id?, tag?}` |
| POST | `/api/contacts/count` | `{rules}` → nombre + échantillon |
| GET | `/api/contacts/duplicates?by=phone_tail\|email\|name` · POST `/api/contacts/merge` `{keep_id, merge_ids[]}` | Doublons et fusion |
| GET | `/api/contacts/stats` · `/api/contacts/tags` | Indicateurs, catalogue d'étiquettes |
| GET | `/api/contacts/export` | CSV (permission `export_contacts`, audité) |
| GET/POST/PUT/DELETE | `/api/contact-groups[/{id}]` | Groupes `{name, description?}` |
| GET/POST/PUT/DELETE | `/api/segments[/{id}]` | Segments `{name, description?, rules}` ; `rules = {match: all\|any, conditions:[{field, op, value}]}` |
| GET | `/api/contact-imports` · `/api/contact-imports/{id}` | Historique / progression |
| POST | `/api/contact-imports` | Envoi (multipart, champ `file`) → aperçu et mappage suggéré |
| POST | `/api/contact-imports/{id}/start` | `{mapping:{phone,first_name,last_name,email,country,tags,custom:[{key,column}]}, options:{duplicate_mode: skip\|update\|merge, default_country, group_id?, new_group_name?, tags?}}` |
| POST | `/api/contact-imports/{id}/process` | Traite un lot ; à rappeler tant que `status = processing` |
| POST | `/api/contact-imports/{id}/cancel` | Annule |
| GET | `/api/contact-imports/{id}/errors` · `/errors.csv` | Lignes rejetées (`kind=invalid\|duplicate`) / CSV pour correction |

## Data & Compliance (`access_dashboard_data`)
`GET /api/data/stats`, `GET/PUT /api/data/retention` (écriture : `manage_data_retention`), `POST /api/data/purge` (interne global).
