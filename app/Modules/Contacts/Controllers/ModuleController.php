<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Controllers;

use App\Controllers\Controller;
use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrganizationRepository;
use App\Tenancy\TenantContext;

/** Base des contrôleurs Contacts : résolution de l'organisation cible et utilitaires communs. */
abstract class ModuleController extends Controller
{
    /**
     * Organisation sur laquelle une création s'applique.
     * Client : forcée par le contexte tenant (l'entrée est ignorée). Interne global : doit être précisée.
     */
    protected function targetOrg(Request $request): int
    {
        $ctx = TenantContext::current();
        if (!$ctx->global) {
            return (int) $ctx->organizationId;
        }
        $raw = $request->body['organization_id'] ?? $request->query('organization_id');
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw ApiException::validation(['organization_id' => ['Choisissez l’organisation concernée.']]);
        }
        (new OrganizationRepository())->find($id) ?? throw ApiException::validation(['organization_id' => ['Organisation introuvable.']]);
        return $id;
    }

    /** @return array{0:int,1:int} page, per_page */
    protected function paging(Request $request, int $defaultPer = 25): array
    {
        return [max(1, (int) $request->query('page', 1)), min(100, max(1, (int) $request->query('per_page', $defaultPer)))];
    }

    /** @param array<string,mixed> $data */
    protected function render(Request $request, string $template, array $data): Response
    {
        return $this->view($template, $data + [
            'dashboards' => $this->dashboards($request),
            'user' => $request->user(),
            'global' => TenantContext::current()->global,
            'scripts' => ['js/ui.js', 'js/' . ($data['section'] ?? 'contacts') . '.js'],
        ]);
    }

    protected function csv(string $body, string $filename): Response
    {
        return new Response(200, $body, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"',
        ]);
    }
}
