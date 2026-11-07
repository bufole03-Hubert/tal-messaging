<?php
declare(strict_types=1);

/**
 * Tests de bout en bout minimaux contre un serveur lancé (Docker ou php -S).
 *
 *   E2E_BASE_URL=http://localhost:8080 E2E_PASSWORD='<mot de passe démo>' php tests/e2e.php
 *
 * Prérequis : base fraîchement seedée (org A = id 1, org B = id 2, owner B = user id 3),
 * et SEED_DEMO_PASSWORD identique à E2E_PASSWORD (sinon lire le mot de passe affiché par le seed).
 */
$base = rtrim(getenv('E2E_BASE_URL') ?: 'http://localhost:8080', '/');
$password = getenv('E2E_PASSWORD') ?: '';
$foreignOrg = (int) (getenv('E2E_FOREIGN_ORG_ID') ?: 2);
$foreignUser = (int) (getenv('E2E_FOREIGN_USER_ID') ?: 3);
if ($password === '') {
    fwrite(STDERR, "E2E_PASSWORD requis.\n");
    exit(2);
}

$jar = tempnam(sys_get_temp_dir(), 'tal');
$csrf = '';

function call(string $method, string $url, ?array $body = null): array
{
    global $jar, $csrf;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $raw];
}

$fail = 0;
function expect(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}

// Jeton CSRF initial (page de login)
[, $html] = call('GET', "$base/login");
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m);
$csrf = $m[1] ?? '';
expect('page de login expose un jeton CSRF', $csrf !== '');

[$s] = call('GET', "$base/api/users");
expect('anonyme → 401 sur endpoint protégé', $s === 401);

[$s] = call('POST', "$base/api/auth/login", ['email' => 'owner@demo-a.local', 'password' => 'mauvais-mot-de-passe']);
expect('mauvais mot de passe → 401', $s === 401);

[$s, $raw] = call('POST', "$base/api/auth/login", ['email' => 'owner@demo-a.local', 'password' => $password]);
$data = json_decode($raw, true)['data'] ?? [];
expect('login owner A → 200', $s === 200 && ($data['requires_2fa'] ?? true) === false);
$csrf = $data['csrf_token'] ?? $csrf;

[$s, $raw] = call('GET', "$base/api/auth/me");
expect('login → endpoint protégé /me → 200', $s === 200);

[$s, $raw] = call('GET', "$base/api/organizations");
$list = json_decode($raw, true)['data'] ?? [];
expect('owner A ne voit que sa propre organisation', $s === 200 && count($list) === 1);

[$s] = call('GET', "$base/api/organizations/$foreignOrg");
expect('accès cross-tenant (organisation B) → 404', $s === 404);

[$s] = call('GET', "$base/api/users/$foreignUser");
expect('accès cross-tenant (utilisateur B) → 404', $s === 404);

[$s] = call('PUT', "$base/api/organizations/$foreignOrg", ['name' => 'Piratage']);
expect('modification cross-tenant → 404', $s === 404);

[$s] = call('POST', "$base/api/organizations", ['name' => 'Interdit']);
expect('client ne peut pas créer d\'organisation → 403', $s === 403);

[$s] = call('GET', "$base/api/feature-flags");
expect('client sans view_feature_flags → 403', $s === 403);

unlink($jar);
echo $fail === 0 ? "\nTous les tests e2e passent.\n" : "\n$fail échec(s).\n";
exit($fail === 0 ? 0 : 1);
