<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceGroup;
use App\Models\DeviceGroupAccess;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Device group management (used for organisation and strategy targeting).
 */
class DeviceGroupController extends Controller
{
    public function index(): View
    {
        $deviceGroups = DeviceGroup::withCount('devices')->orderBy('name')->paginate(20);

        return view('admin.device_groups.index', compact('deviceGroups'));
    }

    public function create(): View
    {
        $deviceGroup = new DeviceGroup;

        return view('admin.device_groups.create', compact('deviceGroup'));
    }

    public function store(Request $request): RedirectResponse
    {
        DeviceGroup::create($this->validateGroup($request));

        return redirect()
            ->route('admin.device-groups.index')
            ->with('status', 'Device group created.');
    }

    public function edit(DeviceGroup $deviceGroup): View
    {
        // User groups that can be granted access to this device group (Access Control Layer 1).
        $userGroups = Group::query()->orderBy('name')->get();

        // Currently granted user-group ids.
        $accessGroupIds = DeviceGroupAccess::query()
            ->where('device_group_id', $deviceGroup->id)
            ->pluck('group_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return view('admin.device_groups.edit', compact('deviceGroup', 'userGroups', 'accessGroupIds'));
    }

    public function update(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $deviceGroup->fill($this->validateGroup($request))->save();

        $this->syncAccess($request, $deviceGroup);

        return response()->json([]);
    }

    /**
     * Sync the device_group_access rows for this device group from the submitted CSV of
     * user-group ids.
     */
    private function syncAccess(Request $request, DeviceGroup $deviceGroup): void
    {
        $raw = (string) $request->input('access_group_ids', '');
        $ids = array_values(array_filter(array_map(
            static fn ($v): int => (int) trim((string) $v),
            $raw === '' ? [] : explode(',', $raw)
        ), static fn (int $id): bool => $id > 0));
        $ids = array_unique($ids);

        DeviceGroupAccess::query()->where('device_group_id', $deviceGroup->id)->delete();

        foreach ($ids as $groupId) {
            DeviceGroupAccess::create([
                'group_id' => $groupId,
                'device_group_id' => $deviceGroup->id,
            ]);
        }
    }

    /**
     * Toggle this group as THE default for new/ungrouped devices. Marking one clears any
     * previous default (at most one is default at a time); toggling the current default off
     * leaves no default.
     */
    public function setDefault(DeviceGroup $deviceGroup): RedirectResponse
    {
        $makeDefault = ! $deviceGroup->is_default;

        DeviceGroup::query()->where('is_default', true)->update(['is_default' => false]);

        if ($makeDefault) {
            $deviceGroup->forceFill(['is_default' => true])->save();
        }

        return redirect()
            ->route('admin.device-groups.index')
            ->with('status', $makeDefault
                ? "\"{$deviceGroup->name}\" is now the default group for new devices."
                : 'Default device group cleared.');
    }

    public function destroy(DeviceGroup $deviceGroup): RedirectResponse
    {
        $deviceGroup->delete();

        return redirect()
            ->route('admin.device-groups.index')
            ->with('status', 'Device group deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGroup(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
