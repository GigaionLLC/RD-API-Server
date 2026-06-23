<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Device (peer) management: list, edit assignment/approval, delete.
 */
class DeviceController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        $devices = Device::query()
            ->with('user:id,username')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('rustdesk_id', 'like', "%{$q}%")
                        ->orWhere('hostname', 'like', "%{$q}%")
                        ->orWhere('alias', 'like', "%{$q}%");
                });
            })
            ->when($status === 'online', fn ($query) => $query->where('is_online', true))
            ->when($status === 'offline', fn ($query) => $query->where('is_online', false))
            ->orderByDesc('last_online_at')
            ->paginate(20)
            ->appends($request->query());

        // Targets for the bulk-assign bar.
        $users = User::orderBy('username')->get(['id', 'username']);
        $deviceGroups = DeviceGroup::orderBy('name')->get(['id', 'name']);
        $strategies = Strategy::orderBy('name')->get(['id', 'name']);

        return view('admin.devices.index', compact('devices', 'q', 'status', 'users', 'deviceGroups', 'strategies'));
    }

    /**
     * Bulk-assign the selected devices to a user, device group, or strategy (or clear it).
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'field' => ['required', Rule::in(['user_id', 'device_group_id', 'strategy_id'])],
            'value' => ['nullable', 'integer'],
        ]);

        $value = $data['value'] ?? null;

        // A non-null value must reference an existing target for the chosen field.
        if ($value !== null) {
            $exists = match ($data['field']) {
                'user_id' => User::whereKey($value)->exists(),
                'device_group_id' => DeviceGroup::whereKey($value)->exists(),
                'strategy_id' => Strategy::whereKey($value)->exists(),
                default => false,
            };
            if (! $exists) {
                return back()->withErrors(['value' => 'The selected target no longer exists.']);
            }
        }

        $count = Device::whereIn('id', $data['ids'])->update([$data['field'] => $value]);

        $labels = ['user_id' => 'owner', 'device_group_id' => 'device group', 'strategy_id' => 'strategy'];

        return back()->with('status', "Updated the {$labels[$data['field']]} on {$count} device(s).");
    }

    /**
     * GET /admin/devices/search?q= — live picker results (id + label), capped, for the
     * searchable combobox so device lists with thousands of rows stay usable.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $devices = Device::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('rustdesk_id', 'like', "%{$q}%")
                ->orWhere('hostname', 'like', "%{$q}%")
                ->orWhere('alias', 'like', "%{$q}%")))
            ->orderBy('rustdesk_id')
            ->limit(20)
            ->get(['id', 'rustdesk_id', 'hostname', 'alias']);

        return response()->json($devices->map(fn (Device $d) => [
            'id' => $d->id,
            'text' => ($d->hostname ?: $d->alias ?: $d->rustdesk_id).' ('.$d->rustdesk_id.')',
        ])->all());
    }

    public function edit(Device $device): View
    {
        // Owner is chosen via a searchable combobox, so only the current owner is loaded here.
        $device->load('user:id,username');
        $deviceGroups = DeviceGroup::orderBy('name')->get(['id', 'name']);
        $strategies = Strategy::orderBy('name')->get(['id', 'name']);

        return view('admin.devices.edit', compact('device', 'deviceGroups', 'strategies'));
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'alias' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'device_group_id' => ['nullable', 'integer', 'exists:device_groups,id'],
            'strategy_id' => ['nullable', 'integer', 'exists:strategies,id'],
            'approved' => ['nullable', 'boolean'],
        ]);

        $device->fill([
            'alias' => $data['alias'] ?? null,
            'note' => $data['note'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'device_group_id' => $data['device_group_id'] ?? null,
            'strategy_id' => $data['strategy_id'] ?? null,
            'approved' => (bool) ($data['approved'] ?? false),
        ])->save();

        return response()->json([]);
    }

    public function destroy(Device $device): RedirectResponse
    {
        $device->delete();

        return redirect()
            ->route('admin.devices.index')
            ->with('status', 'Device deleted.');
    }
}
