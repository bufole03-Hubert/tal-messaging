<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Support\Crypto;
use App\Support\Logger;
use App\Support\Str;
use App\Support\Totp;

/**
 * Authentification par session serveur (cookie HttpOnly + SameSite, stockage en base).
 * Note : les lectures d'authentification (par e-mail) sont les seules requêtes volontairement
 * non scopées par tenant : l'identité doit être résolue avant que le contexte tenant n'existe.
 */
final class AuthService
{
    /** @var array<int,array<string,mixed>> */
    private static array $userCache = [];

    /** @return array{requires_2fa:bool,csrf_token?:string,redirect?:string} */
    public static function attempt(string $email, string $password): array
    {
        $req = Request::current();
        $email = mb_strtolower(trim($email));
        self::assertNotThrottled($email, $req->ip);

        $row = Database::first(
            'SELECT u.id, u.password_hash, u.status, u.totp_enabled, u.organization_id, o.status AS org_status
             FROM users u LEFT JOIN organizations o ON o.id = u.organization_id
             WHERE u.email = ? AND u.deleted_at IS NULL',
            [$email]
        );
        $ok = password_verify($password, $row['password_hash'] ?? self::dummyHash()) && $row !== null;

        if (!$ok) {
            self::recordAttempt($email, $req->ip, false);
            AuditService::log('auth.login_failed', 'user', $row['id'] ?? null, ['email' => $email], $row['id'] ?? null, $row['organization_id'] ?? null);
            throw ApiException::unauthorized('Identifiants invalides.', 'INVALID_CREDENTIALS');
        }
        if ($row['status'] !== 'active' || ($row['organization_id'] !== null && $row['org_status'] !== 'active')) {
            AuditService::log('auth.login_blocked', 'user', $row['id'], [], (int) $row['id'], $row['organization_id']);
            throw ApiException::forbidden('Compte désactivé ou organisation suspendue.', 'ACCOUNT_DISABLED');
        }

        // Mise à niveau transparente du hachage si l'algorithme a évolué.
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if (password_needs_rehash($row['password_hash'], $algo)) {
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [Str::hashPassword($password), $row['id']]);
        }

        if ((int) $row['totp_enabled'] === 1) {
            Session::put('pending_2fa', ['uid' => (int) $row['id'], 'exp' => time() + 300, 'tries' => 0]);
            return ['requires_2fa' => true];
        }
        return self::completeLogin((int) $row['id']);
    }

    /** @return array{requires_2fa:bool,csrf_token:string,redirect:string} */
    public static function verifyLogin2fa(string $code): array
    {
        $pending = Session::get('pending_2fa');
        if (!is_array($pending) || $pending['exp'] < time()) {
            Session::forget('pending_2fa');
            throw ApiException::unauthorized('Session de vérification expirée. Reconnectez-vous.', 'TWO_FACTOR_EXPIRED');
        }
        $uid = (int) $pending['uid'];
        $req = Request::current();
        self::assertNotThrottled('2fa:' . $uid, $req->ip);

        $user = Database::first('SELECT id, organization_id, totp_secret, totp_last_step FROM users WHERE id = ? AND deleted_at IS NULL', [$uid]);
        $valid = false;
        if ($user !== null) {
            $code = trim($code);
            if (preg_match('/^[a-z0-9]{5}-[a-z0-9]{5}$/i', $code)) {
                $valid = self::consumeRecoveryCode($uid, $code);
            } else {
                $secret = Crypto::decrypt((string) $user['totp_secret']);
                if ($secret === null) {
                    // Secret illisible (APP_KEY modifiée ?) : à signaler clairement dans les logs.
                    Logger::error('2FA : secret TOTP indéchiffrable (APP_KEY modifiée ?). Utilisez scripts/reset-2fa.php', ['user_id' => $uid]);
                }
                $step = $secret !== null ? Totp::verify($secret, $code, (int) $user['totp_last_step'], self::totpWindow()) : null;
                if ($step !== null) {
                    Database::execute('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $uid]);
                    $valid = true;
                } elseif ($secret !== null) {
                    $off = Totp::clockOffset($secret, $code);
                    if ($off !== null) {
                        Logger::info('2FA : code valide mais horloge décalée', ['user_id' => $uid, 'offset_seconds' => $off, 'server_time_utc' => gmdate('c')]);
                    }
                }
            }
        }
        if (!$valid) {
            self::recordAttempt('2fa:' . $uid, $req->ip, false);
            $pending['tries']++;
            if ($pending['tries'] >= 5) {
                Session::forget('pending_2fa');
            } else {
                Session::put('pending_2fa', $pending);
            }
            AuditService::log('auth.2fa_failed', 'user', $uid, [], $uid, $user['organization_id'] ?? null);
            throw ApiException::unauthorized('Code de vérification invalide.', 'TWO_FACTOR_INVALID');
        }
        Session::forget('pending_2fa');
        return self::completeLogin($uid);
    }

    public static function totpWindow(): int
    {
        return (int) Config::get('app.security.totp_window', 2);
    }

    /** Vrai si le compte doit finaliser sa 2FA avant d'utiliser l'application. */
    public static function requiresTwoFactorSetup(array $user): bool
    {
        return $user['role_scope'] === 'internal'
            && !$user['totp_enabled']
            && (bool) Config::get('app.security.enforce_internal_2fa', true);
    }

    /** @return array{requires_2fa:bool,csrf_token:string,redirect:string} */
    private static function completeLogin(int $userId): array
    {
        Session::regenerate(); // anti fixation de session
        Session::put('user_id', $userId);
        Session::put('login_at', time());
        Session::put('last_activity', time());
        Session::put('ua_hash', hash('sha256', Request::current()->userAgent));
        Database::execute('UPDATE users SET last_login_at = UTC_TIMESTAMP(), updated_at = updated_at WHERE id = ?', [$userId]);
        self::recordAttempt('user:' . $userId, Request::current()->ip, true);

        $user = self::loadUser($userId);
        AuditService::log('auth.login', 'user', $userId, [], $userId, $user['organization_id'] ?? null);
        return ['requires_2fa' => false, 'csrf_token' => Session::csrfToken(), 'redirect' => '/'];
    }

    public static function logout(): void
    {
        $uid = Session::get('user_id');
        if ($uid !== null) {
            $user = self::loadUser((int) $uid);
            AuditService::log('auth.logout', 'user', (int) $uid, [], (int) $uid, $user['organization_id'] ?? null);
        }
        Session::destroy();
    }

    /** @return array<string,mixed>|null */
    public static function currentUser(): ?array
    {
        $uid = Session::get('user_id');
        if ($uid === null) {
            return null;
        }
        $now = time();
        $idle = (int) Config::get('app.session.idle');
        $abs = (int) Config::get('app.session.absolute');
        if ($now - (int) Session::get('last_activity', 0) > $idle
            || $now - (int) Session::get('login_at', 0) > $abs
            || Session::get('ua_hash') !== hash('sha256', Request::current()->userAgent)) {
            Session::destroy();
            return null;
        }
        $user = self::loadUser((int) $uid);
        if ($user === null || $user['status'] !== 'active' || ($user['organization_id'] !== null && $user['org_status'] !== 'active')) {
            Session::destroy();
            return null;
        }
        Session::put('last_activity', $now);
        return $user;
    }

    /** @return array<string,mixed>|null */
    private static function loadUser(int $id): ?array
    {
        if (isset(self::$userCache[$id])) {
            return self::$userCache[$id];
        }
        $u = Database::first(
            'SELECT u.id, u.uuid, u.organization_id, u.email, u.first_name, u.last_name, u.status, u.totp_enabled, u.must_change_password,
                    u.role_id, r.code AS role_code, r.name AS role_name, r.scope AS role_scope, r.level AS role_level,
                    o.name AS org_name, o.status AS org_status
             FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN organizations o ON o.id = u.organization_id
             WHERE u.id = ? AND u.deleted_at IS NULL',
            [$id]
        );
        if ($u === null) {
            return null;
        }
        $u['id'] = (int) $u['id'];
        $u['organization_id'] = $u['organization_id'] !== null ? (int) $u['organization_id'] : null;
        $u['role_level'] = (int) $u['role_level'];
        $u['totp_enabled'] = (bool) $u['totp_enabled'];
        $u['must_change_password'] = (bool) $u['must_change_password'];
        $u['permissions'] = array_column(Database::select(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
            [$u['role_id']]
        ), 'code');
        return self::$userCache[$id] = $u;
    }

    public static function forgetCache(int $userId): void
    {
        unset(self::$userCache[$userId]);
    }

    // ---- Recovery codes ----

    /** @return array<int,string> codes en clair (affichés une seule fois) */
    public static function generateRecoveryCodes(int $userId): array
    {
        Database::execute('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $code = strtolower(substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5));
            $codes[] = $code;
            Database::execute('INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (?, ?)', [$userId, hash('sha256', $code)]);
        }
        return $codes;
    }

    private static function consumeRecoveryCode(int $userId, string $code): bool
    {
        return Database::execute(
            'UPDATE totp_recovery_codes SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$userId, hash('sha256', strtolower($code))]
        ) === 1;
    }

    // ---- Anti brute-force ----

    private static function assertNotThrottled(string $identifier, string $ip): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - (int) Config::get('app.security.lockout_minutes') * 60);
        $max = (int) Config::get('app.security.max_login_attempts');
        $byId = (int) Database::scalar('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND success = 0 AND created_at > ?', [$identifier, $since]);
        $byIp = (int) Database::scalar('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [$ip, $since]);
        if ($byId >= $max || $byIp >= $max * 4) {
            throw ApiException::tooMany('Trop de tentatives échouées. Réessayez dans ' . Config::get('app.security.lockout_minutes') . ' minutes.');
        }
    }

    private static function recordAttempt(string $identifier, string $ip, bool $success): void
    {
        Database::execute('INSERT INTO login_attempts (identifier, ip, success) VALUES (?, ?, ?)', [$identifier, $ip, (int) $success]);
        if ($success) {
            Database::execute('DELETE FROM login_attempts WHERE identifier = ? AND success = 0', [$identifier]);
        }
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= Str::hashPassword('dummy-password-for-timing');
    }
}
