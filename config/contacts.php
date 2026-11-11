<?php
declare(strict_types=1);

use App\Core\Env;

/** Limites du module Contacts (surchargeables via .env pour les plus utiles). */
return [
    'default_country' => strtoupper((string) Env::get('CONTACTS_DEFAULT_COUNTRY', 'CD')),
    'import' => [
        'max_bytes' => (int) Env::get('CONTACTS_IMPORT_MAX_MB', '5') * 1024 * 1024,
        'max_rows' => (int) Env::get('CONTACTS_IMPORT_MAX_ROWS', '50000'),
        'chunk_size' => 500,
        'max_stored_errors' => 5000,
        'max_custom_columns' => 10,
    ],
    'bulk_max_ids' => 1000,
    'export_max_rows' => 50000,
    'tags_max_per_contact' => 20,
    'attributes_max_per_contact' => 20,
    'retention_defaults' => [
        'import_files_days' => 30,
        'import_errors_days' => 90,
        'deleted_contacts_days' => 30,
    ],
];
