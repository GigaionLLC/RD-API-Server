<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use App\Services\AdminScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Device (peer) management: list, edit assignment/approval, delete, and CSV export.
 */
class DeviceController extends Controller
{
    use ExportsCsv;

    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');
        $actor = $request->user();

        $devices = $this->devicesQuery($actor, 'devices.view', $q, is_string($status) ? $status : null)
            ->with('user:id,username')
            ->paginate(20)
            ->appends($request->query());

        // Targets for the bulk-assign bar.
        $users = $this->scope->scopeUsers(User::query(), $actor, 'devices.edit')
            ->orderBy('username')->get(['id', 'username']);
        $deviceGroups = $this->scope->scopeDeviceGroups(DeviceGroup::query(), $actor, 'devices.edit')
            ->orderBy('name')->get(['id', 'name']);
        $strategies = $this->scope->scopeStrategies(Strategy::query(), $actor, 'devices.edit')
            ->orderBy('name')->get(['id', 'name']);

        return view('admin.devices.index', compact('devices', 'q', 'status', 'users', 'deviceGroups', 'strategies'));
    }

    /**
     * CSV export of the device inventory, honouring the current search + status filter.
     */
    public function export(Request $request): StreamedResponse
    {
        $status = $request->query('status');
        $query = $this->devicesQuery(
            $request->user(),
            'devices.view',
            trim((string) $request->query('q', '')),
            is_string($status) ? $status : null,
        )
            ->with('user:id,username');

        return $this->streamCsv('devices', [
            'id', 'alias', 'hostname', 'os', 'version', 'owner', 'online', 'last_online_at', 'last_online_ip',
        ], $query, fn (Device $d): array => [
            $d->rustdesk_id, $d->alias, $d->hostname, $d->os, $d->version,
            $d->user->username ?? '', $d->is_online ? 'yes' : 'no',
            (string) $d->last_online_at, $d->last_online_ip,
        ]);
    }

    /**
     * @return Builder<Device>
     */
    private function devicesQuery(User $actor, string $permission, string $q, ?string $status): Builder
    {
        return $this->scope->scopeDevices(Device::query(), $actor, $permission)
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('rustdesk_id', 'like', "%{$q}%")
                ->orWhere('hostname', 'like', "%{$q}%")
                ->orWhere('alias', 'like', "%{$q}%")))
            ->when($status === 'online', fn (Builder $query) => $query->where('is_online', true))
            ->when($status === 'offline', fn (Builder $query) => $query->where('is_online', false))
            ->orderByDesc('last_online_at');
    }

    /**
     * Bulk-assign the selected devices to a user, device group, or strategy (or clear it).
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['array'],
            'ids.*' => ['integer'],
            // When true, apply to every device matching the current filter, not just `ids`.
            'all' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'field' => ['required', Rule::in(['user_id', 'device_group_id', 'strategy_id'])],
            'value' => ['nullable', 'integer'],
        ]);

        $value = $data['value'] ?? null;
        $actor = $request->user();

        if ($data['field'] === 'user_id' && $value === null) {
            $this->scope->authorizeUnrestricted($actor, 'devices.edit');
        }

        // A non-null value must reference an existing target for the chosen field.
        if ($value !== null) {
            if ($data['field'] === 'user_id') {
                $target = User::find($value);
                if ($target === null) {
                    return back()->withErrors(['value' => 'The selected target no longer exists.']);
                }
                $this->scope->authorizeUser($actor, $target, 'devices.edit');
            } elseif ($data['field'] === 'device_group_id') {
                if (! DeviceGroup::whereKey($value)->exists()) {
                    return back()->withErrors(['value' => 'The selected target no longer exists.']);
                }
                $this->scope->authorizeDeviceGroup($actor, (int) $value, 'devices.edit');
            } else {
                if (! Strategy::whereKey($value)->exists()) {
                    return back()->withErrors(['value' => 'The selected target no longer exists.']);
                }
                $this->scope->authorizeStrategy($actor, (int) $value, 'devices.edit');
            }
        }

        if ($request->boolean('all')) {
            // Whole filtered set (every page), not just the checked rows.
            $query = $this->devicesQuery(
                $actor,
                'devices.edit',
                trim((string) ($data['q'] ?? '')),
                $data['status'] ?? null,
            );
        } else {
            if (empty($data['ids'])) {
                return back()->withErrors(['ids' => 'Select at least one device.']);
            }
            $ids = array_values(array_map('intval', $data['ids']));
            $this->scope->authorizeDeviceIds($actor, $ids, 'devices.edit');
            $query = Device::whereIn('id', $ids);
        }

        $count = $query->update([$data['field'] => $value]);

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

        $devices = $this->scope->scopeDevices(Device::query(), $request->user(), 'devices.view')
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

    public function edit(Request $request, Device $device): View
    {
        $actor = $request->user();
        $this->scope->authorizeDevice($actor, $device, 'devices.view');

        // Owner is chosen via a searchable combobox, so only the current owner is loaded here.
        $device->load('user:id,username');
        $deviceGroups = $this->scope->scopeDeviceGroups(DeviceGroup::query(), $actor, 'devices.edit')
            ->orderBy('name')->get(['id', 'name']);
        $strategies = $this->scope->scopeStrategies(Strategy::query(), $actor, 'devices.edit')
            ->orderBy('name')->get(['id', 'name']);

        return view('admin.devices.edit', compact('device', 'deviceGroups', 'strategies'));
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $actor = $request->user();
        $this->scope->authorizeDevice($actor, $device, 'devices.edit');

        $data = $request->validate([
            'alias' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'device_group_id' => ['nullable', 'integer', 'exists:device_groups,id'],
            'strategy_id' => ['nullable', 'integer', 'exists:strategies,id'],
            'approved' => ['nullable', 'boolean'],
        ]);

        $ownerId = array_key_exists('user_id', $data) ? $data['user_id'] : $device->user_id;
        if ($ownerId === null && $device->user_id !== null) {
            $this->scope->authorizeUnrestricted($actor, 'devices.edit');
        } elseif ($ownerId !== null) {
            $this->scope->authorizeUser($actor, User::findOrFail($ownerId), 'devices.edit');
        }
        if (($data['device_group_id'] ?? null) !== null) {
            $this->scope->authorizeDeviceGroup($actor, (int) $data['device_group_id'], 'devices.edit');
        }
        if (($data['strategy_id'] ?? null) !== null) {
            $this->scope->authorizeStrategy($actor, (int) $data['strategy_id'], 'devices.edit');
        }

        $updates = [];
        foreach (['alias', 'note', 'user_id', 'device_group_id', 'strategy_id', 'approved'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $field === 'approved' ? (bool) $data[$field] : $data[$field];
            }
        }
        $device->fill($updates)->save();

        return response()->json([]);
    }

    public function destroy(Request $request, Device $device): RedirectResponse
    {
        $this->scope->authorizeDevice($request->user(), $device, 'devices.edit');
        $device->delete();

        return redirect()
            ->route('admin.devices.index')
            ->with('status', 'Device deleted.');
    }
}
