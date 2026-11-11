<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Controllers;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Contacts\Repositories\ImportRepository;
use App\Modules\Contacts\Services\ImportService;
use App\Tenancy\TenantContext;

final class ImportController extends ModuleController
{
    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'contacts/imports', [
            'title' => 'Imports',
            'section' => 'imports',
            'canManage' => $request->can('manage_contacts'),
            'countries' => (array) Config::get('countries.countries'),
            'defaultCountry' => (string) Config::get('contacts.default_country'),
            'maxMb' => intdiv((int) Config::get('contacts.import.max_bytes'), 1048576),
            'maxRows' => (int) Config::get('contacts.import.max_rows'),
        ]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 20);
        $where = '1 = 1';
        $p = [];
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND i.organization_id = ?';
            $p[] = $o;
        }
        $res = (new ImportRepository())->paginate($where, $p, $page, $per, 'created_at', 'desc');
        $res['data'] = array_map([ImportRepository::class, 'present'], $res['data']);
        return Response::json($res);
    }

    /** @param array<string,string> $params */
    public function upload(Request $request, array $params): Response
    {
        $orgId = $this->targetOrg($request);
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            throw ApiException::validation(['file' => ['Aucun fichier reçu.']]);
        }
        $res = ImportService::upload($file, $orgId, (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'contact_import';
        $request->attributes['audit_resource_id'] = $res['id'];
        $request->attributes['audit_org'] = $orgId;
        $request->attributes['audit_no_input'] = true;
        return Response::data($res, 201);
    }

    /** @param array<string,string> $params */
    public function start(Request $request, array $params): Response
    {
        $b = $request->body;
        $res = ImportService::start(
            (int) $params['id'],
            is_array($b['mapping'] ?? null) ? $b['mapping'] : [],
            is_array($b['options'] ?? null) ? $b['options'] : [],
            (int) $request->user()['id']
        );
        $request->attributes['audit_resource_type'] = 'contact_import';
        $request->attributes['audit_org'] = $res['organization_id'];
        $request->attributes['audit_no_input'] = true;
        return Response::data($res);
    }

    /** Traite un lot ; le client rappelle jusqu'à status = completed. @param array<string,string> $params */
    public function process(Request $request, array $params): Response
    {
        $res = ImportService::process((int) $params['id'], (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'contact_import';
        $request->attributes['audit_org'] = $res['organization_id'];
        $request->attributes['audit_no_input'] = true;
        return Response::data($res);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        return Response::data(ImportService::progress((int) $params['id']));
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $res = ImportService::cancel((int) $params['id'], (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'contact_import';
        $request->attributes['audit_org'] = $res['organization_id'];
        return Response::data($res);
    }

    /** @param array<string,string> $params */
    public function errors(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 50);
        $kind = is_string($k = $request->query('kind')) ? $k : null;
        return Response::json(ImportService::errors((int) $params['id'], $kind, $page, $per));
    }

    /** @param array<string,string> $params */
    public function errorsCsv(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        return $this->csv(ImportService::errorsCsv($id), 'import-' . $id . '-erreurs.csv');
    }
}
