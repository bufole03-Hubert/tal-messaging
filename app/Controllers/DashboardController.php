<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class DashboardController extends Controller
{
    private const HOME = [
        'ceo' => 'ceo', 'cto' => 'cto', 'operations_manager' => 'operations', 'sales_cs' => 'sales',
        'data_compliance' => 'data', 'support_agent' => 'support',
    ];

    /** @param array<string,string> $params */
    public function home(Request $request, array $params): Response
    {
        $user = $request->user();
        $slug = self::HOME[$user['role_code']] ?? 'client';
        if (!$request->can('access_dashboard_' . $slug)) {
            throw ApiException::forbidden();
        }
        return Response::redirect('/' . $slug);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $slug = $params['slug'];
        if (!$request->can('access_dashboard_' . $slug)) {
            throw ApiException::forbidden('Vous n’avez pas accès à ce dashboard.', 'PERMISSION_DENIED');
        }
        $all = (array) Config::get('dashboards');
        $visible = [];
        foreach ($all as $s => $d) {
            if ($request->can('access_dashboard_' . $s)) {
                $visible[$s] = $d;
            }
        }
        return $this->view('dashboards/placeholder', [
            'title' => $all[$slug]['label'],
            'slug' => $slug,
            'dashboard' => $all[$slug],
            'dashboards' => $visible,
            'user' => $request->user(),
        ]);
    }
}
