<?php
declare(strict_types=1);

use App\Support\Icon;

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    /**
     * Préfixe d'URL de l'application : "/tal-messaging/public" sous XAMPP, "" si le site est à la racine.
     * Déduit de SCRIPT_NAME ; forçable avec APP_BASE_PATH dans .env ("/" = racine).
     */
    function base_url(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $forced = \App\Core\Env::get('APP_BASE_PATH');
        if ($forced !== null) {
            return $base = rtrim('/' . trim($forced, '/'), '/');
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (basename($script) !== 'index.php') {
            return $base = '';
        }
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return $base = ($dir === '.' ? '' : $dir);
    }
}

if (!function_exists('url')) {
    /** URL interne de l'application, préfixée par le sous-dossier éventuel. */
    function url(string $path = '/'): string
    {
        return base_url() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** URL d'un fichier public avec cache-busting (mtime). */
    function asset(string $path): string
    {
        $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
        $v = is_file($file) ? (string) filemtime($file) : '0';
        return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
    }
}

if (!function_exists('icon')) {
    /** Icône (police Tabler). $class : classes Tailwind, ex. "text-xl text-brand". */
    function icon(string $name, string $class = ''): string
    {
        return Icon::tag($name, $class);
    }
}

if (!function_exists('uuid4')) {
    function uuid4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
