<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Models\UserGroupAccess;
use App\Services\AdminScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * User group management (type 1 = default, type 2 = shared).
 */
class GroupController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): View
    {
        $groups = $this->scope->scopeUserGroups(Group::query(), $request->user(), 'groups.view')
            ->orderBy('name')->paginate(20);

        // Member counts via a single grouped query (Group has no users() relation).
        $memberCounts = User::query()
            ->whereIn('group_id', $groups->getCollection()->pluck('id'))
            ->selectRaw('group_id, COUNT(*) as c')
            ->groupBy('group_id')
            ->pluck('c', 'group_id');

        return view('admin.groups.index', compact('groups', 'memberCounts'));
    }

    public function create(Request $request): View
    {
        $this->scope->authorizeUnrestricted($request->user(), 'groups.edit');
        $group = new Group(['type' => Group::TYPE_DEFAULT]);

        return view('admin.groups.create', compact('group'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->scope->authorizeUnrestricted($request->user(), 'groups.edit');
        $data = $this->validateGroup($request);

        Group::create($data);

        return redirect()
            ->route('admin.groups.index')
            ->with('status', 'Group created.');
    }

    public function edit(Request $request, Group $group): View
    {
        $this->scope->authorizeUserGroup($request->user(), (int) $group->id, 'groups.view');

        // Other user groups available as access targets (Access Control Layer 1).
        $allGroups = $this->scope->scopeUserGroups(Group::query(), $request->user(), 'groups.edit')
            ->where('id', '!=', $group->id)
            ->orderBy('name')
            ->get();

        // Currently granted target group ids.
        $accessGroupIds = UserGroupAccess::query()
            ->where('group_id', $group->id)
            ->pluck('can_access_group_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return view('admin.groups.edit', compact('group', 'allGroups', 'accessGroupIds'));
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $this->scope->authorizeUserGroup($request->user(), (int) $group->id, 'groups.edit');
        $group->fill($this->validateGroup($request))->save();

        $this->syncAccess($request, $group);

        return response()->json([]);
    }

    /**
     * Sync the user_group_access rows for this group from the submitted CSV of target ids.
     */
    private function syncAccess(Request $request, Group $group): void
    {
        $raw = (string) $request->input('can_access_group_ids', '');
        $ids = array_values(array_filter(array_map(
            static fn ($v): int => (int) trim((string) $v),
            $raw === '' ? [] : explode(',', $raw)
        ), static fn (int $id): bool => $id > 0 && $id !== (int) $group->id));
        $ids = array_unique($ids);

        foreach ($ids as $targetId) {
            $this->scope->authorizeUserGroup($request->user(), (int) $targetId, 'groups.edit');
        }

        $deleteQuery = UserGroupAccess::query()->where('group_id', $group->id);
        $allowedGroupIds = $this->scope->userGroupIds($request->user(), 'groups.edit');
        if ($allowedGroupIds !== null) {
            $deleteQuery->whereIn('can_access_group_id', $allowedGroupIds);
        }
        $deleteQuery->delete();

        foreach ($ids as $targetId) {
            UserGroupAccess::firstOrCreate([
                'group_id' => $group->id,
                'can_access_group_id' => $targetId,
            ]);
        }
    }

    public function destroy(Request $request, Group $group): RedirectResponse
    {
        $this->scope->authorizeUserGroup($request->user(), (int) $group->id, 'groups.edit');
        $group->delete();

        return redirect()
            ->route('admin.groups.index')
            ->with('status', 'Group deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGroup(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'integer', Rule::in([Group::TYPE_DEFAULT, Group::TYPE_SHARED])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
