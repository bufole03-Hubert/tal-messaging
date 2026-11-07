<?php
declare(strict_types=1);

namespace App\Repositories;

/** Lecture seule, scopée par organisation : un client ne voit que le journal de sa propre organisation. */
final class AuditLogRepository extends TenantRepository
{
    protected string $table = 'audit_logs';
    protected bool $softDelete = false;
    protected array $writable = [];
    protected array $sortable = ['id' => 't.id', 'created_at' => 't.created_at', 'action' => 't.action'];
}
