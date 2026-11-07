<?php
declare(strict_types=1);

namespace App\Support;

final class Str
{
    public static function slug(string $s): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
        return $s !== '' ? $s : 'org';
    }

    /** Mot de passe aléatoire conforme à la politique (sans caractères ambigus). */
    public static function randomPassword(int $length = 16): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789'];
        $all = implode('', $sets);
        $pwd = '';
        foreach ($sets as $set) {
            $pwd .= $set[random_int(0, strlen($set) - 1)];
        }
        while (strlen($pwd) < $length) {
            $pwd .= $all[random_int(0, strlen($all) - 1)];
        }
        return str_shuffle($pwd);
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }
}
