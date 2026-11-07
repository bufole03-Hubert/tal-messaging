<?php
declare(strict_types=1);

/**
 * Tests unitaires sans base de données ni dépendance : php tests/run.php
 * (auth/TOTP, permissions, validation, isolation multi-tenant fail-closed)
 */
define('BASE_PATH', dirname(__DIR__));
putenv('APP_KEY=' . str_repeat('k', 40)); // avant le chargement de la config
require BASE_PATH . '/app/bootstrap.php';

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Validator;
use App\Repositories\OrganizationRepository;
use App\Repositories\UserRepository;
use App\Support\Crypto;
use App\Support\Totp;
use App\Tenancy\TenantContext;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  OK   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL $name — " . $e->getMessage() . "\n";
    }
}

function check(bool $cond, string $msg = 'assertion échouée'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function throws(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

echo "TOTP\n";
test('RFC 6238 : vecteur de test (t=59 → 287082)', function (): void {
    check(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59) === '287082');
});
test('verify accepte le code courant et refuse un code faux', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    check(Totp::verify($s, Totp::code($s, $now), 0, 1, $now) !== null);
    check(Totp::verify($s, '000000', 0, 1, $now) === null || Totp::code($s, $now) === '000000');
});
test('anti-rejeu : un pas déjà utilisé est refusé', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    $step = Totp::verify($s, Totp::code($s, $now), 0, 1, $now);
    check($step !== null);
    check(Totp::verify($s, Totp::code($s, $now), $step, 1, $now) === null);
});
test('tolérance d’horloge : code d’il y a 45 s accepté avec fenêtre 2, refusé avec fenêtre 1', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    $old = Totp::code($s, $now - 60);
    check(Totp::verify($s, $old, 0, 2, $now) !== null);
    check(Totp::verify($s, $old, 0, 1, $now) === null);
});
test('clockOffset détecte un téléphone décalé de 5 minutes', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    check(Totp::clockOffset($s, Totp::code($s, $now + 300), $now) === 300);
    check(Totp::clockOffset($s, Totp::code($s, $now - 300), $now) === -300);
});
test('code saisi avec espaces accepté', function (): void {
    $s = Totp::generateSecret();
    $now = 1_700_000_000;
    $c = Totp::code($s, $now);
    check(Totp::verify($s, substr($c, 0, 3) . ' ' . substr($c, 3), 0, 1, $now) !== null);
});
test('base32 aller-retour', function (): void {
    $raw = random_bytes(20);
    check(Totp::base32Decode(Totp::base32Encode($raw)) === $raw);
});

echo "Crypto\n";
test('chiffrement AES-256-GCM aller-retour + falsification détectée', function (): void {
    $enc = Crypto::encrypt('SECRET123');
    check(Crypto::decrypt($enc) === 'SECRET123');
    $raw = base64_decode($enc);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    check(Crypto::decrypt(base64_encode($raw)) === null);
});

echo "Permissions\n";
test('toute permission de la matrice existe dans le catalogue', function (): void {
    $cfg = Config::get('permissions');
    foreach ($cfg['roles'] as $code => $role) {
        if ($role['permissions'] === '*') {
            continue;
        }
        foreach ($role['permissions'] as $p) {
            check(isset($cfg['permissions'][$p]), "Permission inconnue « $p » pour le rôle $code");
        }
    }
});
test('viewer ne peut ni gérer l\'équipe ni envoyer de messages', function (): void {
    $p = Config::get('permissions.roles.viewer.permissions');
    check(!in_array('manage_team', $p, true) && !in_array('send_messages', $p, true));
});
test('aucun rôle client ne possède de permission de plateforme', function (): void {
    $cfg = Config::get('permissions');
    $platform = ['view_all_tenants', 'manage_organizations', 'manage_internal_users', 'manage_feature_flags', 'view_roles'];
    foreach ($cfg['roles'] as $code => $role) {
        if ($role['scope'] !== 'client') {
            continue;
        }
        check(!array_intersect($platform, $role['permissions']), "Le rôle client $code a une permission de plateforme");
    }
});
test('la hiérarchie interdit au client d\'atteindre le niveau interne', function (): void {
    $roles = Config::get('permissions.roles');
    check($roles['owner']['level'] <= $roles['ceo']['level']);
    check($roles['owner']['scope'] === 'client' && $roles['ceo']['scope'] === 'internal');
});

echo "Validation\n";
test('champs non déclarés ignorés (anti mass-assignment)', function (): void {
    $out = Validator::validate(['name' => 'Acme', 'role_id' => 1, 'organization_id' => 99], ['name' => 'required|string|min:2']);
    check($out === ['name' => 'Acme']);
});
test('e-mail invalide et champ requis rejetés (422)', function (): void {
    check(throws(fn() => Validator::validate(['email' => 'pas-un-email'], ['email' => 'required|email', 'name' => 'required']), ApiException::class));
});
test('politique de mot de passe', function (): void {
    check(Validator::passwordErrors('Abcdefghi1') === []);
    check(Validator::passwordErrors('court') !== []);
    check(Validator::passwordErrors('toutenminuscule123') !== []);
});

echo "Isolation multi-tenant\n";
test('fail-closed : sans TenantContext, tout accès repository est refusé', function (): void {
    TenantContext::reset();
    check(throws(fn() => (new UserRepository())->find(1), LogicException::class));
});
test('contexte client : filtre organization_id forcé', function (): void {
    TenantContext::forOrganization(7);
    [$sql, $params] = (new UserRepository())->tenantClause();
    check(str_contains($sql, 'organization_id') && $params === [7]);
    [$sqlO, $paramsO] = (new OrganizationRepository())->tenantClause();
    check(str_contains($sqlO, '`id`') && $paramsO === [7]);
    TenantContext::reset();
});
test('contexte global : aucun filtre', function (): void {
    TenantContext::forGlobal();
    [$sql, $params] = (new UserRepository())->tenantClause();
    check($sql === '1 = 1' && $params === []);
    TenantContext::reset();
});
test('un compte interne sans view_all_tenants n\'obtient aucun périmètre', function (): void {
    check(throws(fn() => TenantContext::initialize(['organization_id' => null, 'permissions' => []]), ApiException::class));
});
test('un compte client est limité à son organisation même avec des permissions étendues', function (): void {
    TenantContext::initialize(['organization_id' => 5, 'permissions' => ['view_all_tenants']]);
    check(TenantContext::current()->global === false && TenantContext::current()->organizationId === 5);
    TenantContext::reset();
});

echo sprintf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
