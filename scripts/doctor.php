<?php
declare(strict_types=1);

// Usage : php scripts/doctor.php — diagnostic d'installation (environnement, base, sessions, comptes).
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$problems = 0;
$ok = static function (string $m): void { echo "  [OK]    $m\n"; };
$ko = static function (string $m) use (&$problems): void { $problems++; echo "  [ERREUR] $m\n"; };

echo "PHP " . PHP_VERSION . "\n";
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'ctype'] as $ext) {
    extension_loaded($ext) ? $ok("extension $ext") : $ko("extension PHP manquante : $ext (décommentez-la dans php.ini)");
}
defined('PASSWORD_ARGON2ID') ? $ok('Argon2id disponible') : $ko('Argon2id indisponible dans cette version de PHP');

echo "\nConfiguration (.env)\n";
is_file(BASE_PATH . '/.env') ? $ok('.env présent') : $ko('.env absent : copiez .env.example en .env ou lancez php scripts/init-env.php');
strlen((string) Config::get('app.key')) >= 32 ? $ok('APP_KEY définie') : $ko('APP_KEY absente ou trop courte (32 caractères minimum)');
$c = (array) Config::get('database');
echo "  Base : {$c['user']}@{$c['host']}:{$c['port']}/{$c['name']}\n";

echo "\nBase de données\n";
try {
    Database::pdo();
    $ok('connexion MySQL');
} catch (Throwable $e) {
    $ko('connexion impossible : ' . $e->getMessage());
    $ko('vérifiez que MySQL est démarré, que la base « ' . $c['name'] . ' » existe, et DB_USER / DB_PASSWORD (XAMPP : root et mot de passe vide).');
    exit(1);
}
$expected = ['users', 'organizations', 'roles', 'permissions', 'sessions', 'audit_logs', 'contacts', 'contact_groups', 'segments', 'contact_imports'];
$have = array_column(Database::select('SHOW TABLES'), 'Tables_in_' . $c['name']);
foreach ($expected as $t) {
    in_array($t, $have, true) ? $ok("table $t") : $ko("table manquante : $t → lancez php database/migrate.php");
}
if (in_array('schema_migrations', $have, true)) {
    echo '  Migrations appliquées : ' . implode(', ', array_column(Database::select('SELECT name FROM schema_migrations ORDER BY name'), 'name')) . "\n";
}

if (in_array('sessions', $have, true)) {
    echo "\nSessions\n";
    try {
        $handler = new App\Core\DbSessionHandler();
        $id = bin2hex(random_bytes(16));
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('Mozilla/5.0 ', 20);
        App\Core\Request::current();
        $good = $handler->write($id, 'test|s:1:"x";');
        $back = $handler->read($id);
        $handler->destroy($id);
        ($good && $back === 'test|s:1:"x";') ? $ok('écriture / lecture de session') : $ko('la session ne se relit pas : ' . var_export([$good, $back], true));
    } catch (Throwable $e) {
        $ko('session : ' . $e->getMessage());
    }
}

if (in_array('users', $have, true)) {
    echo "\nComptes\n";
    $n = (int) Database::scalar('SELECT COUNT(*) FROM users');
    $n > 0 ? $ok("$n compte(s)") : $ko('aucun compte : lancez php database/seed.php');
    $ceo = Database::first('SELECT email, totp_enabled FROM users WHERE email = ?', [(string) App\Core\Env::get('SEED_CEO_EMAIL', 'ceo@talmessaging.local')]);
    $ceo ? $ok('compte CEO : ' . $ceo['email']) : $ko('compte CEO introuvable : lancez php database/seed.php');
}

echo "\nTest HTTP (page de connexion → jeton CSRF → envoi)\n";
$base = rtrim((string) Config::get('app.url'), '/');
$http = static function (string $method, string $url, array $headers = [], ?string $body = null): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]]);
    $out = @file_get_contents($url, false, $ctx);
    return [$out === false ? null : $out, $http_response_header ?? []];
};
[$page, $hdr] = $http('GET', $base . '/login');
if ($page === null) {
    $ko("Apache injoignable sur $base/login (Apache démarré ? APP_URL correct ?)");
} else {
    $cookie = null;
    foreach ($hdr as $h) {
        if (stripos($h, 'Set-Cookie: tal_sid=') === 0) {
            $cookie = trim(explode(';', substr($h, 12))[0]);
        }
    }
    preg_match('/name="csrf-token" content="([^"]*)"/', $page, $m);
    $token = $m[1] ?? '';
    $cookie !== null ? $ok('cookie de session reçu') : $ko('aucun cookie de session envoyé par le serveur');
    $token !== '' ? $ok('jeton CSRF présent dans la page') : $ko('jeton CSRF absent de la page');
    if ($cookie !== null && $token !== '') {
        [$res, $h2] = $http('POST', $base . '/api/auth/login', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $token, 'Cookie: ' . $cookie], json_encode(['email' => 'test@invalid.local', 'password' => 'x']));
        $code = json_decode((string) $res, true)['error']['code'] ?? '?';
        $code === 'CSRF_INVALID' ? $ko('CSRF_INVALID alors que le cookie et le jeton sont corrects : la session n\'est pas relue') : $ok("le jeton est accepté (réponse attendue : identifiants refusés, code $code)");
    }
}

echo $problems === 0 ? "\nTout est en ordre.\n" : "\n$problems problème(s) détecté(s).\n";
exit($problems === 0 ? 0 : 1);
