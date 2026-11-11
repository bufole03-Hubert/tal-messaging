<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Repositories;

use App\Repositories\TenantRepository;

final class ContactRepository extends TenantRepository
{
    protected string $table = 'contacts';
    protected string $alias = 'c';
    protected string $select = 'c.id, c.uuid, c.organization_id, o.name AS organization_name, c.phone_e164, c.first_name, c.last_name,
        c.email, c.country, c.operator, c.status, c.source, c.import_id, c.attributes, c.created_at, c.updated_at';
    protected array $writable = ['uuid', 'organization_id', 'phone_e164', 'first_name', 'last_name', 'email', 'country', 'operator',
        'status', 'source', 'import_id', 'attributes', 'created_by'];
    protected array $sortable = [
        'id' => 'c.id', 'created_at' => 'c.created_at', 'updated_at' => 'c.updated_at', 'phone' => 'c.phone_e164',
        'name' => 'c.last_name', 'email' => 'c.email', 'country' => 'c.country', 'operator' => 'c.operator',
    ];

    protected function from(): string
    {
        return '`contacts` c JOIN `organizations` o ON o.id = c.organization_id';
    }
}
