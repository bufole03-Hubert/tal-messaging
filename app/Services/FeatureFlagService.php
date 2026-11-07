<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Feature flags : valeur globale (organization_scope = 0) + surcharge optionnelle par organisation.
 * Usage : FeatureFlagService::isEnabled('campaigns_module', $orgId)
 */
final class FeatureFlagService
{
    public static function isEnabled(string $key, ?int $organizationId = null): bool
    {
        if ($organizationId !== null) {
            $v = Database::scalar('SELECT enabled FROM feature_flags WHERE flag_key = ? AND organization_scope = ?', [$key, $organizationId]);
            if ($v !== null) {
                return (bool) $v;
            }
        }
        return (bool) Database::scalar('SELECT enabled FROM feature_flags WHERE flag_key = ? AND organization_scope = 0', [$key]);
    }

    /** @return array<string,bool> */
    public static function effective(?int $organizationId): array
    {
        $out = [];
        foreach (Database::select('SELECT flag_key, enabled FROM feature_flags WHERE organization_scope = 0') as $r) {
            $out[$r['flag_key']] = (bool) $r['enabled'];
        }
        if ($organizationId !== null) {
            foreach (Database::select('SELECT flag_key, enabled FROM feature_flags WHERE organization_scope = ?', [$organizationId]) as $r) {
                $out[$r['flag_key']] = (bool) $r['enabled'];
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select('SELECT flag_key, organization_scope, enabled, description, updated_by, updated_at FROM feature_flags ORDER BY flag_key, organization_scope');
    }

    public static function set(string $key, bool $enabled, int $organizationScope, int $userId): bool
    {
        if ($organizationScope === 0) {
            $exists = Database::scalar('SELECT 1 FROM feature_flags WHERE flag_key = ? AND organization_scope = 0', [$key]);
            if ($exists === null) {
                return false; // les flags globaux sont créés par le seed/les migrations
            }
        }
        Database::execute(
            'INSERT INTO feature_flags (flag_key, organization_scope, enabled, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by)',
            [$key, $organizationScope, (int) $enabled, $userId]
        );
        return true;
    }
}
