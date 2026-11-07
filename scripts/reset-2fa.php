<?php
declare(strict_types=1);

// Usage : php scripts/reset-2fa.php email@exemple.com
// Désactive la 2FA d'un compte (secret, codes de récupération, enrôlement en cours) : il pourra la reconfigurer.
// Avec Docker : docker compose exec web php scripts/reset-2fa.php email@exemple.com
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Database;

$email = mb_strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '') {
    fwrite(STDERR, "Usage : php scripts/reset-2fa.php email@exemple.com\n");
    exit(1);
}
$id = Database::scalar('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
if ($id === null || $id === false) {
    fwrite(STDERR, "Utilisateur introuvable : $email\n");
    exit(1);
}
Database::execute('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, totp_pending_secret = NULL, totp_pending_at = NULL WHERE id = ?', [$id]);
Database::execute('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$id]);
Database::execute('DELETE FROM sessions WHERE user_id = ?', [$id]);
Database::execute('DELETE FROM login_attempts WHERE identifier IN (?, ?)', ['2fa:' . $id, $email]);
echo "2FA réinitialisée pour $email. Connectez-vous puis reconfigurez-la dans « Sécurité ».\n";
echo "Heure serveur : " . gmdate('Y-m-d H:i:s') . " UTC (comparez-la à l'heure réelle).\n";
