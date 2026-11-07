<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

/** JwtAuthGuard équivalent : authentifie la session et impose la finalisation 2FA / mot de passe. */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $user = AuthService::currentUser();
        if ($user === null) {
            if ($request->isApi()) {
                throw ApiException::unauthorized();
            }
            return Response::redirect('/login');
        }
        $request->attributes['user'] = $user;

        $route = (string) ($request->attributes['route'] ?? '');
        $setupRequired = AuthService::requiresTwoFactorSetup($user) || $user['must_change_password'];
        if ($setupRequired && !str_starts_with($route, 'security.')) {
            if ($request->isApi()) {
                throw ApiException::forbidden(
                    'Vous devez activer la double authentification et/ou changer votre mot de passe.',
                    'SECURITY_SETUP_REQUIRED'
                );
            }
            return Response::redirect('/security');
        }
        return $next($request);
    }
}
