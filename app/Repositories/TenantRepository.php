<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\ApiException;
use App\Core\Database;
use App\Tenancy\TenantContext;

/**
 * Base de TOUS les repositories métier : le filtre organization_id est appliqué ici,
 * jamais dans les contrôleurs. Les colonnes écrivables et triables sont des listes blanches.
 * Un accès hors périmètre se comporte comme « introuvable » (404).
 */
abstract class TenantRepository
{
    protected string $table = '';
    protected string $alias = 't';
    protected string $tenantColumn = 'organization_id';
    protected string $select = 't.*';
    protected bool $softDelete = true;
    /** @var array<int,string> */
    protected array $writable = [];
    /** @var array<string,string> clé publique => colonne SQL */
    protected array $sortable = ['id' => 't.id'];

    protected function from(): string
    {
        return "`{$this->table}` {$this->alias}";
    }

    /** @return array{0:string,1:array<int,mixed>} */
    public function tenantClause(bool $aliased = true): array
    {
        $ctx = TenantContext::current();
        if ($ctx->global) {
            return ['1 = 1', []];
        }
        $col = ($aliased ? "{$this->alias}." : '') . "`{$this->tenantColumn}`";
        return ["$col = ?", [$ctx->organizationId]];
    }

    /** @return array{0:string,1:array<int,mixed>} */
    protected function baseWhere(bool $aliased = true): array
    {
        [$sql, $params] = $this->tenantClause($aliased);
        if ($this->softDelete) {
            $sql .= ' AND ' . ($aliased ? "{$this->alias}." : '') . 'deleted_at IS NULL';
        }
        return [$sql, $params];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        [$w, $p] = $this->baseWhere();
        return Database::first(
            "SELECT {$this->select} FROM {$this->from()} WHERE {$this->alias}.id = ? AND $w LIMIT 1",
            array_merge([$id], $p)
        );
    }

    /**
     * @param string $extraWhere fragment SQL CONSTANT (jamais construit depuis l'entrée utilisateur)
     * @param array<int,mixed> $params
     * @return array{data:array<int,array<string,mixed>>,meta:array<string,int>}
     */
    public function paginate(string $extraWhere, array $params, int $page, int $perPage, string $sort, string $dir): array
    {
        [$w, $p] = $this->baseWhere();
        $col = $this->sortable[$sort] ?? $this->sortable['id'];
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $where = "$w AND ($extraWhere)";
        $all = array_merge($p, $params);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM {$this->from()} WHERE $where", $all);
        $offset = ($page - 1) * $perPage;
        $rows = Database::select(
            "SELECT {$this->select} FROM {$this->from()} WHERE $where ORDER BY $col $direction LIMIT $perPage OFFSET $offset",
            $all
        );
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    /** @param array<string,mixed> $data */
    public function insert(array $data): int
    {
        $ctx = TenantContext::current();
        if ($this->tenantColumn === 'id') {
            if (!$ctx->global) {
                throw ApiException::forbidden();
            }
        } elseif (!$ctx->global) {
            $data[$this->tenantColumn] = $ctx->organizationId; // forcé : jamais pris de l'entrée
        }
        $cols = array_values(array_intersect(array_keys($data), array_merge($this->writable, [$this->tenantColumn])));
        if ($this->tenantColumn === 'id') {
            $cols = array_values(array_diff($cols, ['id']));
        }
        $names = implode(',', array_map(static fn(string $c): string => "`$c`", $cols));
        $marks = implode(',', array_fill(0, count($cols), '?'));
        Database::execute("INSERT INTO `{$this->table}` ($names) VALUES ($marks)", array_map(static fn(string $c): mixed => $data[$c], $cols));
        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        unset($data[$this->tenantColumn], $data['id']); // le rattachement ne peut jamais être modifié
        $cols = array_values(array_intersect(array_keys($data), $this->writable));
        if (!$cols) {
            return;
        }
        [$w, $p] = $this->baseWhere(false);
        $set = implode(',', array_map(static fn(string $c): string => "`$c` = ?", $cols));
        Database::execute(
            "UPDATE `{$this->table}` SET $set WHERE id = ? AND $w",
            array_merge(array_map(static fn(string $c): mixed => $data[$c], $cols), [$id], $p)
        );
    }

    public function softDelete(int $id): void
    {
        [$w, $p] = $this->baseWhere(false);
        Database::execute("UPDATE `{$this->table}` SET deleted_at = UTC_TIMESTAMP() WHERE id = ? AND $w", array_merge([$id], $p));
    }
}
