<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin REST API (v1) — users. Read needs `users.read`; provisioning / updating accounts
 * needs `users.write`. Passwords are hashed automatically by the model cast.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $users = User::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('username', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('username')
            ->paginate($perPage, ['id', 'username', 'email', 'display_name', 'is_admin', 'status', 'last_login_at']);

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
            'is_admin' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'integer', Rule::in([User::STATUS_DISABLED, User::STATUS_NORMAL])],
        ]);

        $user = User::create([
            'username' => $data['username'],
            'password' => $data['password'],
            'email' => $data['email'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'is_admin' => $data['is_admin'] ?? false,
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
        $data = $request->validate([
            'username' => ['sometimes', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_admin' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'integer', Rule::in([User::STATUS_DISABLED, User::STATUS_NORMAL])],
        ]);

        $user->fill($data)->save();

        return response()->json(['data' => $this->shape($user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(User $user): array
    {
        return $user->only(['id', 'username', 'email', 'display_name', 'is_admin', 'status']);
    }
}
