<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoleRepository;

final class RoleController extends Controller
{
    /** Rôles assignables par l'utilisateur courant (client : rôles client de niveau <= au sien). */
    /** @param array<string,string> $params */
    public function roles(Request $request, array $params): Response
    {
        $user = $request->user();
        $roles = array_values(array_filter(RoleRepository::all(), static function (array $r) use ($user): bool {
            if ($user['role_scope'] === 'client') {
                return $r['scope'] === 'client' && (int) $r['level'] <= $user['role_level'] && ($r['code'] !== 'owner' || $user['role_code'] === 'owner');
            }
            return (int) $r['level'] <= $user['role_level'];
        }));
        if (!$request->can('view_roles')) {
            foreach ($roles as &$r) {
                unset($r['permissions']);
            }
        }
        return Response::data($roles);
    }

    /** @param array<string,string> $params */
    public function permissions(Request $request, array $params): Response
    {
        return Response::data(RoleRepository::permissions());
    }
}
