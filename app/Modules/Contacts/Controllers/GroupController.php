<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Modules\Contacts\Repositories\GroupRepository;
use App\Modules\Contacts\Services\GroupService;
use App\Tenancy\TenantContext;

final class GroupController extends ModuleController
{
    private const INPUT = ['name' => 'required|string|min:1|max:100', 'description' => 'string|max:255'];

    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'contacts/groups', ['title' => 'Groupes', 'section' => 'groups', 'canManage' => $request->can('manage_contacts')]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 50);
        $where = '1 = 1';
        $p = [];
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $where .= ' AND g.name LIKE ?';
            $p[] = '%' . addcslashes(trim($q), '%_\\') . '%';
        }
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND g.organization_id = ?';
            $p[] = $o;
        }
        return Response::json((new GroupRepository())->paginate($where, $p, $page, $per, (string) $request->query('sort', 'name'), (string) $request->query('dir', 'asc')));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        return Response::data((new GroupRepository())->find((int) $params['id']) ?? throw ApiException::notFound('Groupe introuvable.'));
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, self::INPUT);
        $orgId = $this->targetOrg($request);
        $g = GroupService::create($d, $orgId, (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'contact_group';
        $request->attributes['audit_resource_id'] = $g['id'];
        $request->attributes['audit_org'] = $orgId;
        return Response::data($g, 201);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $rules = self::INPUT;
        $rules['name'] = 'string|min:1|max:100';
        $g = GroupService::update((int) $params['id'], Validator::validate($request->body, $rules));
        $request->attributes['audit_resource_type'] = 'contact_group';
        $request->attributes['audit_org'] = $g['organization_id'];
        return Response::data($g);
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        GroupService::delete((int) $params['id']);
        $request->attributes['audit_resource_type'] = 'contact_group';
        return Response::data(['deleted' => true]);
    }
}
