<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Modules\Contacts\Repositories\ContactRepository;
use App\Modules\Contacts\Repositories\SegmentRepository;
use App\Modules\Contacts\Support\ContactQuery;

final class SegmentService
{
    /**
     * Nombre de contacts correspondant aux règles, dans le périmètre courant.
     * @param array{match:string,conditions:array<int,array<string,mixed>>} $rules
     */
    public static function count(array $rules, ?int $orgId = null): int
    {
        [$scope, $sp] = (new ContactRepository())->scope();
        [$fs, $fp] = ContactQuery::compile($rules);
        $sql = "SELECT COUNT(*) FROM contacts c WHERE $scope AND ($fs)";
        $params = array_merge($sp, $fp);
        if ($orgId !== null) {
            $sql .= ' AND c.organization_id = ?';
            $params[] = $orgId;
        }
        return (int) Database::scalar($sql, $params);
    }

    /**
     * @param array{match:string,conditions:array<int,array<string,mixed>>} $rules
     * @return array<int,array<string,mixed>>
     */
    public static function sample(array $rules, ?int $orgId = null, int $limit = 5): array
    {
        $repo = new ContactRepository();
        [$fs, $fp] = ContactQuery::compile($rules);
        $extra = $orgId !== null ? "($fs) AND c.organization_id = ?" : $fs;
        $res = $repo->paginate($extra, $orgId !== null ? array_merge($fp, [$orgId]) : $fp, 1, $limit, 'created_at', 'desc');
        return ContactService::hydrate($res['data']);
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function create(array $in, int $orgId, int $actorId): array
    {
        $repo = new SegmentRepository();
        $rules = ContactQuery::normalizeRules($in['rules'] ?? null);
        self::assertNameFree($orgId, (string) $in['name'], null);
        try {
            $id = $repo->insert([
                'uuid' => uuid4(), 'organization_id' => $orgId, 'name' => (string) $in['name'],
                'description' => ($in['description'] ?? '') !== '' ? (string) $in['description'] : null,
                'rules' => json_encode($rules, JSON_UNESCAPED_UNICODE), 'created_by' => $actorId,
            ]);
        } catch (\PDOException $e) {
            throw $e->getCode() === '23000' ? ApiException::conflict('Un segment porte déjà ce nom.', 'SEGMENT_EXISTS') : $e;
        }
        return self::present($repo->find($id) ?? throw new \RuntimeException('Segment créé introuvable.'));
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function update(int $id, array $in): array
    {
        $repo = new SegmentRepository();
        $cur = $repo->find($id) ?? throw ApiException::notFound('Segment introuvable.');
        $upd = [];
        if (isset($in['name']) && $in['name'] !== $cur['name']) {
            self::assertNameFree((int) $cur['organization_id'], (string) $in['name'], $id);
            $upd['name'] = (string) $in['name'];
        }
        if (array_key_exists('description', $in)) {
            $upd['description'] = ($in['description'] ?? '') !== '' ? (string) $in['description'] : null;
        }
        if (array_key_exists('rules', $in)) {
            $upd['rules'] = json_encode(ContactQuery::normalizeRules($in['rules']), JSON_UNESCAPED_UNICODE);
        }
        try {
            $repo->update($id, $upd);
        } catch (\PDOException $e) {
            throw $e->getCode() === '23000' ? ApiException::conflict('Un segment porte déjà ce nom.', 'SEGMENT_EXISTS') : $e;
        }
        return self::present($repo->find($id) ?? throw ApiException::notFound('Segment introuvable.'));
    }

    public static function delete(int $id): void
    {
        $repo = new SegmentRepository();
        $repo->find($id) ?? throw ApiException::notFound('Segment introuvable.');
        $repo->softDelete($id);
    }

    /**
     * Décode les règles et ajoute le nombre de contacts correspondants (dans l'organisation du segment).
     * @param array<string,mixed> $row @return array<string,mixed>
     */
    public static function present(array $row): array
    {
        $rules = is_string($row['rules']) ? (json_decode($row['rules'], true) ?: ['match' => 'all', 'conditions' => []]) : $row['rules'];
        $row['rules'] = $rules;
        try {
            $row['contacts_count'] = self::count(ContactQuery::normalizeRules($rules), (int) $row['organization_id']);
        } catch (ApiException) {
            $row['contacts_count'] = null; // règles devenues invalides (ex. groupe supprimé) : le segment reste modifiable
        }
        return $row;
    }

    private static function assertNameFree(int $orgId, string $name, ?int $exceptId): void
    {
        $hit = Database::scalar(
            'SELECT id FROM segments WHERE organization_id = ? AND name = ? AND deleted_at IS NULL' . ($exceptId ? ' AND id <> ?' : ''),
            $exceptId ? [$orgId, $name, $exceptId] : [$orgId, $name]
        );
        if ($hit !== null) {
            throw ApiException::conflict('Un segment porte déjà ce nom.', 'SEGMENT_EXISTS');
        }
    }
}
