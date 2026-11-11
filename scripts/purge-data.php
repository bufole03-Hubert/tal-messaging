<?php
declare(strict_types=1);

// Usage : php scripts/purge-data.php
// Applique les règles de conservation (fichiers d'import, rapports d'erreurs, contacts supprimés).
// À planifier chaque nuit : cron (0 3 * * * php /chemin/scripts/purge-data.php) ou le Planificateur de tâches Windows (XAMPP).
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Modules\Contacts\Services\RetentionService;
use App\Services\AuditService;

$r = RetentionService::get();
$res = RetentionService::purge();
AuditService::log('contacts.purge', 'app_setting', null, $res + ['source' => 'cli'], null, null);
echo sprintf("[%s] Purge terminée — fichiers : %d, erreurs : %d, contacts : %d (règles : %d/%d/%d jours)\n",
    gmdate('Y-m-d H:i:s'), $res['import_files'], $res['import_errors'], $res['contacts'],
    $r['import_files_days'], $r['import_errors_days'], $r['deleted_contacts_days']);
