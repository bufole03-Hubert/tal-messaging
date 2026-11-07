<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Support\Str;
use App\Tenancy\TenantContext;

final class OrganizationController extends Controller
{
    private const STATUSES = 'pending,active,suspended,closed';

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $where = '1 = 1';
        $p = [];
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $like = '%' . addcslashes(trim($q), '%_\\') . '%';
            $where .= ' AND (t.name LIKE ? OR t.email LIKE ?)';
            array_push($p, $like, $like);
        }
        if (is_string($s = $request->query('status')) && in_array($s, explode(',', self::STATUSES), true)) {
            $where .= ' AND t.status = ?';
            $p[] = $s;
        }
        $page = max(1, (int) $request->query('page', 1));
        $per = min(100, max(1, (int) $request->query('per_page', 20)));
        return Response::json((new OrganizationRepository())->paginate($where, $p, $page, $per, (string) $request->query('sort', 'created_at'), (string) $request->query('dir', 'desc')));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $org = (new OrganizationRepository())->find((int) $params['id']) ?? throw ApiException::notFound('Organisation introuvable.');
        $request->attributes['audit_org'] = (int) $org['id'];
        return Response::data($org);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, [
            'name' => 'required|string|min:2|max:150',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:32',
            'country' => 'nullable|string|size:2',
            'status' => 'nullable|in:' . self::STATUSES,
            'owner_email' => 'nullable|email|max:190',
            'owner_first_name' => 'nullable|string|min:1|max:80',
            'owner_last_name' => 'nullable|string|min:1|max:80',
        ]);
        $repo = new OrganizationRepository();
        $users = new UserRepository();
        $temp = null;

        $result = Database::transaction(function () use ($d, $repo, $users, &$temp): array {
            $id = $repo->insert([
                'uuid' => uuid4(),
                'name' => $d['name'],
                'slug' => $repo->uniqueSlug($d['name']),
                'email' => $d['email'] ?? null,
                'phone' => $d['phone'] ?? null,
                'country' => strtoupper($d['country'] ?? 'CD'),
                'status' => $d['status'] ?? 'active',
            ]);
            $ownerId = null;
            if (!empty($d['owner_email'])) {
                if (Database::scalar('SELECT 1 FROM users WHERE email = ?', [$d['owner_email']]) !== null) {
                    throw ApiException::conflict('Cette adresse e-mail est déjà utilisée.', 'EMAIL_TAKEN');
                }
                $temp = Str::randomPassword();
                $role = RoleRepository::byCode('owner') ?? throw new \RuntimeException('Rôle owner manquant (seed).');
                $ownerId = $users->insert([
                    'uuid' => uuid4(), 'organization_id' => $id, 'role_id' => $role['id'], 'email' => $d['owner_email'],
                    'password_hash' => Str::hashPassword($temp), 'first_name' => $d['owner_first_name'] ?? 'Owner',
                    'last_name' => $d['owner_last_name'] ?? $d['name'], 'status' => 'active', 'must_change_password' => 1,
                ]);
            }
            return ['id' => $id, 'owner_user_id' => $ownerId];
        });

        $request->attributes['audit_resource_type'] = 'organization';
        $request->attributes['audit_resource_id'] = $result['id'];
        $request->attributes['audit_org'] = $result['id'];
        $org = $repo->find($result['id']);
        $payload = ['organization' => $org, 'owner_user_id' => $result['owner_user_id']];
        if ($temp !== null) {
            $payload['temporary_password'] = $temp; // affiché une seule fois
        }
        return Response::data($payload, 201);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $isPlatform = $request->can('manage_organizations');
        if (!$isPlatform && !$request->can('manage_organization_settings')) {
            throw ApiException::forbidden('Permission requise : manage_organization_settings', 'PERMISSION_DENIED');
        }
        $rules = ['name' => 'string|min:2|max:150', 'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:32'];
        if ($isPlatform) {
            $rules += ['country' => 'string|size:2', 'status' => 'in:' . self::STATUSES];
        }
        $d = Validator::validate($request->body, $rules);
        $id = (int) $params['id'];
        $repo = new OrganizationRepository();
        $repo->find($id) ?? throw ApiException::notFound('Organisation introuvable.'); // 404 si hors périmètre
        if (isset($d['country'])) {
            $d['country'] = strtoupper($d['country']);
        }
        $repo->update($id, $d);
        $request->attributes['audit_resource_type'] = 'organization';
        $request->attributes['audit_org'] = $id;
        return Response::data((array) $repo->find($id));
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $repo = new OrganizationRepository();
        $repo->find($id) ?? throw ApiException::notFound('Organisation introuvable.');
        Database::transaction(function () use ($id, $repo): void {
            $repo->update($id, ['status' => 'closed']);
            $repo->softDelete($id);
            Database::execute('DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE organization_id = ?)', [$id]);
        });
        $request->attributes['audit_resource_type'] = 'organization';
        $request->attributes['audit_org'] = $id;
        return Response::data(['deleted' => true]);
    }
}
