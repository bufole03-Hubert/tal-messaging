<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Support\Str;
use App\Tenancy\TenantContext;

final class UserController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $ctx = TenantContext::current();
        $where = '1 = 1';
        $p = [];
        if ($ctx->global) {
            if (($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
                $where .= ' AND u.organization_id = ?';
                $p[] = $o;
            } elseif ($request->query('scope') === 'internal') {
                $where .= ' AND u.organization_id IS NULL';
            }
        }
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $like = '%' . addcslashes(trim($q), '%_\\') . '%';
            $where .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if (is_string($s = $request->query('status')) && in_array($s, ['active', 'suspended'], true)) {
            $where .= ' AND u.status = ?';
            $p[] = $s;
        }
        $page = max(1, (int) $request->query('page', 1));
        $per = min(100, max(1, (int) $request->query('per_page', 20)));
        return Response::json((new UserRepository())->paginate($where, $p, $page, $per, (string) $request->query('sort', 'created_at'), (string) $request->query('dir', 'desc')));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $u = (new UserRepository())->find((int) $params['id']) ?? throw ApiException::notFound('Utilisateur introuvable.');
        $request->attributes['audit_org'] = $u['organization_id'];
        return Response::data($u);
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $actor = $request->user();
        $ctx = TenantContext::current();
        $d = Validator::validate($request->body, [
            'email' => 'required|email|max:190',
            'first_name' => 'required|string|min:1|max:80',
            'last_name' => 'required|string|min:1|max:80',
            'role' => 'required|string|max:50',
            'organization_id' => 'nullable|int|min:1',
            'password' => 'nullable|string|password',
        ]);
        $role = RoleRepository::byCode($d['role']) ?? throw ApiException::validation(['role' => ['Rôle inconnu.']]);
        $this->assertCanAssign($actor, $role);

        if ($role['scope'] === 'internal') {
            if (!$request->can('manage_internal_users') || !$ctx->global) {
                throw ApiException::forbidden('Création de comptes internes non autorisée.', 'PERMISSION_DENIED');
            }
            $orgId = null;
        } elseif ($ctx->global) {
            $orgId = $d['organization_id'] ?? throw ApiException::validation(['organization_id' => ['Requis pour un rôle client.']]);
            (new OrganizationRepository())->find((int) $orgId) ?? throw ApiException::validation(['organization_id' => ['Organisation introuvable.']]);
        } else {
            $orgId = $ctx->organizationId; // forcé, l'entrée est ignorée
        }

        if (Database::scalar('SELECT 1 FROM users WHERE email = ?', [$d['email']]) !== null) {
            throw ApiException::conflict('Cette adresse e-mail est déjà utilisée.', 'EMAIL_TAKEN');
        }
        $generated = !isset($d['password']);
        $password = $d['password'] ?? Str::randomPassword();
        $id = (new UserRepository())->insert([
            'uuid' => uuid4(), 'organization_id' => $orgId, 'role_id' => $role['id'], 'email' => $d['email'],
            'password_hash' => Str::hashPassword($password), 'first_name' => $d['first_name'], 'last_name' => $d['last_name'],
            'status' => 'active', 'must_change_password' => 1,
        ]);
        $request->attributes['audit_resource_type'] = 'user';
        $request->attributes['audit_resource_id'] = $id;
        $request->attributes['audit_org'] = $orgId;
        $payload = ['user' => (new UserRepository())->find($id)];
        if ($generated) {
            $payload['temporary_password'] = $password; // affiché une seule fois
        }
        return Response::data($payload, 201);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $actor = $request->user();
        $repo = new UserRepository();
        $id = (int) $params['id'];
        $target = $repo->find($id) ?? throw ApiException::notFound('Utilisateur introuvable.');
        $this->assertCanManage($actor, $target);

        $d = Validator::validate($request->body, [
            'first_name' => 'string|min:1|max:80',
            'last_name' => 'string|min:1|max:80',
            'role' => 'string|max:50',
            'status' => 'in:active,suspended',
        ]);
        $isSelf = $id === (int) $actor['id'];
        $update = array_intersect_key($d, ['first_name' => 1, 'last_name' => 1]);
        $revoke = false;

        if (isset($d['role']) && $d['role'] !== $target['role']) {
            if ($isSelf) {
                throw ApiException::forbidden('Vous ne pouvez pas modifier votre propre rôle.', 'SELF_MODIFICATION');
            }
            $role = RoleRepository::byCode($d['role']) ?? throw ApiException::validation(['role' => ['Rôle inconnu.']]);
            $this->assertCanAssign($actor, $role);
            if ($role['scope'] !== $target['role_scope']) {
                throw ApiException::validation(['role' => ['Impossible de passer d’un rôle interne à un rôle client.']]);
            }
            $this->assertNotLastOwner($target);
            $update['role_id'] = $role['id'];
            $revoke = true;
        }
        if (isset($d['status']) && $d['status'] !== $target['status']) {
            if ($isSelf) {
                throw ApiException::forbidden('Vous ne pouvez pas modifier votre propre statut.', 'SELF_MODIFICATION');
            }
            if ($d['status'] === 'suspended') {
                $this->assertNotLastOwner($target);
                $revoke = true;
            }
            $update['status'] = $d['status'];
        }
        $repo->update($id, $update);
        if ($revoke) {
            Session::destroyForUser($id);
        }
        $request->attributes['audit_resource_type'] = 'user';
        $request->attributes['audit_org'] = $target['organization_id'];
        return Response::data((array) $repo->find($id));
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        $actor = $request->user();
        $repo = new UserRepository();
        $id = (int) $params['id'];
        $target = $repo->find($id) ?? throw ApiException::notFound('Utilisateur introuvable.');
        if ($id === (int) $actor['id']) {
            throw ApiException::forbidden('Vous ne pouvez pas supprimer votre propre compte.', 'SELF_MODIFICATION');
        }
        $this->assertCanManage($actor, $target);
        $this->assertNotLastOwner($target);
        $repo->softDelete($id);
        Session::destroyForUser($id);
        $request->attributes['audit_resource_type'] = 'user';
        $request->attributes['audit_org'] = $target['organization_id'];
        return Response::data(['deleted' => true]);
    }

    /** Anti-escalade de privilèges : on n'attribue jamais un rôle supérieur au sien. */
    /** @param array<string,mixed> $actor @param array<string,mixed> $role */
    private function assertCanAssign(array $actor, array $role): void
    {
        $ok = (int) $role['level'] <= $actor['role_level'];
        if ($actor['role_scope'] === 'client') {
            $ok = $ok && $role['scope'] === 'client' && ($role['code'] !== 'owner' || $actor['role_code'] === 'owner');
        }
        if (!$ok) {
            throw ApiException::forbidden('Vous ne pouvez pas attribuer ce rôle.', 'ROLE_ESCALATION');
        }
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $target */
    private function assertCanManage(array $actor, array $target): void
    {
        if ((int) $target['id'] !== (int) $actor['id'] && (int) $target['role_level'] > $actor['role_level']) {
            throw ApiException::forbidden('Vous ne pouvez pas gérer un utilisateur de niveau supérieur.', 'ROLE_ESCALATION');
        }
    }

    /** @param array<string,mixed> $target */
    private function assertNotLastOwner(array $target): void
    {
        if ($target['role'] === 'owner' && $target['organization_id'] !== null && $target['status'] === 'active'
            && (new UserRepository())->activeOwnerCount((int) $target['organization_id']) <= 1) {
            throw ApiException::conflict('Une organisation doit conserver au moins un Owner actif.', 'LAST_OWNER');
        }
    }
}
