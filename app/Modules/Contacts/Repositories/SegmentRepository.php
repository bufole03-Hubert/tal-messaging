<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Repositories;

use App\Repositories\TenantRepository;

final class SegmentRepository extends TenantRepository
{
    protected string $table = 'segments';
    protected string $alias = 's';
    protected string $select = 's.id, s.uuid, s.organization_id, o.name AS organization_name, s.name, s.description, s.rules, s.created_at, s.updated_at';
    protected array $writable = ['uuid', 'organization_id', 'name', 'description', 'rules', 'created_by'];
    protected array $sortable = ['id' => 's.id', 'name' => 's.name', 'created_at' => 's.created_at'];

    protected function from(): string
    {
        return '`segments` s JOIN `organizations` o ON o.id = s.organization_id';
    }
}
