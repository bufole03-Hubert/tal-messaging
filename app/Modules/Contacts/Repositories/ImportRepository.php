<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Repositories;

use App\Repositories\TenantRepository;

final class ImportRepository extends TenantRepository
{
    protected string $table = 'contact_imports';
    protected string $alias = 'i';
    protected bool $softDelete = false;
    protected string $select = 'i.id, i.uuid, i.organization_id, o.name AS organization_name, i.created_by, i.filename, i.file_path, i.delimiter,
        i.encoding, i.columns_json, i.mapping, i.options, i.status, i.total_rows, i.processed_rows, i.created_rows, i.updated_rows,
        i.duplicate_rows, i.invalid_rows, i.byte_offset, i.started_at, i.completed_at, i.created_at,
        u.first_name AS creator_first_name, u.last_name AS creator_last_name';
    protected array $writable = ['uuid', 'organization_id', 'created_by', 'filename', 'file_path', 'delimiter', 'encoding', 'columns_json',
        'mapping', 'options', 'status', 'total_rows', 'processed_rows', 'created_rows', 'updated_rows', 'duplicate_rows', 'invalid_rows',
        'byte_offset', 'started_at', 'completed_at'];
    protected array $sortable = ['id' => 'i.id', 'created_at' => 'i.created_at', 'status' => 'i.status', 'filename' => 'i.filename'];

    protected function from(): string
    {
        return '`contact_imports` i JOIN `organizations` o ON o.id = i.organization_id LEFT JOIN `users` u ON u.id = i.created_by';
    }

    /**
     * Version publique : sans chemin de fichier ni détails internes, JSON décodés.
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function present(array $row): array
    {
        unset($row['file_path'], $row['byte_offset'], $row['delimiter'], $row['encoding']);
        foreach (['columns_json' => 'columns', 'mapping' => 'mapping', 'options' => 'options'] as $from => $to) {
            $decoded = isset($row[$from]) && is_string($row[$from]) ? json_decode($row[$from], true) : null;
            unset($row[$from]);
            $row[$to] = $decoded;
        }
        $first = trim((string) ($row['creator_first_name'] ?? '') . ' ' . (string) ($row['creator_last_name'] ?? ''));
        unset($row['creator_first_name'], $row['creator_last_name']);
        $row['created_by_name'] = $first !== '' ? $first : null;
        return $row;
    }
}
