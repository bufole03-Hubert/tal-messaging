<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $sent = $request->header('x-csrf-token') ?? (is_string($request->body['_csrf'] ?? null) ? $request->body['_csrf'] : '');
            if (!hash_equals(Session::csrfToken(), (string) $sent)) {
                throw ApiException::forbidden('Jeton CSRF invalide ou expiré. Rechargez la page.', 'CSRF_INVALID');
            }
        }
        return $next($request);
    }
}
