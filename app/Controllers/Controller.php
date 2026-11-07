<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], string $layout = 'layouts/app'): Response
    {
        return Response::html(View::render($template, $data, $layout));
    }

    /** Espaces (dashboards) visibles pour l'utilisateur courant : alimente la sidebar. @return array<string,array<string,mixed>> */
    protected function dashboards(Request $request): array
    {
        $out = [];
        foreach ((array) Config::get('dashboards') as $slug => $d) {
            if ($request->can('access_dashboard_' . $slug)) {
                $out[$slug] = $d;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    protected function publicUser(array $user): array
    {
        return [
            'id' => $user['id'],
            'uuid' => $user['uuid'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => ['code' => $user['role_code'], 'name' => $user['role_name'], 'scope' => $user['role_scope']],
            'organization' => $user['organization_id'] !== null ? ['id' => $user['organization_id'], 'name' => $user['org_name']] : null,
            'permissions' => $user['permissions'],
            'two_factor_enabled' => $user['totp_enabled'],
            'must_change_password' => $user['must_change_password'],
            'security_setup_required' => \App\Services\AuthService::requiresTwoFactorSetup($user) || $user['must_change_password'],
        ];
    }
}
