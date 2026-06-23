<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin REST API (v1) — devices. Authenticated by a scoped API key (devices.read).
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $devices = Device::query()
            ->with('user:id,username')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('rustdesk_id', 'like', "%{$q}%")
                ->orWhere('hostname', 'like', "%{$q}%")
                ->orWhere('alias', 'like', "%{$q}%")))
            ->orderByDesc('last_online_at')
            ->paginate($perPage, [
                'id', 'rustdesk_id', 'alias', 'hostname', 'os', 'version', 'user_id',
                'device_group_id', 'strategy_id', 'is_online', 'last_online_at', 'last_online_ip',
            ]);

        return response()->json($devices);
    }
}
