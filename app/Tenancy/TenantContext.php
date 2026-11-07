<?php
declare(strict_types=1);

namespace App\Tenancy;

use App\Core\ApiException;

/**
 * Contexte d'isolation multi-tenant de la requête courante.
 *  - Utilisateur client       : organizationId fixé, global = false  → filtre forcé.
 *  - Utilisateur interne      : global = true uniquement s'il possède `view_all_tenants`.
 * Si le contexte n'est pas initialisé, tout accès aux repositories tenant échoue (fail-closed).
 */
final class TenantContext
{
    private static ?self $current = null;

    private function __construct(
        public readonly ?int $organizationId,
        public readonly bool $global,
    ) {
    }

    /** @param array<string,mixed> $user */
    public static function initialize(array $user): void
    {
        $orgId = $user['organization_id'] !== null ? (int) $user['organization_id'] : null;
        $canSeeAll = $orgId === null && in_array('view_all_tenants', $user['permissions'], true);

        if ($orgId === null && !$canSeeAll) {
            throw ApiException::forbidden('Aucun périmètre organisationnel pour ce compte.', 'NO_TENANT_SCOPE');
        }
        self::$current = new self($orgId, $canSeeAll);
    }

    public static function forOrganization(int $organizationId): void
    {
        self::$current = new self($organizationId, false);
    }

    public static function forGlobal(): void
    {
        self::$current = new self(null, true);
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    public static function current(): self
    {
        if (self::$current === null) {
            throw new \LogicException('TenantContext non initialisé : accès refusé (fail-closed).');
        }
        return self::$current;
    }
}
