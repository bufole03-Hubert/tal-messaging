<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Contacts\Services\ContactService;
use App\Modules\Contacts\Services\RetentionService;
use App\Services\AuditService;

/** Dashboard Data & Compliance : indicateurs de qualité des données, imports, règles de conservation. */
final class DataController extends ModuleController
{
    /** Contenu du dashboard « data » (appelé par DashboardController). @return array<string,mixed> */
    public static function dashboardData(Request $request): array
    {
        return [
            'canRetention' => $request->can('manage_data_retention'),
            'retention' => RetentionService::get(),
        ];
    }

    /** @param array<string,string> $params */
    public function stats(Request $request, array $params): Response
    {
        return Response::data(ContactService::stats());
    }

    /** @param array<string,string> $params */
    public function retention(Request $request, array $params): Response
    {
        return Response::data(RetentionService::get());
    }

    /** @param array<string,string> $params */
    public function saveRetention(Request $request, array $params): Response
    {
        $new = RetentionService::save($request->body);
        $request->attributes['audit_resource_type'] = 'app_setting';
        return Response::data($new);
    }

    /** @param array<string,string> $params */
    public function purge(Request $request, array $params): Response
    {
        $res = RetentionService::purge();
        AuditService::log('contacts.purge', 'app_setting', null, $res, (int) $request->user()['id'], null);
        return Response::data($res);
    }
}
