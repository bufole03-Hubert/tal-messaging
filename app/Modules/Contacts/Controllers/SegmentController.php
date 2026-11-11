<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Modules\Contacts\Repositories\SegmentRepository;
use App\Modules\Contacts\Services\SegmentService;
use App\Tenancy\TenantContext;

final class SegmentController extends ModuleController
{
    private const INPUT = ['name' => 'required|string|min:1|max:100', 'description' => 'string|max:255', 'rules' => 'required|array'];

    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'contacts/segments', ['title' => 'Segments', 'section' => 'segments', 'countries' => (array) \App\Core\Config::get('countries.countries'), 'canManage' => $request->can('manage_contacts')]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 50);
        $where = '1 = 1';
        $p = [];
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND s.organization_id = ?';
            $p[] = $o;
        }
        $res = (new SegmentRepository())->paginate($where, $p, $page, $per, (string) $request->query('sort', 'name'), (string) $request->query('dir', 'asc'));
        $res['data'] = array_map([SegmentService::class, 'present'], $res['data']);
        return Response::json($res);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $s = (new SegmentRepository())->find((int) $params['id']) ?? throw ApiException::notFound('Segment introuvable.');
        return Response::data(SegmentService::present($s));
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, self::INPUT);
        $orgId = $this->targetOrg($request);
        $s = SegmentService::create($d, $orgId, (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'segment';
        $request->attributes['audit_resource_id'] = $s['id'];
        $request->attributes['audit_org'] = $orgId;
        return Response::data($s, 201);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $rules = self::INPUT;
        $rules['name'] = 'string|min:1|max:100';
        $rules['rules'] = 'array';
        $s = SegmentService::update((int) $params['id'], Validator::validate($request->body, $rules));
        $request->attributes['audit_resource_type'] = 'segment';
        $request->attributes['audit_org'] = $s['organization_id'];
        return Response::data($s);
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        SegmentService::delete((int) $params['id']);
        $request->attributes['audit_resource_type'] = 'segment';
        return Response::data(['deleted' => true]);
    }
}
