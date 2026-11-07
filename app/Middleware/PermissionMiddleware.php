<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;

/** Exige TOUTES les permissions listées (alias `perm:a,b`). */
final class PermissionMiddleware implements MiddlewareInterface
{
    /** @param array<int,string> $required */
    public function __construct(private readonly array $required)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->user();
        foreach ($this->required as $perm) {
            if (!in_array($perm, $user['permissions'], true)) {
                throw ApiException::forbidden('Permission requise : ' . $perm, 'PERMISSION_DENIED');
            }
        }
        return $next($request);
    }
}
