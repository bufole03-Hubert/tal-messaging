<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HealthController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $dbOk = true;
        try {
            Database::scalar('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        // Volontairement minimal : aucune version ni détail interne exposés.
        return Response::json(['status' => $dbOk ? 'ok' : 'degraded', 'checks' => ['database' => $dbOk ? 'up' : 'down']], $dbOk ? 200 : 503);
    }
}
