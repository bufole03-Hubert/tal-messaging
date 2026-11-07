<?php
declare(strict_types=1);

/**
 * Source unique de vérité : permissions granulaires + matrice rôles → permissions.
 * `database/seed.php` synchronise ces données en base (idempotent).
 * '*' = toutes les permissions.
 */
$clientBase = ['access_dashboard_client', 'view_organization', 'view_reports'];

return [
    'permissions' => [
        // Client
        'send_messages' => 'Envoyer des messages (Quick Send, API)',
        'manage_campaigns' => 'Créer et gérer des campagnes',
        'manage_contacts' => 'Gérer contacts, listes, imports',
        'manage_api_keys' => 'Gérer les clés API et webhooks',
        'manage_billing' => 'Gérer facturation et achats de crédits',
        'manage_team' => 'Gérer les utilisateurs de l’organisation',
        'manage_sender_ids' => 'Demander et gérer les Sender IDs',
        'manage_organization_settings' => 'Modifier le profil de l’organisation',
        'view_reports' => 'Consulter les rapports',
        'view_wallet' => 'Consulter le solde et les transactions',
        'view_organization' => 'Consulter le profil de l’organisation',
        // Plateforme
        'manage_organizations' => 'Créer, modifier, suspendre des organisations',
        'view_all_tenants' => 'Voir les données de toutes les organisations',
        'manage_internal_users' => 'Gérer les comptes internes TAL',
        'view_roles' => 'Consulter rôles et permissions',
        'view_audit_logs' => 'Consulter le journal d’audit',
        'view_feature_flags' => 'Consulter les feature flags',
        'manage_feature_flags' => 'Activer/désactiver les feature flags',
        'manage_appearance' => 'Personnaliser le thème (palette, mode par défaut)',
        'manage_providers' => 'Gérer fournisseurs et routage',
        'view_system_health' => 'Consulter la santé du système',
        'manage_tickets' => 'Gérer les tickets support',
        'manage_data_imports' => 'Superviser les imports de données',
        'view_operator_reports' => 'Consulter les rapports opérateurs',
        'manage_crm' => 'Gérer le pipeline commercial',
        // Accès aux dashboards
        'access_dashboard_ceo' => 'Accéder au dashboard CEO',
        'access_dashboard_cto' => 'Accéder au dashboard CTO',
        'access_dashboard_operations' => 'Accéder au dashboard Operations',
        'access_dashboard_sales' => 'Accéder au dashboard Sales & CS',
        'access_dashboard_data' => 'Accéder au dashboard Data & Compliance',
        'access_dashboard_support' => 'Accéder au dashboard Support',
        'access_dashboard_client' => 'Accéder au dashboard Client',
    ],

    'roles' => [
        // ---- Rôles internes ----
        'ceo' => ['name' => 'CEO', 'scope' => 'internal', 'level' => 100, 'permissions' => '*'],
        'cto' => ['name' => 'CTO', 'scope' => 'internal', 'level' => 90, 'permissions' => [
            'access_dashboard_cto', 'view_all_tenants', 'manage_team', 'view_roles', 'view_audit_logs',
            'view_feature_flags', 'manage_feature_flags', 'manage_providers', 'view_system_health',
            'manage_api_keys', 'view_organization', 'view_reports',
        ]],
        'operations_manager' => ['name' => 'Operations Manager', 'scope' => 'internal', 'level' => 70, 'permissions' => [
            'access_dashboard_operations', 'view_all_tenants', 'send_messages', 'manage_campaigns', 'manage_contacts',
            'manage_sender_ids', 'view_reports', 'view_organization', 'view_operator_reports', 'view_feature_flags',
        ]],
        'sales_cs' => ['name' => 'Sales & Customer Success', 'scope' => 'internal', 'level' => 60, 'permissions' => [
            'access_dashboard_sales', 'view_all_tenants', 'manage_organizations', 'manage_crm', 'manage_team',
            'view_organization', 'view_reports', 'view_wallet',
        ]],
        'data_compliance' => ['name' => 'Data & Compliance Manager', 'scope' => 'internal', 'level' => 60, 'permissions' => [
            'access_dashboard_data', 'view_all_tenants', 'manage_contacts', 'manage_data_imports', 'view_operator_reports',
            'view_audit_logs', 'view_reports', 'view_organization',
        ]],
        'support_agent' => ['name' => 'Support Agent', 'scope' => 'internal', 'level' => 50, 'permissions' => [
            'access_dashboard_support', 'view_all_tenants', 'manage_tickets', 'view_organization', 'view_reports',
        ]],

        // ---- Rôles client (par organisation) ----
        'owner' => ['name' => 'Owner', 'scope' => 'client', 'level' => 100, 'permissions' => array_merge($clientBase, [
            'send_messages', 'manage_campaigns', 'manage_contacts', 'manage_api_keys', 'manage_billing', 'manage_team',
            'manage_sender_ids', 'manage_organization_settings', 'view_wallet', 'view_audit_logs',
        ])],
        'admin' => ['name' => 'Admin', 'scope' => 'client', 'level' => 80, 'permissions' => array_merge($clientBase, [
            'send_messages', 'manage_campaigns', 'manage_contacts', 'manage_api_keys', 'manage_team',
            'manage_sender_ids', 'manage_organization_settings', 'view_wallet', 'view_audit_logs',
        ])],
        'campaign_manager' => ['name' => 'Campaign Manager', 'scope' => 'client', 'level' => 50, 'permissions' => array_merge($clientBase, [
            'send_messages', 'manage_campaigns', 'manage_contacts', 'manage_sender_ids', 'view_wallet',
        ])],
        'developer' => ['name' => 'Developer', 'scope' => 'client', 'level' => 40, 'permissions' => array_merge($clientBase, [
            'send_messages', 'manage_api_keys',
        ])],
        'viewer' => ['name' => 'Viewer', 'scope' => 'client', 'level' => 10, 'permissions' => array_merge($clientBase, ['view_wallet'])],
    ],
];
