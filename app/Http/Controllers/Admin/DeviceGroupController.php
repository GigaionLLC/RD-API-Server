<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceGroup;
use App\Models\DeviceGroupAccess;
use App\Models\Group;
use App\Services\AdminScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Device group management (used for organisation and strategy targeting).
 */
class DeviceGroupController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): View
    {
        $deviceGroups = $this->scope->scopeDeviceGroups(
            DeviceGroup::query()->withCount('devices'),
            $request->user(),
            'device_groups.view',
        )->orderBy('name')->paginate(20);
        // The current default (may be on another page) so the UI can name it in the
        // replace-confirmation.
        $defaultGroup = $this->scope->scopeDeviceGroups(
            DeviceGroup::query()->where('is_default', true),
            $request->user(),
            'device_groups.view',
        )->first(['id', 'name']);

        return view('admin.device_groups.index', compact('deviceGroups', 'defaultGroup'));
    }

    public function create(Request $request): View
    {
        $this->scope->authorizeUnrestricted($request->user(), 'device_groups.edit');
        $deviceGroup = new DeviceGroup;

        return view('admin.device_groups.create', compact('deviceGroup'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->scope->authorizeUnrestricted($request->user(), 'device_groups.edit');
        $group = DeviceGroup::create($this->validateGroup($request));

        // Convenience: if there's no default yet, the first group created becomes the default
        // (so new devices are grouped without an extra "set default" step).
        if (DeviceGroup::defaultId() === null) {
            $group->forceFill(['is_default' => true])->save();
        }

        return redirect()
            ->route('admin.device-groups.index')
            ->with('status', $group->is_default ? 'Device group created and set as default.' : 'Device group created.');
    }

    public function edit(Request $request, DeviceGroup $deviceGroup): View
    {
        $this->scope->authorizeDeviceGroup($request->user(), (int) $deviceGroup->id, 'device_groups.view');

        // Editors receive only their editable boundary; view-only delegates receive their
        // readable boundary so existing mappings remain understandable without widening it.
        $scopePermission = $request->user()->hasPermission('device_groups.edit')
            ? 'device_groups.edit'
            : 'device_groups.view';
        // Currently granted user-group ids.
        $accessGroupIds = DeviceGroupAccess::query()
            ->where('device_group_id', $deviceGroup->id)
            ->pluck('group_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // Only the granted groups are loaded. The picker searches the rest through
        // admin.groups.search, so a directory with thousands of groups no longer puts all
        // of them in the page. Still scoped: a name outside the boundary is not shown even
        // when the grant exists.
        $selectedAccessGroups = $accessGroupIds === []
            ? collect()
            : $this->scope->scopeUserGroups(Group::query(), $request->user(), $scopePermission)
                ->whereIn('id', $accessGroupIds)
                ->orderBy('name')
                ->get(['id', 'name']);

        return view('admin.device_groups.edit', compact('deviceGroup', 'selectedAccessGroups', 'accessGroupIds'));
    }

    public function update(Request $request, DeviceGroup $deviceGroup): JsonResponse
    {
        $this->scope->authorizeDeviceGroup($request->user(), (int) $deviceGroup->id, 'device_groups.edit');
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

        foreach ($ids as $groupId) {
            $this->scope->authorizeUserGroup($request->user(), (int) $groupId, 'device_groups.edit');
        }

        $deleteQuery = DeviceGroupAccess::query()->where('device_group_id', $deviceGroup->id);
        $allowedGroupIds = $this->scope->userGroupIds($request->user(), 'device_groups.edit');
        if ($allowedGroupIds !== null) {
            $deleteQuery->whereIn('group_id', $allowedGroupIds);
        }
        $deleteQuery->delete();

        foreach ($ids as $groupId) {
            DeviceGroupAccess::firstOrCreate([
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
    public function setDefault(Request $request, DeviceGroup $deviceGroup): RedirectResponse
    {
        $this->scope->authorizeUnrestricted($request->user(), 'device_groups.edit');
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

    public function destroy(Request $request, DeviceGroup $deviceGroup): RedirectResponse
    {
        $this->scope->authorizeDeviceGroup($request->user(), (int) $deviceGroup->id, 'device_groups.edit');
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
