<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Modules\Contacts\Repositories\ContactRepository;
use App\Modules\Contacts\Repositories\GroupRepository;
use App\Modules\Contacts\Repositories\ImportRepository;
use App\Modules\Contacts\Support\ContactQuery;
use App\Support\PhoneNumber;
use App\Tenancy\TenantContext;

/**
 * Logique métier des contacts. Toute lecture/écriture passe par le périmètre du TenantContext :
 * ContactRepository (find, paginate, update) ou scope() pour les requêtes personnalisées.
 */
final class ContactService
{
    /* ------------------------------------------------------------ Normalisation */

    /** @return array{e164:string,country:?string,operator:?string} */
    public static function phone(string $raw, ?string $defaultCountry = null): array
    {
        $r = PhoneNumber::normalize($raw, $defaultCountry);
        if (!$r['ok']) {
            throw ApiException::validation(['phone' => [PhoneNumber::reasonLabel((string) $r['reason'])]]);
        }
        return ['e164' => (string) $r['e164'], 'country' => $r['country'], 'operator' => $r['operator']];
    }

    /** @return array<int,string> */
    public static function tags(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }
        if (is_string($input)) {
            $input = explode(',', $input);
        }
        if (!is_array($input)) {
            throw ApiException::validation(['tags' => ['Liste d’étiquettes attendue.']]);
        }
        $out = [];
        foreach ($input as $t) {
            $t = mb_strtolower(trim((string) $t));
            if ($t === '') {
                continue;
            }
            if (preg_match('/^[\p{L}\p{N}_\- ]{1,50}$/u', $t) !== 1) {
                throw ApiException::validation(['tags' => ['Étiquette invalide : lettres, chiffres, espaces, _ et - (50 max).']]);
            }
            $out[$t] = true;
        }
        $out = array_keys($out);
        if (count($out) > (int) Config::get('contacts.tags_max_per_contact')) {
            throw ApiException::validation(['tags' => ['Trop d’étiquettes (' . Config::get('contacts.tags_max_per_contact') . ' max).']]);
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function attributes(mixed $input): array
    {
        if ($input === null || $input === '' || $input === []) {
            return [];
        }
        if (!is_array($input)) {
            throw ApiException::validation(['attributes' => ['Champs personnalisés invalides.']]);
        }
        if (count($input) > (int) Config::get('contacts.attributes_max_per_contact')) {
            throw ApiException::validation(['attributes' => ['Trop de champs personnalisés (' . Config::get('contacts.attributes_max_per_contact') . ' max).']]);
        }
        $out = [];
        foreach ($input as $k => $v) {
            $k = strtolower(trim((string) $k));
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k) !== 1) {
                throw ApiException::validation(['attributes' => ['Nom de champ invalide « ' . $k . ' » : lettres minuscules, chiffres et _ (40 max).']]);
            }
            if (!is_scalar($v)) {
                throw ApiException::validation(['attributes' => ['Valeur invalide pour « ' . $k . ' ».']]);
            }
            $v = trim((string) $v);
            if (mb_strlen($v) > 255) {
                throw ApiException::validation(['attributes' => ['Valeur trop longue pour « ' . $k . ' » (255 max).']]);
            }
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function clean(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        return $v === '' ? null : $v;
    }

    /** @param array<int,mixed> $ids @return array<int,int> identifiants de groupes valides pour l'organisation */
    private static function validGroupIds(array $ids, int $orgId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if (!$ids) {
            return [];
        }
        $rows = Database::select(
            'SELECT id FROM contact_groups WHERE organization_id = ? AND deleted_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge([$orgId], $ids)
        );
        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /* -------------------------------------------------------------- Lecture */

    /**
     * Ajoute tags, groupes et attributs décodés à une page de lignes.
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function hydrate(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $tags = [];
        foreach (Database::select("SELECT contact_id, tag FROM contact_tags WHERE contact_id IN ($in) ORDER BY tag", $ids) as $t) {
            $tags[(int) $t['contact_id']][] = $t['tag'];
        }
        $groups = [];
        foreach (Database::select(
            "SELECT m.contact_id, g.id, g.name FROM contact_group_members m
             JOIN contact_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
             WHERE m.contact_id IN ($in) ORDER BY g.name",
            $ids
        ) as $g) {
            $groups[(int) $g['contact_id']][] = ['id' => (int) $g['id'], 'name' => $g['name']];
        }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['organization_id'] = (int) $r['organization_id'];
            $r['import_id'] = $r['import_id'] !== null ? (int) $r['import_id'] : null;
            $r['attributes'] = is_string($r['attributes'] ?? null) ? (json_decode($r['attributes'], true) ?: (object) []) : (object) [];
            $r['tags'] = $tags[$r['id']] ?? [];
            $r['groups'] = $groups[$r['id']] ?? [];
        }
        return $rows;
    }

    /* --------------------------------------------------------------- Écriture */

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function create(array $in, int $actorId, int $orgId): array
    {
        $phone = self::phone((string) $in['phone'], $in['country'] ?? null);
        $tags = self::tags($in['tags'] ?? []);
        $attrs = self::attributes($in['attributes'] ?? []);
        $groupIds = self::validGroupIds((array) ($in['group_ids'] ?? []), $orgId);

        self::assertPhoneFree($orgId, $phone['e164'], null);

        $repo = new ContactRepository();
        try {
            $id = Database::transaction(function () use ($repo, $in, $phone, $tags, $attrs, $groupIds, $orgId, $actorId): int {
                $id = $repo->insert([
                    'uuid' => uuid4(),
                    'organization_id' => $orgId,
                    'phone_e164' => $phone['e164'],
                    'first_name' => self::clean($in['first_name'] ?? null),
                    'last_name' => self::clean($in['last_name'] ?? null),
                    'email' => self::clean($in['email'] ?? null),
                    'country' => $phone['country'],
                    'operator' => $phone['operator'],
                    'status' => ($in['status'] ?? 'active') === 'blocked' ? 'blocked' : 'active',
                    'source' => 'manual',
                    'attributes' => $attrs ? json_encode($attrs, JSON_UNESCAPED_UNICODE) : null,
                    'created_by' => $actorId,
                ]);
                self::syncTags($id, $orgId, $tags);
                self::syncGroups($id, $orgId, $groupIds);
                self::log($orgId, $id, $actorId, 'created', ['phone' => $phone['e164']]);
                return $id;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw ApiException::conflict('Ce numéro existe déjà dans cette organisation.', 'CONTACT_EXISTS');
            }
            throw $e;
        }
        return self::one($id);
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function update(int $id, array $in, int $actorId): array
    {
        $repo = new ContactRepository();
        $cur = $repo->find($id) ?? throw ApiException::notFound('Contact introuvable.');
        $orgId = (int) $cur['organization_id'];
        $upd = [];
        $changes = [];

        foreach (['first_name', 'last_name', 'email'] as $f) {
            if (array_key_exists($f, $in)) {
                $v = self::clean($in[$f]);
                if ($v !== $cur[$f]) {
                    $upd[$f] = $v;
                    $changes[$f] = [$cur[$f], $v];
                }
            }
        }
        if (isset($in['status']) && in_array($in['status'], ['active', 'blocked'], true) && $in['status'] !== $cur['status']) {
            $upd['status'] = $in['status'];
            $changes['status'] = [$cur['status'], $in['status']];
        }
        if (isset($in['phone']) && trim((string) $in['phone']) !== '') {
            $p = self::phone((string) $in['phone'], $in['country'] ?? $cur['country']);
            if ($p['e164'] !== $cur['phone_e164']) {
                self::assertPhoneFree($orgId, $p['e164'], $id);
                $upd['phone_e164'] = $p['e164'];
                $upd['country'] = $p['country'];
                $upd['operator'] = $p['operator'];
                $changes['phone'] = [$cur['phone_e164'], $p['e164']];
            }
        }
        if (array_key_exists('attributes', $in)) {
            $attrs = self::attributes($in['attributes']);
            $old = is_string($cur['attributes']) ? (json_decode($cur['attributes'], true) ?: []) : [];
            if ($attrs !== $old) {
                $upd['attributes'] = $attrs ? json_encode($attrs, JSON_UNESCAPED_UNICODE) : null;
                $changes['attributes'] = [$old, $attrs];
            }
        }

        try {
            Database::transaction(function () use ($repo, $id, $orgId, $actorId, $in, $upd, &$changes): void {
                if ($upd) {
                    $repo->update($id, $upd);
                }
                if (array_key_exists('tags', $in)) {
                    $new = self::tags($in['tags']);
                    $old = array_column(Database::select('SELECT tag FROM contact_tags WHERE contact_id = ? ORDER BY tag', [$id]), 'tag');
                    sort($new);
                    if ($new !== $old) {
                        self::syncTags($id, $orgId, $new);
                        $changes['tags'] = [$old, $new];
                    }
                }
                if (array_key_exists('group_ids', $in)) {
                    $new = self::validGroupIds((array) $in['group_ids'], $orgId);
                    $old = array_map('intval', array_column(Database::select('SELECT group_id FROM contact_group_members WHERE contact_id = ?', [$id]), 'group_id'));
                    sort($new);
                    sort($old);
                    if ($new !== $old) {
                        self::syncGroups($id, $orgId, $new);
                        $changes['groups'] = [$old, $new];
                    }
                }
                if ($changes) {
                    self::log($orgId, $id, $actorId, 'updated', $changes);
                }
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw ApiException::conflict('Ce numéro existe déjà dans cette organisation.', 'CONTACT_EXISTS');
            }
            throw $e;
        }
        return self::one($id);
    }

    public static function delete(int $id, int $actorId): void
    {
        $repo = new ContactRepository();
        $cur = $repo->find($id) ?? throw ApiException::notFound('Contact introuvable.');
        $repo->softDelete($id);
        self::log((int) $cur['organization_id'], $id, $actorId, 'deleted', ['phone' => $cur['phone_e164']]);
    }

    /** @return array<string,mixed> */
    public static function one(int $id): array
    {
        $row = (new ContactRepository())->find($id) ?? throw ApiException::notFound('Contact introuvable.');
        return self::hydrate([$row])[0];
    }

    /** @return array<int,array<string,mixed>> */
    public static function history(int $id): array
    {
        (new ContactRepository())->find($id) ?? throw ApiException::notFound('Contact introuvable.');
        $rows = Database::select(
            'SELECT ch.id, ch.action, ch.changes, ch.created_at, u.first_name, u.last_name
             FROM contact_changes ch LEFT JOIN users u ON u.id = ch.user_id
             WHERE ch.contact_id = ? ORDER BY ch.id DESC LIMIT 50',
            [$id]
        );
        foreach ($rows as &$r) {
            $r['changes'] = is_string($r['changes']) ? json_decode($r['changes'], true) : null;
            $name = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']);
            $r['user'] = $name !== '' ? $name : null;
            unset($r['first_name'], $r['last_name']);
        }
        return $rows;
    }

    private static function assertPhoneFree(int $orgId, string $e164, ?int $exceptId): void
    {
        $existing = Database::scalar(
            'SELECT id FROM contacts WHERE organization_id = ? AND phone_e164 = ? AND deleted_at IS NULL' . ($exceptId ? ' AND id <> ?' : ''),
            $exceptId ? [$orgId, $e164, $exceptId] : [$orgId, $e164]
        );
        if ($existing !== null) {
            throw new ApiException('CONTACT_EXISTS', 'Ce numéro existe déjà dans cette organisation.', 409, ['contact_id' => (int) $existing]);
        }
    }

    /** @param array<int,string> $tags */
    private static function syncTags(int $id, int $orgId, array $tags): void
    {
        Database::execute('DELETE FROM contact_tags WHERE contact_id = ?', [$id]);
        foreach ($tags as $t) {
            Database::execute('INSERT IGNORE INTO contact_tags (contact_id, organization_id, tag) VALUES (?, ?, ?)', [$id, $orgId, $t]);
        }
    }

    /** @param array<int,int> $groupIds */
    private static function syncGroups(int $id, int $orgId, array $groupIds): void
    {
        Database::execute('DELETE FROM contact_group_members WHERE contact_id = ?', [$id]);
        foreach ($groupIds as $g) {
            Database::execute('INSERT IGNORE INTO contact_group_members (group_id, contact_id, organization_id) VALUES (?, ?, ?)', [$g, $id, $orgId]);
        }
    }

    /** @param array<string,mixed>|null $changes */
    public static function log(int $orgId, int $contactId, ?int $userId, string $action, ?array $changes = null): void
    {
        Database::execute(
            'INSERT INTO contact_changes (organization_id, contact_id, user_id, action, changes) VALUES (?, ?, ?, ?, ?)',
            [$orgId, $contactId, $userId, $action, $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null]
        );
    }

    /* ---------------------------------------------------------- Actions en lot */

    /**
     * @param array{ids?:array<int,int>,rules?:array<string,mixed>} $selection
     * @param array<string,mixed> $params group_id | tag
     * @return array{affected:int}
     */
    public static function bulk(string $action, array $selection, array $params, int $actorId): array
    {
        $repo = new ContactRepository();
        [$scope, $sp] = $repo->scope();
        if (isset($selection['ids'])) {
            $ids = array_values(array_unique(array_map('intval', $selection['ids'])));
            if (!$ids || count($ids) > (int) Config::get('contacts.bulk_max_ids')) {
                throw ApiException::validation(['ids' => ['Sélection de 1 à ' . Config::get('contacts.bulk_max_ids') . ' contacts attendue.']]);
            }
            $where = "$scope AND c.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
            $wp = array_merge($sp, $ids);
        } else {
            [$fs, $fp] = ContactQuery::compile(ContactQuery::normalizeRules($selection['rules'] ?? ['conditions' => []]));
            $where = "$scope AND ($fs)";
            $wp = array_merge($sp, $fp);
        }

        $histo = static function (string $act, ?array $data) use ($where, $wp, $actorId): void {
            Database::execute(
                "INSERT INTO contact_changes (organization_id, contact_id, user_id, action, changes)
                 SELECT c.organization_id, c.id, ?, ?, ? FROM contacts c WHERE $where",
                array_merge([$actorId, $act, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null], $wp)
            );
        };

        switch ($action) {
            case 'delete':
                return Database::transaction(function () use ($histo, $where, $wp): array {
                    $histo('deleted', ['bulk' => true]);
                    return ['affected' => Database::execute("UPDATE contacts c SET c.deleted_at = UTC_TIMESTAMP() WHERE $where", $wp)];
                });
            case 'block':
            case 'unblock':
                $status = $action === 'block' ? 'blocked' : 'active';
                return Database::transaction(function () use ($histo, $where, $wp, $status): array {
                    $histo('updated', ['status' => ['bulk', $status]]);
                    return ['affected' => Database::execute("UPDATE contacts c SET c.status = ? WHERE $where", array_merge([$status], $wp))];
                });
            case 'group_add':
            case 'group_remove':
                $group = (new GroupRepository())->find((int) ($params['group_id'] ?? 0)) ?? throw ApiException::validation(['group_id' => ['Groupe introuvable.']]);
                $gid = (int) $group['id'];
                $gorg = (int) $group['organization_id'];
                if ($action === 'group_add') {
                    return Database::transaction(function () use ($histo, $where, $wp, $gid, $gorg): array {
                        $histo('group_added', ['group_id' => $gid]);
                        // Le groupe et le contact doivent appartenir à la même organisation.
                        $n = Database::execute(
                            "INSERT IGNORE INTO contact_group_members (group_id, contact_id, organization_id)
                             SELECT ?, c.id, c.organization_id FROM contacts c WHERE $where AND c.organization_id = ?",
                            array_merge([$gid], $wp, [$gorg])
                        );
                        return ['affected' => $n];
                    });
                }
                return Database::transaction(function () use ($histo, $where, $wp, $gid): array {
                    $histo('group_removed', ['group_id' => $gid]);
                    return ['affected' => Database::execute(
                        "DELETE m FROM contact_group_members m JOIN contacts c ON c.id = m.contact_id WHERE m.group_id = ? AND $where",
                        array_merge([$gid], $wp)
                    )];
                });
            case 'tag_add':
            case 'tag_remove':
                $tag = self::tags([$params['tag'] ?? ''])[0] ?? throw ApiException::validation(['tag' => ['Étiquette requise.']]);
                if ($action === 'tag_add') {
                    return Database::transaction(function () use ($histo, $where, $wp, $tag): array {
                        $histo('tag_added', ['tag' => $tag]);
                        return ['affected' => Database::execute(
                            "INSERT IGNORE INTO contact_tags (contact_id, organization_id, tag) SELECT c.id, c.organization_id, ? FROM contacts c WHERE $where",
                            array_merge([$tag], $wp)
                        )];
                    });
                }
                return Database::transaction(function () use ($histo, $where, $wp, $tag): array {
                    $histo('tag_removed', ['tag' => $tag]);
                    return ['affected' => Database::execute(
                        "DELETE t FROM contact_tags t JOIN contacts c ON c.id = t.contact_id WHERE t.tag = ? AND $where",
                        array_merge([$tag], $wp)
                    )];
                });
        }
        throw ApiException::validation(['action' => ['Action inconnue.']]);
    }

    /* -------------------------------------------------- Doublons et fusion */

    /** @return array<int,array<string,mixed>> */
    public static function duplicates(string $by): array
    {
        $repo = new ContactRepository();
        [$scope, $sp] = $repo->scope();
        [$expr, $cond, $having] = match ($by) {
            'email' => ['LOWER(c.email)', "c.email IS NOT NULL AND c.email <> ''", 'COUNT(*) > 1'],
            'name' => ["LOWER(CONCAT_WS(' ', c.first_name, c.last_name))",
                "c.first_name IS NOT NULL AND c.first_name <> '' AND c.last_name IS NOT NULL AND c.last_name <> ''", 'COUNT(*) > 1'],
            'phone_tail' => ['RIGHT(c.phone_e164, 9)', '1 = 1', 'COUNT(DISTINCT c.phone_e164) > 1'],
            default => throw ApiException::validation(['by' => ['Critère inconnu (email, name, phone_tail).']]),
        };
        $groups = Database::select(
            "SELECT c.organization_id, $expr AS k, COUNT(*) AS n FROM contacts c
             WHERE $scope AND $cond GROUP BY c.organization_id, $expr HAVING $having ORDER BY n DESC, k LIMIT 25",
            $sp
        );
        $out = [];
        foreach ($groups as $g) {
            $rows = Database::select(
                "SELECT c.id, c.organization_id, c.phone_e164, c.first_name, c.last_name, c.email, c.source, c.created_at
                 FROM contacts c WHERE $scope AND c.organization_id = ? AND $expr = ? ORDER BY c.created_at, c.id LIMIT 20",
                array_merge($sp, [$g['organization_id'], $g['k']])
            );
            $out[] = ['key' => $g['k'], 'organization_id' => (int) $g['organization_id'], 'count' => (int) $g['n'], 'contacts' => $rows];
        }
        return $out;
    }

    /**
     * Fusionne des contacts dans « keep » : complète les champs vides, réunit étiquettes, groupes et attributs.
     * @param array<int,int> $mergeIds
     * @return array<string,mixed>
     */
    public static function merge(int $keepId, array $mergeIds, int $actorId): array
    {
        $repo = new ContactRepository();
        $keep = $repo->find($keepId) ?? throw ApiException::notFound('Contact à conserver introuvable.');
        $orgId = (int) $keep['organization_id'];
        $mergeIds = array_values(array_unique(array_filter(array_map('intval', $mergeIds), static fn(int $i): bool => $i > 0 && $i !== $keepId)));
        if (!$mergeIds || count($mergeIds) > 20) {
            throw ApiException::validation(['merge_ids' => ['1 à 20 contacts à fusionner attendus.']]);
        }
        $others = [];
        foreach ($mergeIds as $mid) {
            $o = $repo->find($mid) ?? throw ApiException::notFound('Contact à fusionner introuvable.');
            if ((int) $o['organization_id'] !== $orgId) {
                throw ApiException::validation(['merge_ids' => ['Les contacts doivent appartenir à la même organisation.']]);
            }
            $others[] = $o;
        }

        Database::transaction(function () use ($repo, $keep, $others, $orgId, $keepId, $actorId): void {
            $fill = [];
            $attrs = is_string($keep['attributes']) ? (json_decode($keep['attributes'], true) ?: []) : [];
            foreach ($others as $o) {
                foreach (['first_name', 'last_name', 'email'] as $f) {
                    if (($keep[$f] === null || $keep[$f] === '') && !isset($fill[$f]) && $o[$f] !== null && $o[$f] !== '') {
                        $fill[$f] = $o[$f];
                    }
                }
                $oa = is_string($o['attributes']) ? (json_decode($o['attributes'], true) ?: []) : [];
                $attrs += $oa; // les attributs du contact conservé restent prioritaires
                Database::execute(
                    'INSERT IGNORE INTO contact_tags (contact_id, organization_id, tag) SELECT ?, organization_id, tag FROM contact_tags WHERE contact_id = ?',
                    [$keepId, $o['id']]
                );
                Database::execute(
                    'INSERT IGNORE INTO contact_group_members (group_id, contact_id, organization_id)
                     SELECT group_id, ?, organization_id FROM contact_group_members WHERE contact_id = ?',
                    [$keepId, $o['id']]
                );
                $repo->softDelete((int) $o['id']);
                self::log($orgId, (int) $o['id'], $actorId, 'merged_into', ['keep_id' => $keepId]);
            }
            $fill['attributes'] = $attrs ? json_encode(array_slice($attrs, 0, (int) Config::get('contacts.attributes_max_per_contact'), true), JSON_UNESCAPED_UNICODE) : null;
            $repo->update($keepId, $fill);
            self::log($orgId, $keepId, $actorId, 'merged', ['merged_ids' => array_map(static fn(array $o): int => (int) $o['id'], $others)]);
        });
        return self::one($keepId);
    }

    /* ---------------------------------------------------- Statistiques, tags */

    /** @return array<string,mixed> */
    public static function stats(): array
    {
        $repo = new ContactRepository();
        [$scope, $sp] = $repo->scope();
        $ctx = TenantContext::current();

        $t = Database::first(
            "SELECT COUNT(*) AS total,
                COALESCE(SUM(c.status = 'active'), 0) AS active,
                COALESCE(SUM(c.status = 'blocked'), 0) AS blocked,
                COALESCE(SUM(c.email IS NOT NULL AND c.email <> ''), 0) AS with_email,
                COALESCE(SUM((c.first_name IS NOT NULL AND c.first_name <> '') OR (c.last_name IS NOT NULL AND c.last_name <> '')), 0) AS with_name
             FROM contacts c WHERE $scope",
            $sp
        ) ?? [];
        $totals = array_map('intval', $t);

        $dist = static function (string $expr) use ($scope, $sp): array {
            return array_map(
                static fn(array $r): array => ['key' => (string) $r['k'], 'count' => (int) $r['n']],
                Database::select("SELECT $expr AS k, COUNT(*) AS n FROM contacts c WHERE $scope GROUP BY k ORDER BY n DESC LIMIT 10", $sp)
            );
        };

        $since = gmdate('Y-m-d 00:00:00', strtotime('-29 days'));
        $byDay = [];
        foreach (Database::select(
            "SELECT DATE(c.created_at) AS d, COUNT(*) AS n FROM contacts c WHERE $scope AND c.created_at >= ? GROUP BY DATE(c.created_at)",
            array_merge($sp, [$since])
        ) as $r) {
            $byDay[(string) $r['d']] = (int) $r['n'];
        }
        $growth = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', strtotime("-$i days"));
            $growth[] = ['date' => $d, 'count' => $byDay[$d] ?? 0];
        }

        $imports = new ImportRepository();
        [$is, $ip] = $imports->scope();
        $imp = Database::first(
            "SELECT COUNT(*) AS imports, COALESCE(SUM(i.processed_rows), 0) AS `rows`, COALESCE(SUM(i.created_rows), 0) AS created,
                COALESCE(SUM(i.updated_rows), 0) AS updated, COALESCE(SUM(i.duplicate_rows), 0) AS duplicates,
                COALESCE(SUM(i.invalid_rows), 0) AS invalid
             FROM contact_imports i WHERE $is AND i.created_at >= ?",
            array_merge($ip, [gmdate('Y-m-d H:i:s', strtotime('-90 days'))])
        ) ?? [];
        $imp = array_map('intval', $imp);
        $rows = max(0, (int) ($imp['rows'] ?? 0));
        $imp['valid_rate'] = $rows > 0 ? round((($rows - $imp['invalid']) / $rows) * 100, 1) : null;
        $imp['duplicate_rate'] = $rows > 0 ? round(($imp['duplicates'] / $rows) * 100, 1) : null;

        $recent = $imports->paginate('1 = 1', [], 1, 8, 'created_at', 'desc')['data'];

        $gr = new GroupRepository();
        [$gs, $gp] = $gr->scope();
        $segs = new \App\Modules\Contacts\Repositories\SegmentRepository();
        [$ss, $spp] = $segs->scope();

        $out = [
            'totals' => $totals + [
                'groups' => (int) Database::scalar("SELECT COUNT(*) FROM contact_groups g WHERE $gs", $gp),
                'segments' => (int) Database::scalar("SELECT COUNT(*) FROM segments s WHERE $ss", $spp),
            ],
            'by_operator' => $dist("COALESCE(c.operator, 'inconnu')"),
            'by_country' => $dist("COALESCE(c.country, '??')"),
            'by_source' => $dist('c.source'),
            'growth' => $growth,
            'imports_90d' => $imp,
            'recent_imports' => array_map([ImportRepository::class, 'present'], $recent),
        ];
        if ($ctx->global) {
            $out['top_organizations'] = array_map(
                static fn(array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'count' => (int) $r['n']],
                Database::select(
                    'SELECT o.id, o.name, COUNT(c.id) AS n FROM organizations o JOIN contacts c ON c.organization_id = o.id AND c.deleted_at IS NULL
                     WHERE o.deleted_at IS NULL GROUP BY o.id, o.name ORDER BY n DESC LIMIT 10'
                )
            );
        }
        return $out;
    }

    /** @return array<int,array{tag:string,count:int}> */
    public static function tagCatalog(): array
    {
        [$scope, $sp] = (new ContactRepository())->scope();
        return array_map(
            static fn(array $r): array => ['tag' => (string) $r['tag'], 'count' => (int) $r['n']],
            Database::select(
                "SELECT t.tag, COUNT(*) AS n FROM contact_tags t JOIN contacts c ON c.id = t.contact_id
                 WHERE $scope GROUP BY t.tag ORDER BY n DESC, t.tag LIMIT 200",
                $sp
            )
        );
    }

    /* ---------------------------------------------------------------- Export */

    /**
     * CSV (UTF-8 avec BOM pour Excel). Le téléphone est exporté en chiffres (sans « + ») : réimportable tel quel
     * et sans risque d'interprétation en formule. Les autres cellules sont protégées contre l'injection de formules.
     * @param array{match:string,conditions:array<int,array<string,mixed>>} $rules
     */
    public static function exportCsv(array $rules): string
    {
        $repo = new ContactRepository();
        [$scope, $sp] = $repo->scope();
        [$fs, $fp] = ContactQuery::compile($rules);
        $global = TenantContext::current()->global;
        $max = (int) Config::get('contacts.export_max_rows');

        $out = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        $head = ['telephone', 'prenom', 'nom', 'email', 'pays', 'operateur', 'statut', 'source', 'etiquettes', 'groupes', 'date_creation'];
        if ($global) {
            array_unshift($head, 'organisation');
        }
        fputcsv($out, $head, ';', '"', '');

        $safe = static function (?string $v): string {
            $v = (string) $v;
            return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
        };

        $lastId = 0;
        $written = 0;
        while ($written < $max) {
            $limit = min(1000, $max - $written);
            $rows = Database::select(
                "SELECT c.id, c.phone_e164, c.first_name, c.last_name, c.email, c.country, c.operator, c.status, c.source, c.created_at, c.organization_id, c.attributes, c.import_id, c.uuid, c.updated_at, o.name AS organization_name
                 FROM contacts c JOIN organizations o ON o.id = c.organization_id
                 WHERE $scope AND ($fs) AND c.id > ? ORDER BY c.id LIMIT $limit",
                array_merge($sp, $fp, [$lastId])
            );
            if (!$rows) {
                break;
            }
            foreach (self::hydrate($rows) as $r) {
                $line = [
                    ltrim((string) $r['phone_e164'], '+'),
                    $safe($r['first_name']), $safe($r['last_name']), $safe($r['email']),
                    (string) $r['country'], (string) $r['operator'], (string) $r['status'], (string) $r['source'],
                    $safe(implode(';', $r['tags'])),
                    $safe(implode(';', array_map(static fn(array $g): string => (string) $g['name'], $r['groups']))),
                    (string) $r['created_at'],
                ];
                if ($global) {
                    array_unshift($line, $safe((string) $r['organization_name']));
                }
                fputcsv($out, $line, ';', '"', '');
                $lastId = (int) $r['id'];
                $written++;
            }
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
