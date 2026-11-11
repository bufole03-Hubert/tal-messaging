<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Modules\Contacts\Repositories\GroupRepository;

final class GroupService
{
    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function create(array $in, int $orgId, int $actorId): array
    {
        $repo = new GroupRepository();
        self::assertNameFree($orgId, (string) $in['name'], null);
        try {
            $id = $repo->insert([
                'uuid' => uuid4(), 'organization_id' => $orgId, 'name' => (string) $in['name'],
                'description' => isset($in['description']) && $in['description'] !== '' ? (string) $in['description'] : null,
                'created_by' => $actorId,
            ]);
        } catch (\PDOException $e) {
            throw $e->getCode() === '23000' ? ApiException::conflict('Un groupe porte déjà ce nom.', 'GROUP_EXISTS') : $e;
        }
        return $repo->find($id) ?? throw new \RuntimeException('Groupe créé introuvable.');
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function update(int $id, array $in): array
    {
        $repo = new GroupRepository();
        $cur = $repo->find($id) ?? throw ApiException::notFound('Groupe introuvable.');
        $upd = [];
        if (isset($in['name']) && $in['name'] !== $cur['name']) {
            self::assertNameFree((int) $cur['organization_id'], (string) $in['name'], $id);
            $upd['name'] = (string) $in['name'];
        }
        if (array_key_exists('description', $in)) {
            $upd['description'] = $in['description'] !== null && $in['description'] !== '' ? (string) $in['description'] : null;
        }
        try {
            $repo->update($id, $upd);
        } catch (\PDOException $e) {
            throw $e->getCode() === '23000' ? ApiException::conflict('Un groupe porte déjà ce nom.', 'GROUP_EXISTS') : $e;
        }
        return $repo->find($id) ?? throw ApiException::notFound('Groupe introuvable.');
    }

    public static function delete(int $id): void
    {
        $repo = new GroupRepository();
        $repo->find($id) ?? throw ApiException::notFound('Groupe introuvable.');
        Database::transaction(function () use ($repo, $id): void {
            Database::execute('DELETE FROM contact_group_members WHERE group_id = ?', [$id]);
            $repo->softDelete($id);
        });
    }

    /** Utilisé par l'import : retrouve ou crée un groupe du même nom. */
    public static function createOrFind(int $orgId, string $name, int $actorId): int
    {
        $id = Database::scalar('SELECT id FROM contact_groups WHERE organization_id = ? AND name = ? AND deleted_at IS NULL', [$orgId, $name]);
        if ($id !== null) {
            return (int) $id;
        }
        return (int) self::create(['name' => $name], $orgId, $actorId)['id'];
    }

    private static function assertNameFree(int $orgId, string $name, ?int $exceptId): void
    {
        $hit = Database::scalar(
            'SELECT id FROM contact_groups WHERE organization_id = ? AND name = ? AND deleted_at IS NULL' . ($exceptId ? ' AND id <> ?' : ''),
            $exceptId ? [$orgId, $name, $exceptId] : [$orgId, $name]
        );
        if ($hit !== null) {
            throw ApiException::conflict('Un groupe porte déjà ce nom.', 'GROUP_EXISTS');
        }
    }
}
