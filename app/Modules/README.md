# Modules à venir

Chaque module suit la même convention, sans refonte du noyau :

```
app/Modules/<Nom>/
├── routes.php        # return static function (Router $r): void { ... }  — chargé automatiquement
├── Controllers/
├── Services/
├── Repositories/     # étendent App\Repositories\TenantRepository (isolation organization_id héritée)
└── README.md         # rôle, endpoints, permissions requises
```

Namespace : `App\Modules\<Nom>\...` (autoload PSR-4 existant). Migrations : `database/migrations/00N_<nom>.sql`.
Permissions : ajouter dans `config/permissions.php` puis relancer `php database/seed.php`.
Activation progressive : `FeatureFlagService::isEnabled('<flag>', $orgId)`.

| Module | Flag | Statut |
|---|---|---|
| Contacts Engine | — | TODO |
| Campaign Engine | `campaigns_module` | TODO |
| Messaging Engine + Provider Adapter | `messaging_module` | TODO |
| Intelligent Router + Queues (Redis) | — | TODO |
| Billing & Wallet | `billing_module` | TODO |
| API Gateway publique + Webhooks | `public_api` | TODO |
| Reporting, Notifications, Monitoring | — | TODO |
