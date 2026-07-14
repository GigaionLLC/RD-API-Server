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
    public function index(Request $request): View
    {
        $user = $request->user();
        $keys = ApiKey::query()
            ->with('user:id,username')
            ->when(! $user->is_admin, fn ($query) => $query->where('user_id', $user->id))
            ->orderByDesc('id')
            ->get();

        return view('admin.api_keys.index', [
            'keys' => $keys,
            'scopeList' => ApiKey::scopesAllowedFor($user),
            'canEdit' => $user->hasPermission('api_keys.edit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $allowedScopes = array_keys(ApiKey::scopesAllowedFor($request->user()));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in($allowedScopes)],
            'allowed_ips' => ['nullable', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        [$plain, $prefix, $hash] = ApiKey::generateSecret();

        ApiKey::create([
            'user_id' => $request->user()->id,
            'credential_version' => max(1, (int) $request->user()->credential_version),
            'name' => $data['name'],
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => array_values($data['scopes']),
            'allowed_ips' => $this->normalizeIps($data['allowed_ips'] ?? null),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return back()
            ->with('new_api_key', $plain)
            ->with('status', 'API key created — copy it now; it will not be shown again.');
    }

    /**
     * Rotate a key's secret in place (same name/scopes/IP rules). The old secret stops working
     * immediately; the new one is shown once.
     */
    public function rotate(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeKeyManagement($request, $apiKey);

        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        $ownerVersion = max(1, (int) $apiKey->user()->value('credential_version'));

        $apiKey->forceFill([
            'token_hash' => $hash,
            'prefix' => $prefix,
            'credential_version' => $ownerVersion,
            'last_used_at' => null,
            'last_used_ip' => null,
        ])->save();

        return back()
            ->with('new_api_key', $plain)
            ->with('status', "Key '{$apiKey->name}' rotated — the old secret no longer works.");
    }

    /**
     * Normalise a comma/space/newline-separated IP list to a trimmed comma list, or null.
     */
    private function normalizeIps(?string $raw): ?string
    {
        $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $raw) ?: [])));

        return $ips === [] ? null : implode(',', $ips);
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $this->authorizeKeyManagement($request, $apiKey);

        $apiKey->delete();

        return back()->with('status', 'API key revoked.');
    }

    /**
     * Full administrators may manage every key. Delegated API-key managers may only rotate
     * or revoke keys they own, preventing route-model binding from becoming an IDOR.
     */
    private function authorizeKeyManagement(Request $request, ApiKey $apiKey): void
    {
        $user = $request->user();

        if (! $user->is_admin && (int) $apiKey->user_id !== (int) $user->id) {
            abort(403, 'You may only manage API keys that you own.');
        }
    }
}
