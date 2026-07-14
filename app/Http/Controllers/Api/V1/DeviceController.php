<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use App\Services\AdminScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin REST API (v1) — devices. Read needs `devices.read`; reassigning a device's owner,
 * device group, strategy or alias needs `devices.write`.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $devices = $this->scope->scopeDevices(Device::query(), $request->user(), 'devices.view')
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

    /**
     * PUT /api/v1/devices/{device} — reassign owner / device group / strategy / alias. Only the
     * supplied keys are changed; pass an explicit null to clear an assignment.
     */
    public function update(Request $request, Device $device): JsonResponse
    {
        $this->scope->authorizeDevice($request->user(), $device, 'devices.edit');
        $data = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'device_group_id' => ['sometimes', 'nullable', 'integer', 'exists:device_groups,id'],
            'strategy_id' => ['sometimes', 'nullable', 'integer', 'exists:strategies,id'],
            'alias' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('user_id', $data) && $data['user_id'] === null && $device->user_id !== null) {
            $this->scope->authorizeUnrestricted($request->user(), 'devices.edit');
        } elseif (($data['user_id'] ?? null) !== null) {
            $this->scope->authorizeUser(
                $request->user(),
                User::findOrFail($data['user_id']),
                'devices.edit',
            );
        }
        if (($data['device_group_id'] ?? null) !== null) {
            DeviceGroup::findOrFail($data['device_group_id']);
            $this->scope->authorizeDeviceGroup(
                $request->user(),
                (int) $data['device_group_id'],
                'devices.edit',
            );
        }
        if (($data['strategy_id'] ?? null) !== null) {
            Strategy::findOrFail($data['strategy_id']);
            $this->scope->authorizeStrategy(
                $request->user(),
                (int) $data['strategy_id'],
                'devices.edit',
            );
        }

        $device->fill($data)->save();

        return response()->json(['data' => $device->only([
            'id', 'rustdesk_id', 'alias', 'user_id', 'device_group_id', 'strategy_id',
        ])]);
    }
}
