<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Validation et normalisation des numéros au format E.164 (+243812345678), sans dépendance externe.
 * Formats acceptés : « 0812345678 », « 812345678 », « 243812345678 », « +243 81 234 56 78 »,
 * « 00243812345678 », « +243 (0) 81 234 56 78 ». Les pays connus (config/countries.php) ont une longueur contrôlée.
 */
final class PhoneNumber
{
    private const REASONS = [
        'empty' => 'Numéro vide',
        'scientific' => 'Format scientifique (cellule Excel mal formatée : mettez la colonne au format Texte)',
        'invalid_chars' => 'Caractères invalides',
        'too_short' => 'Numéro trop court',
        'too_long' => 'Numéro trop long',
        'bad_length' => 'Longueur incorrecte pour ce pays',
    ];

    /**
     * @return array{ok:bool,e164:?string,country:?string,operator:?string,reason:?string}
     */
    public static function normalize(string $raw, ?string $defaultCountry = null): array
    {
        $countries = (array) Config::get('countries.countries', []);
        $default = strtoupper($defaultCountry ?: (string) Config::get('contacts.default_country', 'CD'));
        $s = trim($raw);

        if ($s === '') {
            return self::fail('empty');
        }
        if (preg_match('/^\d+([.,]\d+)?e[+-]?\d+$/i', $s) === 1) {
            return self::fail('scientific');
        }
        if (preg_match('/[A-Za-z]/', $s) === 1 || preg_match('/^[+\d\s().\-\/]+$/', $s) !== 1) {
            return self::fail('invalid_chars');
        }
        $international = str_starts_with($s, '+');
        $digits = (string) preg_replace('/\D+/', '', $s);
        if ($digits === '') {
            return self::fail('invalid_chars');
        }
        if (!$international && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }

        if (!$international) {
            $dial = (string) ($countries[$default]['dial'] ?? '');
            $lengths = (array) ($countries[$default]['national_length'] ?? []);
            if ($dial !== '') {
                if (str_starts_with($digits, '0')) {
                    $digits = $dial . ltrim($digits, '0');                       // 0812345678
                } elseif (str_starts_with($digits, $dial) && in_array(strlen($digits) - strlen($dial), $lengths, true)) {
                    // déjà au format international sans « + » : 243812345678
                } elseif (in_array(strlen($digits), $lengths, true)) {
                    $digits = $dial . $digits;                                    // 812345678
                }
            }
        }

        // Pays reconnu par l'indicatif le plus long.
        $match = null;
        foreach ([3, 2, 1] as $len) {
            $prefix = substr($digits, 0, $len);
            foreach ($countries as $code => $c) {
                if ($c['dial'] === $prefix) {
                    $match = [$code, $c];
                    break 2;
                }
            }
        }

        if ($match !== null) {
            [$code, $c] = $match;
            $national = ltrim(substr($digits, strlen($c['dial'])), '0');          // « +243 (0) 81… »
            if (!in_array(strlen($national), (array) $c['national_length'], true)) {
                return self::fail('bad_length');
            }
            return [
                'ok' => true,
                'e164' => '+' . $c['dial'] . $national,
                'country' => $code,
                'operator' => self::operator($c, $national),
                'reason' => null,
            ];
        }

        // Pays non listé : contrôle E.164 générique (8 à 15 chiffres).
        if (strlen($digits) < 8) {
            return self::fail('too_short');
        }
        if (strlen($digits) > 15) {
            return self::fail('too_long');
        }
        return ['ok' => true, 'e164' => '+' . $digits, 'country' => null, 'operator' => null, 'reason' => null];
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASONS[$reason] ?? 'Numéro invalide';
    }

    /** @param array<string,mixed> $country */
    private static function operator(array $country, string $national): ?string
    {
        $prefix = substr($national, 0, 2);
        foreach ((array) ($country['operators'] ?? []) as $name => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return (string) $name;
            }
        }
        return null;
    }

    /** @return array{ok:bool,e164:?string,country:?string,operator:?string,reason:?string} */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'e164' => null, 'country' => null, 'operator' => null, 'reason' => $reason];
    }
}
