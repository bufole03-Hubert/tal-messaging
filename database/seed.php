<?php
declare(strict_types=1);

// Usage : php database/seed.php  (idempotent)
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Support\Str;

$cfg = Config::get('permissions');

// 1. Permissions
foreach ($cfg['permissions'] as $code => $desc) {
    Database::execute(
        'INSERT INTO permissions (code, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description)',
        [$code, $desc]
    );
}
$permIds = array_column(Database::select('SELECT id, code FROM permissions'), 'id', 'code');

// 2. Rôles + matrice rôles → permissions
foreach ($cfg['roles'] as $code => $role) {
    Database::execute(
        'INSERT INTO roles (code, name, scope, level) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), scope = VALUES(scope), level = VALUES(level)',
        [$code, $role['name'], $role['scope'], $role['level']]
    );
    $roleId = (int) Database::scalar('SELECT id FROM roles WHERE code = ?', [$code]);
    $codes = $role['permissions'] === '*' ? array_keys($cfg['permissions']) : $role['permissions'];
    Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    foreach ($codes as $pc) {
        Database::execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permIds[$pc]]);
    }
}

// 3. Feature flags globaux (modules à venir, désactivés par défaut)
$flags = [
    'campaigns_module' => 'Module campagnes',
    'messaging_module' => 'Module messagerie (SMS/WhatsApp)',
    'whatsapp_channel' => 'Canal WhatsApp Business',
    'public_api' => 'API publique',
    'billing_module' => 'Wallet et facturation',
    'maintenance_mode' => 'Mode maintenance',
];
foreach ($flags as $key => $desc) {
    Database::execute(
        'INSERT INTO feature_flags (flag_key, organization_scope, enabled, description) VALUES (?, 0, 0, ?)
         ON DUPLICATE KEY UPDATE description = VALUES(description)',
        [$key, $desc]
    );
}

// 4. Comptes seed (créés uniquement s'ils n'existent pas ; mots de passe aléatoires affichés une seule fois)
$created = [];
$makeUser = static function (string $email, string $roleCode, string $first, string $last, ?int $orgId, ?string $password, bool $mustChange) use (&$created): void {
    if (Database::scalar('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
        return;
    }
    $password = $password ?: Str::randomPassword();
    Database::execute(
        'INSERT INTO users (uuid, organization_id, role_id, email, password_hash, first_name, last_name, status, must_change_password)
         VALUES (?, ?, (SELECT id FROM roles WHERE code = ?), ?, ?, ?, ?, \'active\', ?)',
        [uuid4(), $orgId, $roleCode, $email, Str::hashPassword($password), $first, $last, (int) $mustChange]
    );
    $created[] = [$email, $password, $roleCode];
};

$makeOrg = static function (string $name, string $slug, string $email): int {
    $id = Database::scalar('SELECT id FROM organizations WHERE slug = ?', [$slug]);
    if ($id !== null) {
        return (int) $id;
    }
    Database::execute(
        "INSERT INTO organizations (uuid, name, slug, email, country, status) VALUES (?, ?, ?, ?, 'CD', 'active')",
        [uuid4(), $name, $slug, $email]
    );
    return Database::lastInsertId();
};

$makeUser((string) Env::get('SEED_CEO_EMAIL', 'ceo@talmessaging.local'), 'ceo', 'John', 'Moka', null, Env::get('SEED_CEO_PASSWORD'), true);

$demoPwd = Env::get('SEED_DEMO_PASSWORD');
$orgA = $makeOrg('Démo Entreprise A', 'demo-a', 'contact@demo-a.local');
$orgB = $makeOrg('Démo Entreprise B', 'demo-b', 'contact@demo-b.local');
$makeUser('owner@demo-a.local', 'owner', 'Alice', 'Owner-A', $orgA, $demoPwd, false);
$makeUser('owner@demo-b.local', 'owner', 'Bob', 'Owner-B', $orgB, $demoPwd, false);

echo "Seed terminé.\n";
if ($created) {
    echo "=== IDENTIFIANTS (affichés une seule fois) ===\n";
    foreach ($created as [$email, $pwd, $role]) {
        printf("  %-28s %-18s %s\n", $email, $pwd, $role);
    }
    echo "Le CEO devra activer la 2FA et changer son mot de passe à la première connexion.\n";
}
