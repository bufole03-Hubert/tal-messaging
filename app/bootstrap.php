<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Autoloader PSR-4 minimal (namespace App\ => app/) : aucune dépendance Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/Support/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');
date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'UTC'));

ini_set('display_errors', App\Core\Config::get('app.debug') ? '1' : '0');
error_reporting(E_ALL);
