<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * User account management: CRUD plus a password reset.
 */
class UserController extends Controller
{
    /**
     * GET /admin/users/search?q= — live picker results (id + username) for the searchable
     * combobox, capped, so user pickers stay usable with many accounts.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('username', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('username')
            ->limit(20)
            ->get(['id', 'username']);

        return response()->json($users->map(fn (User $u) => [
            'id' => $u->id,
            'text' => (string) $u->username,
        ])->all());
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('display_name', 'like', "%{$q}%");
            })
            ->orderBy('username')
            ->paginate(20)
            ->appends($request->query());

        return view('admin.users.index', compact('users', 'q'));
    }

    public function create(): View
    {
        $user = new User(['status' => User::STATUS_NORMAL, 'login_verify' => User::LOGIN_VERIFY_OFF]);
        $groups = Group::orderBy('name')->get(['id', 'name']);

        return view('admin.users.create', compact('user', 'groups'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            'is_admin' => ['nullable', 'boolean'],
            'status' => ['required', 'integer', Rule::in([User::STATUS_NORMAL, User::STATUS_DISABLED, User::STATUS_UNVERIFIED])],
            'force_sso' => ['nullable', 'boolean'],
            'login_verify' => ['required', Rule::in([User::LOGIN_VERIFY_OFF, User::LOGIN_VERIFY_EMAIL, User::LOGIN_VERIFY_TOTP])],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data['is_admin'] = (bool) ($data['is_admin'] ?? false);
        $data['force_sso'] = (bool) ($data['force_sso'] ?? false);

        User::create($data);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        $groups = Group::orderBy('name')->get(['id', 'name']);
        $adminRoles = AdminRole::orderBy('name')->get(['id', 'name']);
        $assignedRoleIds = $user->adminRoles()->pluck('admin_roles.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return view('admin.users.edit', compact('user', 'groups', 'adminRoles', 'assignedRoleIds'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'is_admin' => ['nullable', 'boolean'],
            'status' => ['required', 'integer', Rule::in([User::STATUS_NORMAL, User::STATUS_DISABLED, User::STATUS_UNVERIFIED])],
            'force_sso' => ['nullable', 'boolean'],
            'login_verify' => ['required', Rule::in([User::LOGIN_VERIFY_OFF, User::LOGIN_VERIFY_EMAIL, User::LOGIN_VERIFY_TOTP])],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'admin_role_ids' => ['nullable', 'string'],
        ]);

        $data['is_admin'] = (bool) ($data['is_admin'] ?? false);
        $data['force_sso'] = (bool) ($data['force_sso'] ?? false);
        $data['group_id'] = $data['group_id'] ?? null;

        $roleIds = $this->parseRoleIds($request->input('admin_role_ids'));
        unset($data['admin_role_ids']);

        $user->fill($data)->save();
        $user->adminRoles()->sync($roleIds);

        return response()->json([]);
    }

    /**
     * Parse the submitted CSV of admin-role ids into a clean, validated id list.
     *
     * @return array<int, int>
     */
    private function parseRoleIds(?string $raw): array
    {
        $ids = array_values(array_filter(array_map(
            static fn ($v): int => (int) trim((string) $v),
            $raw === null || $raw === '' ? [] : explode(',', $raw)
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        return AdminRole::whereIn('id', array_unique($ids))->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user->forceFill(['password' => $data['password']])->save();

        return response()->json([]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return redirect()
                ->route('admin.users.index')
                ->with('status', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'User deleted.');
    }
}
