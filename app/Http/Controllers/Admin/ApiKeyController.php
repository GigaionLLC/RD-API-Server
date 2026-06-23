<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manage scoped API keys for the admin REST API (/api/v1). The plaintext secret is shown
 * exactly once, on creation.
 */
class ApiKeyController extends Controller
{
    public function index(): View
    {
        $keys = ApiKey::with('user:id,username')->orderByDesc('id')->get();

        return view('admin.api_keys.index', ['keys' => $keys, 'scopeList' => ApiKey::SCOPES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(array_keys(ApiKey::SCOPES))],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        [$plain, $prefix, $hash] = ApiKey::generateSecret();

        ApiKey::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => array_values($data['scopes']),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return back()
            ->with('new_api_key', $plain)
            ->with('status', 'API key created — copy it now; it will not be shown again.');
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();

        return back()->with('status', 'API key revoked.');
    }
}
