<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OauthProvider;
use App\Services\OauthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * OAuth / OIDC provider management: list/create/edit/delete provider configurations the
 * RustDesk client can log in with (github, google, oidc). The client_secret is write-only —
 * it is never rendered back into the edit form.
 */
class OauthProviderController extends Controller
{
    public function index(): View
    {
        $providers = OauthProvider::orderBy('op')->paginate(20);

        return view('admin.oauth_providers.index', compact('providers'));
    }

    public function create(): View
    {
        $provider = new OauthProvider([
            'type' => OauthService::TYPE_OIDC,
            'auto_register' => false,
            'pkce_enable' => false,
            'pkce_method' => 'S256',
            'enabled' => true,
        ]);

        $types = OauthService::types();
        $presets = config('oauth_presets', []);
        $redirectUri = app(OauthService::class)->redirectUri();

        return view('admin.oauth_providers.create', compact('provider', 'types', 'presets', 'redirectUri'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProvider($request, null);

        OauthProvider::create($data);

        return redirect()
            ->route('admin.oauth-providers.index')
            ->with('status', 'OAuth provider created.');
    }

    public function edit(OauthProvider $oauthProvider): View
    {
        $types = OauthService::types();

        return view('admin.oauth_providers.edit', [
            'provider' => $oauthProvider,
            'types' => $types,
        ]);
    }

    public function update(Request $request, OauthProvider $oauthProvider): RedirectResponse
    {
        $data = $this->validateProvider($request, $oauthProvider);

        // Leave the stored secret untouched when the field is left blank on edit.
        if (($data['client_secret'] ?? '') === '') {
            unset($data['client_secret']);
        }

        $oauthProvider->fill($data)->save();

        return redirect()
            ->route('admin.oauth-providers.index')
            ->with('status', 'OAuth provider updated.');
    }

    public function destroy(OauthProvider $oauthProvider): RedirectResponse
    {
        $oauthProvider->delete();

        return redirect()
            ->route('admin.oauth-providers.index')
            ->with('status', 'OAuth provider deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProvider(Request $request, ?OauthProvider $existing): array
    {
        $secretRequired = $existing === null ? 'required' : 'nullable';

        $validated = $request->validate([
            'op' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('oauth_providers', 'op')->ignore($existing?->id),
            ],
            'type' => ['required', Rule::in(OauthService::types())],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [$secretRequired, 'string', 'max:255'],
            'scopes' => ['nullable', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'pkce_method' => ['nullable', Rule::in(['S256', 'plain'])],
        ]);

        // OIDC requires an issuer to discover endpoints.
        if ($validated['type'] === OauthService::TYPE_OIDC && empty($validated['issuer'])) {
            throw ValidationException::withMessages([
                'issuer' => 'Issuer is required for the oidc provider type.',
            ]);
        }

        $validated['auto_register'] = $request->boolean('auto_register');
        $validated['pkce_enable'] = $request->boolean('pkce_enable');
        $validated['enabled'] = $request->boolean('enabled');
        $validated['pkce_method'] = $validated['pkce_method'] ?? 'S256';

        return $validated;
    }
}
