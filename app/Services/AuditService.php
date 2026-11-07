<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Support\Logger;

/** Écriture append-only dans audit_logs. Les secrets sont masqués avant stockage. */
final class AuditService
{
    /** @param array<string,mixed> $metadata */
    public static function log(
        string $action,
        ?string $resourceType = null,
        string|int|null $resourceId = null,
        array $metadata = [],
        ?int $userId = null,
        string|int|null $organizationId = null,
    ): void {
        try {
            $req = Request::current();
            $json = json_encode(Logger::scrub($metadata), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            Database::execute(
                'INSERT INTO audit_logs (user_id, organization_id, action, resource_type, resource_id, ip, user_agent, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $organizationId !== null ? (int) $organizationId : null,
                    substr($action, 0, 100),
                    $resourceType,
                    $resourceId !== null ? substr((string) $resourceId, 0, 64) : null,
                    $req->ip,
                    $req->userAgent,
                    $json === false ? null : substr($json, 0, 60000),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('Échec écriture audit : ' . $e->getMessage(), ['action' => $action]);
        }
    }
}
