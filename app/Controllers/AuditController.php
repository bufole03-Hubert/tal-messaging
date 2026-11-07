<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AuditLogRepository;
use App\Tenancy\TenantContext;

final class AuditController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $where = '1 = 1';
        $p = [];
        if (is_string($a = $request->query('action')) && $a !== '') {
            $where .= ' AND t.action LIKE ?';
            $p[] = addcslashes($a, '%_\\') . '%';
        }
        if (($u = filter_var($request->query('user_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND t.user_id = ?';
            $p[] = $u;
        }
        if (is_string($rt = $request->query('resource_type')) && $rt !== '') {
            $where .= ' AND t.resource_type = ?';
            $p[] = $rt;
        }
        if (is_string($from = $request->query('from')) && strtotime($from) !== false) {
            $where .= ' AND t.created_at >= ?';
            $p[] = gmdate('Y-m-d H:i:s', strtotime($from));
        }
        if (is_string($to = $request->query('to')) && strtotime($to) !== false) {
            $where .= ' AND t.created_at <= ?';
            $p[] = gmdate('Y-m-d H:i:s', strtotime($to));
        }
        // Le filtre par organisation n'a d'effet que pour le contexte global (le contexte tenant est déjà forcé).
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND t.organization_id = ?';
            $p[] = $o;
        }
        $page = max(1, (int) $request->query('page', 1));
        $per = min(100, max(1, (int) $request->query('per_page', 25)));
        $res = (new AuditLogRepository())->paginate($where, $p, $page, $per, 'created_at', 'desc');
        foreach ($res['data'] as &$row) {
            $row['metadata'] = $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null;
        }
        return Response::json($res);
    }
}
