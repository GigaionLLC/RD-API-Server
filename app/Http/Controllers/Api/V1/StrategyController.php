<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin REST API (v1) — strategies. Authenticated by a scoped API key (strategies.read).
 */
class StrategyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $strategies = Strategy::query()
            ->withCount('assignments')
            ->orderBy('name')
            ->paginate($perPage, ['id', 'name', 'note', 'enabled', 'options', 'modified_at']);

        return response()->json($strategies);
    }
}
