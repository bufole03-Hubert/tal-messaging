<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Controllers;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Modules\Contacts\Repositories\ContactRepository;
use App\Modules\Contacts\Services\ContactService;
use App\Modules\Contacts\Services\SegmentService;
use App\Modules\Contacts\Support\ContactQuery;
use App\Services\AuditService;
use App\Tenancy\TenantContext;

final class ContactController extends ModuleController
{
    private const INPUT = [
        'phone' => 'required|string|max:40',
        'country' => 'string|size:2',
        'first_name' => 'string|max:80',
        'last_name' => 'string|max:80',
        'email' => 'string|email|max:190',
        'status' => 'in:active,blocked',
        'tags' => 'array|max:20',
        'attributes' => 'array',
        'group_ids' => 'array|max:50',
    ];

    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'contacts/index', [
            'title' => 'Contacts',
            'section' => 'contacts',
            'countries' => (array) Config::get('countries.countries'),
            'operators' => self::operators(),
            'canManage' => $request->can('manage_contacts'),
            'canExport' => $request->can('export_contacts'),
        ]);
    }

    /** @return array<int,string> */
    private static function operators(): array
    {
        $ops = [];
        foreach ((array) Config::get('countries.countries') as $c) {
            foreach (array_keys((array) ($c['operators'] ?? [])) as $o) {
                $ops[$o] = true;
            }
        }
        return array_keys($ops);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request);
        $rules = ContactQuery::fromQuery($request->query);
        [$sql, $p] = ContactQuery::compile($rules);
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $sql = "($sql) AND c.organization_id = ?";
            $p[] = $o;
        }
        $res = (new ContactRepository())->paginate($sql, $p, $page, $per, (string) $request->query('sort', 'created_at'), (string) $request->query('dir', 'desc'));
        $res['data'] = ContactService::hydrate($res['data']);
        return Response::json($res);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $c = ContactService::one((int) $params['id']);
        $request->attributes['audit_org'] = $c['organization_id'];
        return Response::data($c);
    }

    /** @param array<string,string> $params */
    public function history(Request $request, array $params): Response
    {
        return Response::data(ContactService::history((int) $params['id']));
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, self::INPUT);
        $orgId = $this->targetOrg($request);
        $c = ContactService::create($d, (int) $request->user()['id'], $orgId);
        $request->attributes['audit_resource_type'] = 'contact';
        $request->attributes['audit_resource_id'] = $c['id'];
        $request->attributes['audit_org'] = $orgId;
        $request->attributes['audit_no_input'] = true; // données personnelles : pas recopiées dans l'audit
        return Response::data($c, 201);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $rules = self::INPUT;
        $rules['phone'] = 'string|max:40';
        $d = Validator::validate($request->body, $rules);
        $c = ContactService::update((int) $params['id'], $d, (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'contact';
        $request->attributes['audit_org'] = $c['organization_id'];
        $request->attributes['audit_no_input'] = true;
        return Response::data($c);
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        ContactService::delete((int) $params['id'], (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'contact';
        return Response::data(['deleted' => true]);
    }

    /** @param array<string,string> $params */
    public function bulk(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, [
            'action' => 'required|in:delete,block,unblock,group_add,group_remove,tag_add,tag_remove',
            'ids' => 'array',
            'rules' => 'array',
            'group_id' => 'int|min:1',
            'tag' => 'string|max:50',
        ]);
        if (!isset($d['ids']) && !isset($d['rules'])) {
            throw ApiException::validation(['ids' => ['Sélectionnez des contacts ou un filtre.']]);
        }
        $selection = isset($d['ids']) ? ['ids' => $d['ids']] : ['rules' => $d['rules']];
        $res = ContactService::bulk($d['action'], $selection, ['group_id' => $d['group_id'] ?? null, 'tag' => $d['tag'] ?? null], (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'contact';
        $request->attributes['audit_resource_id'] = null;
        $request->attributes['audit_no_input'] = true;
        AuditService::log('contacts.bulk_' . $d['action'], 'contact', null, ['affected' => $res['affected'], 'by' => isset($d['ids']) ? 'ids' : 'filter'], (int) $request->user()['id'], TenantContext::current()->organizationId);
        return Response::data($res);
    }

    /** @param array<string,string> $params */
    public function count(Request $request, array $params): Response
    {
        $rules = ContactQuery::normalizeRules($request->body['rules'] ?? null);
        $orgId = isset($request->body['organization_id']) ? (int) $request->body['organization_id'] : null;
        if (!TenantContext::current()->global) {
            $orgId = null; // le périmètre tenant s'applique déjà
        }
        return Response::data([
            'count' => SegmentService::count($rules, $orgId),
            'sample' => SegmentService::sample($rules, $orgId, 5),
        ]);
    }

    /** @param array<string,string> $params */
    public function duplicates(Request $request, array $params): Response
    {
        $by = (string) $request->query('by', 'name');
        if (!in_array($by, ['name', 'email', 'phone_tail'], true)) {
            throw ApiException::validation(['by' => ['Critère inconnu (name, email, phone_tail).']]);
        }
        return Response::data(ContactService::duplicates($by));
    }

    /** @param array<string,string> $params */
    public function merge(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['keep_id' => 'required|int|min:1', 'merge_ids' => 'required|array|min:1|max:20']);
        $c = ContactService::merge((int) $d['keep_id'], array_map('intval', $d['merge_ids']), (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'contact';
        $request->attributes['audit_resource_id'] = $c['id'];
        $request->attributes['audit_org'] = $c['organization_id'];
        return Response::data($c);
    }

    /** @param array<string,string> $params */
    public function stats(Request $request, array $params): Response
    {
        return Response::data(ContactService::stats());
    }

    /** @param array<string,string> $params */
    public function tags(Request $request, array $params): Response
    {
        return Response::data(ContactService::tagCatalog());
    }

    /** @param array<string,string> $params */
    public function export(Request $request, array $params): Response
    {
        $rules = ContactQuery::fromQuery($request->query);
        $csv = ContactService::exportCsv($rules);
        $lines = max(0, substr_count($csv, "\n") - 1);
        AuditService::log('contacts.export', 'contact', null, ['rows' => $lines, 'filters' => $rules['conditions']], (int) $request->user()['id'], TenantContext::current()->organizationId);
        return $this->csv($csv, 'contacts-' . gmdate('Ymd-His') . '.csv');
    }
}
