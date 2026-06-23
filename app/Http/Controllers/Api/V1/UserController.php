<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin REST API (v1) — users. Authenticated by a scoped API key (users.read).
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
}
