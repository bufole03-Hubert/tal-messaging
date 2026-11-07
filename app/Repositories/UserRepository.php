<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository extends TenantRepository
{
    protected string $table = 'users';
    protected string $alias = 'u';
    protected string $select = 'u.id, u.uuid, u.organization_id, u.email, u.first_name, u.last_name, u.status, u.totp_enabled,
        u.must_change_password, u.last_login_at, u.created_at, u.role_id, r.code AS role, r.name AS role_name, r.scope AS role_scope, r.level AS role_level';
    protected array $writable = ['uuid', 'organization_id', 'role_id', 'email', 'password_hash', 'first_name', 'last_name', 'status', 'must_change_password'];
    protected array $sortable = ['id' => 'u.id', 'email' => 'u.email', 'last_name' => 'u.last_name', 'created_at' => 'u.created_at'];

    protected function from(): string
    {
        return '`users` u JOIN `roles` r ON r.id = u.role_id';
    }

    public function activeOwnerCount(int $organizationId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.organization_id = ? AND r.code = 'owner' AND u.status = 'active' AND u.deleted_at IS NULL",
            [$organizationId]
        );
    }
}
