<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', 'TAL Messaging'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'url' => Env::get('APP_URL', 'http://localhost:8080'),
    'key' => Env::get('APP_KEY', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Lubumbashi'),
    'session' => [
        'idle' => (int) Env::get('SESSION_IDLE_MINUTES', '30') * 60,
        'absolute' => (int) Env::get('SESSION_ABSOLUTE_HOURS', '12') * 3600,
    ],
    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'password_min' => 10,
        // Tolérance TOTP en pas de 30 s de part et d'autre (2 = ±60 s) : absorbe les horloges légèrement décalées.
        'totp_window' => max(1, min(4, (int) Env::get('TOTP_WINDOW', '2'))),
        // Mettre ENFORCE_INTERNAL_2FA=false pour laisser le super admin se connecter sans 2FA (dépannage).
        'enforce_internal_2fa' => filter_var(Env::get('ENFORCE_INTERNAL_2FA', 'true'), FILTER_VALIDATE_BOOLEAN),
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
    ],
];
