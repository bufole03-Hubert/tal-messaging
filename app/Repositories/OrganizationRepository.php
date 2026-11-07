<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Support\Str;

final class OrganizationRepository extends TenantRepository
{
    protected string $table = 'organizations';
    protected string $tenantColumn = 'id'; // une organisation « appartient » à elle-même
    protected array $writable = ['uuid', 'name', 'slug', 'email', 'phone', 'country', 'status'];
    protected array $sortable = ['id' => 't.id', 'name' => 't.name', 'created_at' => 't.created_at', 'status' => 't.status'];

    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;
        while (Database::scalar('SELECT 1 FROM organizations WHERE slug = ?', [$slug]) !== null) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
