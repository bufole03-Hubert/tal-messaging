<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\FeatureFlagService;

final class FeatureFlagController extends Controller
{
    /** Flags effectifs pour l'organisation de l'utilisateur (utilisable par le frontend). */
    /** @param array<string,string> $params */
    public function effective(Request $request, array $params): Response
    {
        return Response::data(FeatureFlagService::effective($request->user()['organization_id']));
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        return Response::data(FeatureFlagService::all());
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['enabled' => 'required|bool', 'organization_id' => 'nullable|int|min:1']);
        $orgId = (int) ($d['organization_id'] ?? 0);
        if ($orgId > 0 && Database::scalar('SELECT 1 FROM organizations WHERE id = ? AND deleted_at IS NULL', [$orgId]) === null) {
            throw ApiException::notFound('Organisation introuvable.');
        }
        if (!FeatureFlagService::set($params['key'], (bool) $d['enabled'], $orgId, (int) $request->user()['id'])) {
            throw ApiException::notFound('Feature flag inconnu.');
        }
        $request->attributes['audit_resource_type'] = 'feature_flag';
        if ($orgId > 0) {
            $request->attributes['audit_org'] = $orgId;
        }
        return Response::data(['flag_key' => $params['key'], 'organization_id' => $orgId ?: null, 'enabled' => (bool) $d['enabled']]);
    }
}
