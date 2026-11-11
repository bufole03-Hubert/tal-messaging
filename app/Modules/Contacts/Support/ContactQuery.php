<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Support;

use App\Core\ApiException;

/**
 * Compile des conditions (filtres de liste ou règles de segment) en SQL paramétré sur l'alias « c » (contacts).
 * Tout est en liste blanche : champ, opérateur et type de valeur. Aucune valeur n'est concaténée dans le SQL.
 *
 * Format d'une règle : {"match":"all|any","conditions":[{"field":"operator","op":"in","value":["vodacom"]}]}
 */
final class ContactQuery
{
    /** @var array<string,array{ops:array<int,string>,type:string}> */
    public const FIELDS = [
        'search' => ['ops' => ['contains'], 'type' => 'text'],
        'name' => ['ops' => ['contains'], 'type' => 'text'],
        'phone' => ['ops' => ['starts_with', 'contains'], 'type' => 'phone'],
        'country' => ['ops' => ['in', 'not_in'], 'type' => 'country'],
        'operator' => ['ops' => ['in', 'not_in'], 'type' => 'operator'],
        'status' => ['ops' => ['in', 'not_in'], 'type' => 'status'],
        'source' => ['ops' => ['in', 'not_in'], 'type' => 'source'],
        'group' => ['ops' => ['in', 'not_in'], 'type' => 'int_list'],
        'tag' => ['ops' => ['in', 'not_in'], 'type' => 'tag_list'],
        'created_at' => ['ops' => ['after', 'before'], 'type' => 'date'],
        'has_email' => ['ops' => ['is'], 'type' => 'bool'],
        'no_group' => ['ops' => ['is'], 'type' => 'bool'],
        'import' => ['ops' => ['is'], 'type' => 'int'],
    ];

    /**
     * Valide et normalise des règles venues du client.
     * @return array{match:string,conditions:array<int,array{field:string,op:string,value:mixed}>}
     */
    public static function normalizeRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            throw ApiException::validation(['rules' => ['Règles invalides.']]);
        }
        $match = ($rules['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $raw = $rules['conditions'] ?? [];
        if (!is_array($raw) || count($raw) > 20) {
            throw ApiException::validation(['rules' => ['20 conditions maximum.']]);
        }
        $out = [];
        foreach (array_values($raw) as $i => $c) {
            $n = $i + 1;
            if (!is_array($c) || !isset($c['field'], $c['op']) || !is_string($c['field']) || !is_string($c['op'])) {
                throw ApiException::validation(['rules' => ["Condition $n invalide."]]);
            }
            $def = self::FIELDS[$c['field']] ?? null;
            if ($def === null || !in_array($c['op'], $def['ops'], true)) {
                throw ApiException::validation(['rules' => ["Condition $n : champ ou opérateur non autorisé."]]);
            }
            $out[] = ['field' => $c['field'], 'op' => $c['op'], 'value' => self::value($def['type'], $c['value'] ?? null, $n)];
        }
        return ['match' => $match, 'conditions' => $out];
    }

    /**
     * Transforme les paramètres de la liste (?status=active&operator=vodacom,airtel…) en règles « all ».
     * @param array<string,mixed> $q
     * @return array{match:string,conditions:array<int,array{field:string,op:string,value:mixed}>}
     */
    public static function fromQuery(array $q): array
    {
        $c = [];
        $list = static fn(mixed $v): array => array_values(array_filter(array_map('trim', explode(',', (string) $v)), static fn($x) => $x !== ''));
        foreach (['country', 'operator', 'status', 'source', 'tag'] as $f) {
            if (isset($q[$f]) && is_string($q[$f]) && $q[$f] !== '') {
                $c[] = ['field' => $f, 'op' => 'in', 'value' => $list($q[$f])];
            }
        }
        if (isset($q['group_id']) && is_string($q['group_id']) && $q['group_id'] !== '') {
            $c[] = ['field' => 'group', 'op' => 'in', 'value' => $list($q['group_id'])];
        }
        if (!empty($q['no_group'])) {
            $c[] = ['field' => 'no_group', 'op' => 'is', 'value' => true];
        }
        if (isset($q['import_id']) && $q['import_id'] !== '') {
            $c[] = ['field' => 'import', 'op' => 'is', 'value' => $q['import_id']];
        }
        if (isset($q['q']) && is_string($q['q']) && trim($q['q']) !== '') {
            $c[] = ['field' => 'search', 'op' => 'contains', 'value' => trim($q['q'])];
        }
        if (isset($q['created_from']) && $q['created_from'] !== '') {
            $c[] = ['field' => 'created_at', 'op' => 'after', 'value' => $q['created_from']];
        }
        if (isset($q['created_to']) && $q['created_to'] !== '') {
            $c[] = ['field' => 'created_at', 'op' => 'before', 'value' => $q['created_to']];
        }
        if (isset($q['has_email']) && in_array($q['has_email'], ['1', 'true'], true)) {
            $c[] = ['field' => 'has_email', 'op' => 'is', 'value' => true];
        }
        return self::normalizeRules(['match' => 'all', 'conditions' => $c]);
    }

    /**
     * @param array{match:string,conditions:array<int,array{field:string,op:string,value:mixed}>} $rules (déjà normalisées)
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function compile(array $rules, string $a = 'c'): array
    {
        $parts = [];
        $params = [];
        foreach ($rules['conditions'] as $cond) {
            [$sql, $p] = self::condition($cond['field'], $cond['op'], $cond['value'], $a);
            $parts[] = '(' . $sql . ')';
            array_push($params, ...$p);
        }
        if (!$parts) {
            return ['1 = 1', []];
        }
        return [implode($rules['match'] === 'any' ? ' OR ' : ' AND ', $parts), $params];
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function condition(string $field, string $op, mixed $v, string $a): array
    {
        $in = static fn(array $vals): string => implode(',', array_fill(0, count($vals), '?'));
        $like = static fn(string $s): string => '%' . addcslashes($s, '%_\\') . '%';

        switch ($field) {
            case 'search':
                $l = $like((string) $v);
                $digits = preg_replace('/\D+/', '', (string) $v) ?? '';
                $sql = "$a.first_name LIKE ? OR $a.last_name LIKE ? OR CONCAT_WS(' ', $a.first_name, $a.last_name) LIKE ? OR $a.email LIKE ?";
                $p = [$l, $l, $l, $l];
                if ($digits !== '') {
                    $sql .= " OR $a.phone_e164 LIKE ?";
                    $p[] = '%' . $digits . '%';
                }
                return [$sql, $p];
            case 'name':
                $l = $like((string) $v);
                return ["$a.first_name LIKE ? OR $a.last_name LIKE ? OR CONCAT_WS(' ', $a.first_name, $a.last_name) LIKE ?", [$l, $l, $l]];
            case 'phone':
                $d = (string) preg_replace('/\D+/', '', (string) $v);
                return ["$a.phone_e164 LIKE ?", [$op === 'starts_with' ? '+' . $d . '%' : '%' . $d . '%']];
            case 'country':
            case 'operator':
            case 'status':
            case 'source':
                $col = "$a.$field";
                $vals = (array) $v;
                if ($op === 'in') {
                    return ["$col IN (" . $in($vals) . ')', $vals];
                }
                // NOT IN doit aussi retenir les valeurs NULL (ex. pays ou opérateur inconnu)
                return ["($col IS NULL OR $col NOT IN (" . $in($vals) . '))', $vals];
            case 'group':
                $sub = "EXISTS (SELECT 1 FROM contact_group_members m WHERE m.contact_id = $a.id AND m.group_id IN (" . $in((array) $v) . '))';
                return [$op === 'in' ? $sub : 'NOT ' . $sub, (array) $v];
            case 'tag':
                $sub = "EXISTS (SELECT 1 FROM contact_tags t WHERE t.contact_id = $a.id AND t.tag IN (" . $in((array) $v) . '))';
                return [$op === 'in' ? $sub : 'NOT ' . $sub, (array) $v];
            case 'created_at':
                return $op === 'after'
                    ? ["$a.created_at >= ?", [$v . ' 00:00:00']]
                    : ["$a.created_at <= ?", [$v . ' 23:59:59']];
            case 'has_email':
                return $v ? ["($a.email IS NOT NULL AND $a.email <> '')", []] : ["($a.email IS NULL OR $a.email = '')", []];
            case 'no_group':
                $sub = "EXISTS (SELECT 1 FROM contact_group_members m WHERE m.contact_id = $a.id)";
                return [$v ? 'NOT ' . $sub : $sub, []];
            case 'import':
                return ["$a.import_id = ?", [(int) $v]];
        }
        throw new \LogicException('Champ de filtre inconnu : ' . $field);
    }

    private static function value(string $type, mixed $v, int $n): mixed
    {
        $err = static fn(string $m): ApiException => ApiException::validation(['rules' => ["Condition $n : $m"]]);
        $list = static function (mixed $v, int $max = 30) use ($err): array {
            if (is_string($v)) {
                $v = array_values(array_filter(array_map('trim', explode(',', $v)), static fn($x) => $x !== ''));
            }
            if (!is_array($v) || !$v || count($v) > $max) {
                throw $err('liste de valeurs attendue (1 à ' . $max . ').');
            }
            return array_values($v);
        };

        switch ($type) {
            case 'text':
                if (!is_string($v) || trim($v) === '' || mb_strlen($v) > 100) {
                    throw $err('texte attendu (100 caractères max).');
                }
                return trim($v);
            case 'phone':
                if (!is_string($v) || preg_replace('/\D+/', '', $v) === '' || strlen($v) > 20) {
                    throw $err('chiffres attendus.');
                }
                return $v;
            case 'country':
                $vals = array_map(static fn($x) => strtoupper(trim((string) $x)), $list($v));
                foreach ($vals as $x) {
                    if (preg_match('/^[A-Z]{2}$/', $x) !== 1) {
                        throw $err('code pays invalide.');
                    }
                }
                return $vals;
            case 'operator':
                $vals = array_map(static fn($x) => strtolower(trim((string) $x)), $list($v));
                foreach ($vals as $x) {
                    if (preg_match('/^[a-z0-9_]{2,20}$/', $x) !== 1) {
                        throw $err('opérateur invalide.');
                    }
                }
                return $vals;
            case 'status':
                $vals = array_map(static fn($x) => strtolower(trim((string) $x)), $list($v));
                if (array_diff($vals, ['active', 'blocked'])) {
                    throw $err('statut invalide.');
                }
                return $vals;
            case 'source':
                $vals = array_map(static fn($x) => strtolower(trim((string) $x)), $list($v));
                if (array_diff($vals, ['manual', 'import', 'api'])) {
                    throw $err('source invalide.');
                }
                return $vals;
            case 'int_list':
                $vals = [];
                foreach ($list($v) as $x) {
                    $i = filter_var($x, FILTER_VALIDATE_INT);
                    if ($i === false || $i < 1) {
                        throw $err('identifiant invalide.');
                    }
                    $vals[] = $i;
                }
                return $vals;
            case 'tag_list':
                $vals = array_map(static fn($x) => mb_strtolower(trim((string) $x)), $list($v));
                foreach ($vals as $x) {
                    if ($x === '' || mb_strlen($x) > 50) {
                        throw $err('étiquette invalide.');
                    }
                }
                return $vals;
            case 'date':
                $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
                if ($d === false || $d->format('Y-m-d') !== $v) {
                    throw $err('date invalide (AAAA-MM-JJ).');
                }
                return $v;
            case 'bool':
                $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    throw $err('booléen attendu.');
                }
                return $b;
            case 'int':
                $i = filter_var($v, FILTER_VALIDATE_INT);
                if ($i === false || $i < 1) {
                    throw $err('identifiant invalide.');
                }
                return $i;
        }
        throw new \LogicException('Type inconnu : ' . $type);
    }
}
