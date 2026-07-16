<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\Group;
use App\Models\LdapIdentity;
use App\Models\User;
use App\Models\UserThird;
use App\Services\AccountCredentialService;
use App\Services\AdminScopeService;
use App\Support\AccountPasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * User account management: CRUD plus a password reset.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $scope,
        private readonly AccountCredentialService $credentials,
    ) {}

    /**
     * GET /admin/users/search?q= — live picker results (id + username) for the searchable
     * combobox, capped, so user pickers stay usable with many accounts.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $users = $this->scope->scopeUsers(User::query(), $request->user(), 'users.view')
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

        $actor = $request->user();
        $users = $this->scope->scopeUsers(User::query(), $actor, 'users.view')
            ->withExists('adminRoles')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(fn ($search) => $search
                    ->where('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('display_name', 'like', "%{$q}%"));
            })
            ->orderBy('username')
            ->paginate(20)
            ->appends($request->query());

        $groups = $this->scope->scopeUserGroups(Group::query(), $actor, 'users.edit')
            ->orderBy('name')->get(['id', 'name']);

        $canEdit = $request->user()->hasPermission('users.edit');
        $canManageAdminAccess = (bool) $request->user()->is_admin;

        return view('admin.users.index', compact('users', 'q', 'groups', 'canEdit', 'canManageAdminAccess'));
    }

    /**
     * Bulk action on the selected users: enable, disable, set group, or delete. Destructive
     * actions (disable / delete) skip the acting admin so they can't lock themselves out.
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['enable', 'disable', 'delete', 'group'])],
            'value' => ['nullable', 'integer'],
        ]);

        $ids = array_map('intval', $data['ids']);
        $actor = $request->user();
        $this->scope->authorizeUserIds($actor, array_values($ids), 'users.edit');

        if (! $actor->is_admin && User::query()
            ->whereIn('id', $ids)
            ->where(fn ($query) => $query->where('is_admin', true)->orWhereHas('adminRoles'))
            ->exists()) {
            abort(403, 'Only a full administrator may bulk-update privileged accounts.');
        }

        $self = (int) $actor->id;
        $protect = static fn (array $list): array => array_values(array_filter($list, fn (int $id): bool => $id !== $self));

        switch ($data['action']) {
            case 'enable':
                $count = User::whereIn('id', $ids)->update(['status' => User::STATUS_NORMAL]);
                $msg = "Enabled {$count} user(s).";
                break;
            case 'disable':
                $count = User::whereIn('id', $protect($ids))->update(['status' => User::STATUS_DISABLED]);
                $msg = "Disabled {$count} user(s).";
                break;
            case 'delete':
                $count = User::whereIn('id', $protect($ids))->delete();
                $msg = "Deleted {$count} user(s).";
                break;
            default: // group
                $value = $data['value'] ?? null;
                if ($value !== null && ! Group::whereKey($value)->exists()) {
                    return back()->withErrors(['value' => 'The selected group no longer exists.']);
                }
                if ($value === null) {
                    $this->scope->authorizeUnrestricted($actor, 'users.edit');
                } else {
                    $this->scope->authorizeUserGroup($actor, (int) $value, 'users.edit');
                }
                $count = User::whereIn('id', $ids)->update(['group_id' => $value]);
                $msg = "Updated the group on {$count} user(s).";
                break;
        }

        return back()->with('status', $msg);
    }

    public function create(Request $request): View
    {
        $user = new User(['status' => User::STATUS_NORMAL, 'login_verify' => User::LOGIN_VERIFY_OFF]);
        $groups = $this->scope->scopeUserGroups(Group::query(), $request->user(), 'users.edit')
            ->orderBy('name')->get(['id', 'name']);
        $canManageAdminAccess = (bool) $request->user()->is_admin;

        return view('admin.users.create', compact('user', 'groups', 'canManageAdminAccess'));
    }

    public function store(Request $request): RedirectResponse
    {
        $canManageAdminAccess = (bool) $request->user()->is_admin;
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'password' => AccountPasswordPolicy::rules(),
            'is_admin' => [Rule::prohibitedIf(! $canManageAdminAccess), 'nullable', 'boolean'],
            'status' => ['required', 'integer', Rule::in([User::STATUS_NORMAL, User::STATUS_DISABLED, User::STATUS_UNVERIFIED])],
            'force_sso' => ['nullable', 'boolean'],
            'login_verify' => ['required', Rule::in([User::LOGIN_VERIFY_OFF, User::LOGIN_VERIFY_EMAIL])],
            'two_factor_enabled' => ['missing'],
            'two_factor_secret' => ['missing'],
            'two_factor_confirmed_at' => ['missing'],
            'two_factor_recovery_codes' => ['missing'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data['is_admin'] = $canManageAdminAccess && (bool) ($data['is_admin'] ?? false);
        $data['force_sso'] = (bool) ($data['force_sso'] ?? false);
        $data['two_factor_enabled'] = false;
        $data['two_factor_secret'] = null;
        $data['two_factor_confirmed_at'] = null;
        $data['two_factor_recovery_codes'] = null;

        if (! $this->scope->isUnrestricted($request->user(), 'users.edit')) {
            if (($data['group_id'] ?? null) === null) {
                abort(403, 'Scoped user managers must create users inside their assigned groups.');
            }
            $this->scope->authorizeUserGroup($request->user(), (int) $data['group_id'], 'users.edit');
        }

        User::create($data);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'User created.');
    }

    public function edit(Request $request, User $user): View
    {
        $this->scope->authorizeUser($request->user(), $user, 'users.view');
        $this->authorizePrivilegedAccountManagement($request, $user);

        $groups = $this->scope->scopeUserGroups(Group::query(), $request->user(), 'users.edit')
            ->orderBy('name')->get(['id', 'name']);
        $canEdit = $request->user()->hasPermission('users.edit');
        $canManageAdminAccess = (bool) $request->user()->is_admin;
        $adminRoles = $canManageAdminAccess
            ? AdminRole::orderBy('name')->get(['id', 'name'])
            : collect();
        $assignedRoleIds = $canManageAdminAccess
            ? $user->adminRoles()->pluck('admin_roles.id')->map(static fn ($id): int => (int) $id)->all()
            : [];
        $isFederated = LdapIdentity::query()->where('user_id', $user->id)->exists()
            || UserThird::query()->where('user_id', $user->id)->exists();
        $hasActiveTotp = $user->hasActiveTotp();

        return view('admin.users.edit', compact(
            'user',
            'groups',
            'adminRoles',
            'assignedRoleIds',
            'canEdit',
            'canManageAdminAccess',
            'isFederated',
            'hasActiveTotp',
        ));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->scope->authorizeUser($request->user(), $user, 'users.edit');
        $this->authorizePrivilegedAccountManagement($request, $user);
        $canManageAdminAccess = (bool) $request->user()->is_admin;
        $hasActiveTotp = $user->hasActiveTotp();

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'is_admin' => [Rule::prohibitedIf(! $canManageAdminAccess), 'nullable', 'boolean'],
            'status' => ['required', 'integer', Rule::in([User::STATUS_NORMAL, User::STATUS_DISABLED, User::STATUS_UNVERIFIED])],
            'force_sso' => ['nullable', 'boolean'],
            'login_verify' => $hasActiveTotp
                ? ['missing']
                : ['required', Rule::in([User::LOGIN_VERIFY_OFF, User::LOGIN_VERIFY_EMAIL])],
            'two_factor_enabled' => ['missing'],
            'two_factor_secret' => ['missing'],
            'two_factor_confirmed_at' => ['missing'],
            'two_factor_recovery_codes' => ['missing'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'admin_role_ids' => [Rule::prohibitedIf(! $canManageAdminAccess), 'nullable', 'string'],
        ]);

        $data['force_sso'] = (bool) ($data['force_sso'] ?? false);
        $data['group_id'] = $data['group_id'] ?? null;
        unset(
            $data['admin_role_ids'],
            $data['two_factor_enabled'],
            $data['two_factor_secret'],
            $data['two_factor_confirmed_at'],
            $data['two_factor_recovery_codes'],
        );

        if (! $this->scope->isUnrestricted($request->user(), 'users.edit')) {
            if ($data['group_id'] === null) {
                abort(403, 'Scoped user managers cannot move users outside their assigned groups.');
            }
            $this->scope->authorizeUserGroup($request->user(), (int) $data['group_id'], 'users.edit');
        }

        if ($canManageAdminAccess) {
            $data['is_admin'] = (bool) ($data['is_admin'] ?? false);
        } else {
            unset($data['is_admin']);
        }

        $user = DB::transaction(function () use ($user, $data): User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->hasActiveTotp()) {
                // Personal TOTP enrollment is owned by the account holder. A generic account
                // update may change surrounding profile fields, but never the factor policy.
                unset($data['login_verify']);
            } else {
                // Keep every inactive state canonical even if this row predates the repair
                // migration or a concurrent enrollment request has not committed yet.
                $data['two_factor_enabled'] = false;
                $data['two_factor_secret'] = null;
                $data['two_factor_confirmed_at'] = null;
                $data['two_factor_recovery_codes'] = null;
            }

            $locked->fill($data)->save();

            return $locked;
        });
        if ($canManageAdminAccess) {
            $user->adminRoles()->sync($this->parseRoleIds($request->input('admin_role_ids')));
        }

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
        $this->scope->authorizeUser($request->user(), $user, 'users.edit');
        $this->authorizePrivilegedAccountManagement($request, $user);

        $data = $request->validate([
            'password' => AccountPasswordPolicy::rules(),
        ]);

        $selfReset = (int) $request->user()->id === (int) $user->id;
        $this->credentials->replacePassword($user, $data['password']);

        if ($selfReset) {
            Auth::logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['redirect' => route('admin.login')]);
        }

        return response()->json([]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->scope->authorizeUser($request->user(), $user, 'users.edit');
        $this->authorizePrivilegedAccountManagement($request, $user);

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

    /**
     * There is no delegated-role hierarchy that can safely compare two administrators.
     * Until one exists, only a full administrator may modify another account that has
     * full or delegated console authority. Delegated user managers may still manage every
     * ordinary account.
     */
    private function authorizePrivilegedAccountManagement(Request $request, User $user): void
    {
        if (! $request->user()->is_admin && ($user->is_admin || $user->adminRoles()->exists())) {
            abort(403, 'Only a full administrator may manage privileged accounts.');
        }
    }
}
