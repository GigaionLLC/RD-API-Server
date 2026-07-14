<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin Role management (Admin Role Layer 3, docs/modernization/12-access-control-design.md).
 * CRUD over scoped console roles: each role grants a set of permission strings and may be
 * scoped to user/device groups. Gated behind the `roles.*` permissions.
 */
class AdminRoleController extends Controller
{
    public function index(Request $request): View
    {
        $roles = AdminRole::query()
            ->withCount('users')
            ->orderBy('name')
            ->paginate(20);

        $canEdit = (bool) $request->user()->is_admin;

        return view('admin.admin_roles.index', compact('roles', 'canEdit'));
    }

    public function create(Request $request): View
    {
        $this->authorizeRoleMutation($request);

        $role = new AdminRole(['type' => AdminRole::TYPE_GLOBAL, 'perms' => [], 'scope' => []]);
        $groups = Group::orderBy('name')->get(['id', 'name']);
        $canEdit = true;

        return view('admin.admin_roles.create', compact('role', 'groups', 'canEdit'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoleMutation($request);

        $data = $this->validateRole($request);

        AdminRole::create($data);

        return redirect()
            ->route('admin.roles.index')
            ->with('status', 'Role created.');
    }

    public function edit(Request $request, AdminRole $role): View
    {
        $groups = Group::orderBy('name')->get(['id', 'name']);
        $canEdit = (bool) $request->user()->is_admin;

        return view('admin.admin_roles.edit', compact('role', 'groups', 'canEdit'));
    }

    public function update(Request $request, AdminRole $role): RedirectResponse
    {
        $this->authorizeRoleMutation($request);

        $role->fill($this->validateRole($request))->save();

        return redirect()
            ->route('admin.roles.index')
            ->with('status', 'Role updated.');
    }

    public function destroy(Request $request, AdminRole $role): RedirectResponse
    {
        $this->authorizeRoleMutation($request);

        $role->users()->detach();
        $role->delete();

        return redirect()
            ->route('admin.roles.index')
            ->with('status', 'Role deleted.');
    }

    /**
     * Validate and normalise the submitted role: name, type, the checkbox-grid permissions
     * (restricted to the catalogue), and group scope (only meaningful for the group type).
     *
     * @return array<string, mixed>
     */
    private function validateRole(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(AdminRole::TYPES)],
            'perms' => ['array'],
            'perms.*' => ['string', Rule::in(AdminRole::allPermissions())],
            'scope' => ['array'],
            'scope.*' => ['integer', 'exists:groups,id'],
        ]);

        $perms = array_values(array_intersect(AdminRole::allPermissions(), $validated['perms'] ?? []));

        $scope = $validated['type'] === AdminRole::TYPE_GROUP
            ? array_values(array_map('intval', $validated['scope'] ?? []))
            : [];

        return [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'perms' => $perms,
            'scope' => $scope,
        ];
    }

    /**
     * Delegated role mutation cannot be made safe without a formal role hierarchy: an
     * editor could otherwise rewrite a role assigned to themselves as global. Delegates may
     * inspect roles through roles.view; only full administrators may change them.
     */
    private function authorizeRoleMutation(Request $request): void
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only a full administrator may modify admin roles.');
        }
    }
}
