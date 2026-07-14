<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountCredentialService;
use App\Services\AdminScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin REST API (v1) — users. Read needs `users.read`; provisioning / updating accounts
 * needs `users.write`. Passwords are hashed automatically by the model cast.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly AdminScopeService $scope,
        private readonly AccountCredentialService $credentials,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $users = $this->scope->scopeUsers(User::query(), $request->user(), 'users.view')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('username', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('username')
            ->paginate($perPage, [
                'id', 'username', 'email', 'display_name', 'is_admin', 'status', 'group_id', 'last_login_at',
            ]);

        return response()->json($users);
    }

    /**
     * POST /api/v1/users — provision an account.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
            'email' => ['nullable', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            // Administrative privilege is intentionally outside this API scope. Keeping it
            // out of the write contract prevents a users.write key from minting a console
            // superuser, even when the key belongs to a full administrator.
            'is_admin' => ['prohibited'],
            'status' => ['sometimes', 'integer', Rule::in([User::STATUS_DISABLED, User::STATUS_NORMAL])],
        ]);

        if (! $this->scope->isUnrestricted($request->user(), 'users.edit')) {
            if (($data['group_id'] ?? null) === null) {
                return response()->json(['error' => 'Scoped API keys must create users inside an assigned group'], 403);
            }
            $this->scope->authorizeUserGroup($request->user(), (int) $data['group_id'], 'users.edit');
        }

        $user = User::create([
            'username' => $data['username'],
            'password' => $data['password'],
            'email' => $data['email'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'group_id' => $data['group_id'] ?? null,
            'is_admin' => false,
            'status' => $data['status'] ?? User::STATUS_NORMAL,
        ]);

        return response()->json(['data' => $this->shape($user)], 201);
    }

    /**
     * PUT /api/v1/users/{user} — update an account. Only supplied fields change; a password is
     * re-hashed only when provided.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->scope->authorizeUser($request->user(), $user, 'users.edit');
        // API automation may provision ordinary accounts, but it must not take over or
        // disable any account that can administer the console. Full administrators retain
        // that capability through the protected console instead.
        if ($user->is_admin || $user->adminRoles()->exists()) {
            return response()->json(['error' => 'Privileged accounts cannot be modified through the API'], 403);
        }

        $data = $request->validate([
            'username' => ['sometimes', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'is_admin' => ['prohibited'],
            'status' => ['sometimes', 'integer', Rule::in([User::STATUS_DISABLED, User::STATUS_NORMAL])],
        ]);

        if (array_key_exists('group_id', $data) && ! $this->scope->isUnrestricted($request->user(), 'users.edit')) {
            if ($data['group_id'] === null) {
                return response()->json(['error' => 'Scoped API keys cannot move users outside assigned groups'], 403);
            }
            $this->scope->authorizeUserGroup($request->user(), (int) $data['group_id'], 'users.edit');
        }

        $previousEmail = $user->email;
        $password = array_key_exists('password', $data) ? (string) $data['password'] : null;
        unset($data['password']);

        if ($password !== null) {
            $user = DB::transaction(function () use ($user, $data, $password, $previousEmail): User {
                $user->fill($data)->save();

                return $this->credentials->replacePassword($user, $password, [$previousEmail]);
            });
        } else {
            $user->fill($data)->save();
        }

        return response()->json(['data' => $this->shape($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(User $user): array
    {
        return $user->only(['id', 'username', 'email', 'display_name', 'is_admin', 'status', 'group_id']);
    }
}
