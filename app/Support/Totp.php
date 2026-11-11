<?php
declare(strict_types=1);

namespace App\Support;

/** TOTP RFC 6238 (SHA-1, 30 s, 6 chiffres) — implémentation locale sans dépendance. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, int $timestamp, int $digits = 6): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('N2', 0, intdiv($timestamp, 30));
        $hash = hash_hmac('sha1', $counter, $key, true);
        $o = ord($hash[19]) & 0x0f;
        $bin = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * @return int|null le pas de temps validé (à mémoriser pour empêcher le rejeu) ou null
     */
    public static function verify(string $secret, string $code, int $lastStep = 0, int $window = 1, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now ??= time();
        $step = intdiv($now, 30);
        for ($i = -$window; $i <= $window; $i++) {
            $s = $step + $i;
            if ($s <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $s * 30), $code)) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Vrai si le code est mathématiquement valide dans la fenêtre mais a déjà été consommé
     * (pas de temps <= $lastStep). Sert uniquement à afficher un message clair : « attendez le prochain code ».
     */
    public static function isReplay(string $secret, string $code, int $lastStep, int $window = 1, ?int $now = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($lastStep <= 0 || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $step = intdiv($now ?? time(), 30);
        for ($i = -$window; $i <= $window; $i++) {
            $s = $step + $i;
            if ($s <= $lastStep && hash_equals(self::code($secret, $s * 30), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Diagnostic : cherche à quel décalage d'horloge le code saisi aurait été valide.
     * @return int|null décalage en secondes (positif = l'appareil est en avance sur le serveur), ou null
     */
    public static function clockOffset(string $secret, string $code, ?int $now = null, int $maxSteps = 240): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $step = intdiv($now ?? time(), 30);
        for ($i = 0; $i <= $maxSteps; $i++) {
            foreach ($i === 0 ? [0] : [$i, -$i] as $d) {
                if (hash_equals(self::code($secret, ($step + $d) * 30), $code)) {
                    return $d * 30;
                }
            }
        }
        return null;
    }

    public static function uri(string $issuer, string $account, string $secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer)
        );
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }
}
