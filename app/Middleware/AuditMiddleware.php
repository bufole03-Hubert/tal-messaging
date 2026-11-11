<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;

/**
 * Journalise toute action modifiante (POST/PUT/DELETE) et tout refus 403/404 sur une ressource.
 * Les routes `auth.*` sont journalisées explicitement par AuthService.
 */
final class AuditMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $status = 200;
        try {
            $response = $next($request);
            $status = $response->status;
            return $response;
        } catch (ApiException $e) {
            $status = $e->status;
            throw $e;
        } catch (\Throwable $e) {
            $status = 500;
            throw $e;
        } finally {
            $this->record($request, $status);
        }
    }

    private function record(Request $request, int $status): void
    {
        $route = (string) ($request->attributes['route'] ?? '');
        $user = $request->attributes['user'] ?? null;
        if ($route === '' || str_starts_with($route, 'auth.')) {
            return;
        }
        $mutating = !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true);
        $denied = in_array($status, [403, 404], true) && isset($request->params['id']);
        if (!$mutating && !$denied) {
            return;
        }
        try {
            AuditService::log(
                $denied && !$mutating ? $route . '.denied' : $route,
                $request->attributes['audit_resource_type'] ?? null,
                $request->attributes['audit_resource_id'] ?? ($request->params['id'] ?? $request->params['key'] ?? null),
                ['status' => $status, 'params' => $request->params, 'input' => ($mutating && empty($request->attributes['audit_no_input'])) ? $request->body : []],
                is_array($user) ? (int) $user['id'] : null,
                $request->attributes['audit_org'] ?? (is_array($user) ? $user['organization_id'] : null),
            );
        } catch (\Throwable) {
            // L'audit ne doit jamais casser la requête ; l'échec est déjà journalisé dans AuditService.
        }
    }
}
