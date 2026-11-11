<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static bool $started = false;

    public static function isSecureTransport(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && in_array($_SERVER['REMOTE_ADDR'] ?? '', (array) Config::get('app.security.trusted_proxies', []), true));
    }

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('tal_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_url() !== '' ? base_url() : '/',
            'secure' => self::isSecureTransport(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_set_save_handler(new DbSessionHandler(), true);
        try {
            $ok = @session_start();
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            // Cause quasi certaine : base inaccessible ou migrations non exécutées (table « sessions » absente).
            throw new ApiException('SETUP_REQUIRED', 'Base de données non initialisée ou inaccessible. Lancez install.bat (ou « php database/migrate.php » puis « php database/seed.php »), et vérifiez DB_USER / DB_PASSWORD dans .env.', 503);
        }
        self::$started = true;
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function csrfToken(): string
    {
        self::start();
        return (string) $_SESSION['_csrf'];
    }

    /** Régénère l'ID de session et le jeton CSRF (après login/logout/élévation de privilège). */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$started = false;
    }

    public static function currentHash(): string
    {
        return hash('sha256', session_id());
    }

    /** Révoque toutes les sessions d'un utilisateur (sauf éventuellement la courante). */
    public static function destroyForUser(int $userId, ?string $exceptHash = null): void
    {
        if ($exceptHash === null) {
            Database::execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        } else {
            Database::execute('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$userId, $exceptHash]);
        }
    }
}
