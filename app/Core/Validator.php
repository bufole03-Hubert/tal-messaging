<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validation stricte par liste blanche : seuls les champs déclarés sont retournés
 * (protection contre le mass-assignment).
 * Règles : required, nullable, string, int, bool, email, in:a,b, min:n, max:n, size:n, password
 */
final class Validator
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @return array<string,mixed>
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $out = [];
        foreach ($rules as $field => $ruleString) {
            $rs = explode('|', $ruleString);
            $present = array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';
            if (!$present) {
                if (in_array('required', $rs, true)) {
                    $errors[$field][] = 'Champ requis.';
                }
                continue;
            }
            $value = $data[$field];
            foreach ($rs as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                switch ($name) {
                    case 'string':
                        if (!is_string($value)) {
                            $errors[$field][] = 'Doit être du texte.';
                            continue 2;
                        }
                        $value = trim($value);
                        break;
                    case 'int':
                        $i = filter_var($value, FILTER_VALIDATE_INT);
                        if ($i === false) {
                            $errors[$field][] = 'Doit être un entier.';
                            continue 2;
                        }
                        $value = $i;
                        break;
                    case 'array':
                        if (!is_array($value)) {
                            $errors[$field][] = 'Doit être une liste.';
                            continue 2;
                        }
                        break;
                    case 'date':
                        $dt = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                        if ($dt === false || $dt->format('Y-m-d') !== $value) {
                            $errors[$field][] = 'Date invalide (AAAA-MM-JJ).';
                            continue 2;
                        }
                        break;
                    case 'bool':
                        $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($b === null) {
                            $errors[$field][] = 'Doit être un booléen.';
                            continue 2;
                        }
                        $value = $b;
                        break;
                    case 'email':
                        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = 'Adresse e-mail invalide.';
                        } else {
                            $value = mb_strtolower(trim($value));
                        }
                        break;
                    case 'in':
                        if (!in_array((string) $value, explode(',', (string) $arg), true)) {
                            $errors[$field][] = 'Valeur non autorisée.';
                        }
                        break;
                    case 'min':
                        if ((is_string($value) ? mb_strlen($value) : (is_array($value) ? count($value) : $value)) < (int) $arg) {
                            $errors[$field][] = 'Valeur trop courte ou trop petite (min ' . $arg . ').';
                        }
                        break;
                    case 'max':
                        if ((is_string($value) ? mb_strlen($value) : (is_array($value) ? count($value) : $value)) > (int) $arg) {
                            $errors[$field][] = 'Valeur trop longue ou trop grande (max ' . $arg . ').';
                        }
                        break;
                    case 'size':
                        if (!is_string($value) || mb_strlen($value) !== (int) $arg) {
                            $errors[$field][] = 'Longueur attendue : ' . $arg . '.';
                        }
                        break;
                    case 'password':
                        foreach (self::passwordErrors((string) $value) as $err) {
                            $errors[$field][] = $err;
                        }
                        break;
                }
            }
            $out[$field] = $value;
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        return $out;
    }

    /** @return array<int,string> */
    public static function passwordErrors(string $p): array
    {
        $min = (int) Config::get('app.security.password_min', 10);
        $e = [];
        if (mb_strlen($p) < $min) {
            $e[] = "Au moins $min caractères.";
        }
        if (mb_strlen($p) > 200) {
            $e[] = 'Maximum 200 caractères.';
        }
        if (!preg_match('/[a-z]/', $p) || !preg_match('/[A-Z]/', $p) || !preg_match('/\d/', $p)) {
            $e[] = 'Doit contenir majuscule, minuscule et chiffre.';
        }
        return $e;
    }
}
