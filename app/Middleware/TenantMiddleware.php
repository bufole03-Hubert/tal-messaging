<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Tenancy\TenantContext;

/** TenantGuard : fixe le contexte d'isolation pour toute la requête (échec fermé). */
final class TenantMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        TenantContext::initialize($request->user());
        try {
            return $next($request);
        } finally {
            TenantContext::reset();
        }
    }
}
