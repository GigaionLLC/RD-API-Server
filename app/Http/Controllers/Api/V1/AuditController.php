<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditConn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin REST API (v1) — connection audit log. Authenticated by a scoped API key (audit.read).
 */
class AuditController extends Controller
{
    public function connections(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $conns = AuditConn::query()
            ->when($request->query('peer_id'), fn ($q, $id) => $q->where('peer_id', $id))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($conns);
    }
}
