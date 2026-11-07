<?php
declare(strict_types=1);

// Usage : php database/migrate.php
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Database;

$pdo = Database::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
  name VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$applied = array_column(Database::select('SELECT name FROM schema_migrations'), 'name');
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "Migration : $name\n";
    // Les DDL MySQL ne sont pas transactionnels : chaque instruction est exécutée séparément.
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
    Database::execute('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
}
echo "Migrations à jour.\n";
