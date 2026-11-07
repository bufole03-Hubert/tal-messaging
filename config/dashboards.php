<?php
declare(strict_types=1);

/** Les 7 espaces. Chaque dashboard exige la permission access_dashboard_<slug>. */
return [
    'ceo' => ['label' => 'CEO', 'icon' => 'chart', 'description' => 'Cockpit de direction : revenus, clients, marges, fournisseurs, alertes.',
        'sections' => ['Overview KPIs', 'Revenue Analytics', 'Clients & Top clients', 'Campagnes', 'Finance / Wallet', 'Fournisseurs (vue business)', 'Attention Center']],
    'cto' => ['label' => 'CTO', 'icon' => 'server', 'description' => 'Control Room : santé système, fournisseurs, router, API, webhooks, logs.',
        'sections' => ['System Health', 'Monitoring temps réel', 'Fournisseurs & Router', 'API Keys & Rate Limiting', 'Webhooks & Retry', 'Logs & Incidents', 'Feature Flags']],
    'operations' => ['label' => 'Operations', 'icon' => 'send', 'description' => 'Exécution quotidienne des campagnes et des communications.',
        'sections' => ['Message Center', 'Campagnes en cours', 'Calendrier', 'Imports', 'Rapports opérateurs']],
    'sales' => ['label' => 'Sales & CS', 'icon' => 'briefcase', 'description' => 'Pipeline commercial et succès client.',
        'sections' => ['Pipeline', 'Fiches clients', 'Clients à risque', 'Onboarding', 'Tâches & notes']],
    'data' => ['label' => 'Data & Compliance', 'icon' => 'database', 'description' => 'Qualité des données, imports et rapports opérateurs.',
        'sections' => ['Contacts', 'Imports CSV', 'Segments & listes', 'Rapports opérateurs', 'Conservation & exports']],
    'support' => ['label' => 'Support', 'icon' => 'headset', 'description' => 'Tickets et assistance aux clients.',
        'sections' => ['Tickets', 'Recherche message / campagne', 'Escalades']],
    'client' => ['label' => 'Client', 'icon' => 'building', 'description' => 'Espace des entreprises clientes.',
        'sections' => ['Solde & crédits', 'Quick Send', 'Campagnes', 'Contacts', 'Sender IDs', 'API Developer Center', 'Équipe']],
];
