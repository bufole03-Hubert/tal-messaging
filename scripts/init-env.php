<?php
declare(strict_types=1);

// Génère un fichier .env avec des secrets aléatoires (n'écrase jamais un .env existant).
$root = dirname(__DIR__);
$target = $root . '/.env';
if (is_file($target)) {
    fwrite(STDERR, ".env existe déjà : aucune modification.\n");
    exit(0);
}

$template = (string) file_get_contents($root . '/.env.example');
$secrets = [
    'APP_KEY' => bin2hex(random_bytes(32)),
    'DB_PASSWORD' => bin2hex(random_bytes(16)),
    'DB_ROOT_PASSWORD' => bin2hex(random_bytes(16)),
];
foreach ($secrets as $key => $value) {
    $template = preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $template) ?? $template;
}
file_put_contents($target, $template);
chmod($target, 0600);
echo ".env généré.\n";
