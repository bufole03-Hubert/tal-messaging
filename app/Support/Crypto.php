<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/** Chiffrement AES-256-GCM (secrets 2FA, futurs credentials fournisseurs). */
final class Crypto
{
    private static function key(): string
    {
        $k = Config::get('app.key');
        if (!is_string($k) || strlen($k) < 32) {
            throw new \RuntimeException('APP_KEY absente ou trop courte (32 caractères minimum).');
        }
        return hash('sha256', $k, true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Échec du chiffrement.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
}
