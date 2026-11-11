<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Repositories;

use App\Repositories\TenantRepository;

final class GroupRepository extends TenantRepository
{
    protected string $table = 'contact_groups';
    protected string $alias = 'g';
    protected string $select = 'g.id, g.uuid, g.organization_id, o.name AS organization_name, g.name, g.description, g.created_at, g.updated_at,
        (SELECT COUNT(*) FROM contact_group_members m JOIN contacts mc ON mc.id = m.contact_id AND mc.deleted_at IS NULL
          WHERE m.group_id = g.id) AS contacts_count';
    protected array $writable = ['uuid', 'organization_id', 'name', 'description', 'created_by'];
    protected array $sortable = ['id' => 'g.id', 'name' => 'g.name', 'created_at' => 'g.created_at', 'contacts_count' => 'contacts_count'];

    protected function from(): string
    {
        return '`contact_groups` g JOIN `organizations` o ON o.id = g.organization_id';
    }
}
