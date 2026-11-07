<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Données de référence globales (non tenant) : rôles et permissions. */
final class RoleRepository
{
    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        $roles = Database::select('SELECT id, code, name, scope, level FROM roles ORDER BY scope, level DESC');
        foreach ($roles as &$role) {
            $role['permissions'] = array_column(Database::select(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.code',
                [$role['id']]
            ), 'code');
        }
        return $roles;
    }

    /** @return array<string,mixed>|null */
    public static function byCode(string $code): ?array
    {
        return Database::first('SELECT id, code, name, scope, level FROM roles WHERE code = ?', [$code]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function permissions(): array
    {
        return Database::select('SELECT code, description FROM permissions ORDER BY code');
    }
}
