<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
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

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        $devices = $this->devicesQuery($q, is_string($status) ? $status : null)
            ->with('user:id,username')
            ->paginate(20)
            ->appends($request->query());

        // Targets for the bulk-assign bar.
        $users = User::orderBy('username')->get(['id', 'username']);
        $deviceGroups = DeviceGroup::orderBy('name')->get(['id', 'name']);
        $strategies = Strategy::orderBy('name')->get(['id', 'name']);

        return view('admin.devices.index', compact('devices', 'q', 'status', 'users', 'deviceGroups', 'strategies'));
    }

    /**
     * CSV export of the device inventory, honouring the current search + status filter.
     */
    public function export(Request $request): StreamedResponse
    {
        $status = $request->query('status');
        $query = $this->devicesQuery(trim((string) $request->query('q', '')), is_string($status) ? $status : null)
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
    private function devicesQuery(string $q, ?string $status): Builder
    {
        return Device::query()
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

        if ($request->boolean('all')) {
            // Whole filtered set (every page), not just the checked rows.
            $query = $this->devicesQuery(trim((string) ($data['q'] ?? '')), $data['status'] ?? null);
        } else {
            if (empty($data['ids'])) {
                return back()->withErrors(['ids' => 'Select at least one device.']);
            }
            $query = Device::whereIn('id', $data['ids']);
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
