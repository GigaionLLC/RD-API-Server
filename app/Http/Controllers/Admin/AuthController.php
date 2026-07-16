<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LdapService;
use App\Services\LocalPasswordHashService;
use App\Services\OauthService;
use App\Support\AccountPasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Session-based authentication for the admin console (separate from the client
 * bearer-token auth used by /api/*). Supports local credentials, LDAP, and interactive
 * SSO/OIDC sign-in (the same provider configs the client uses, e.g. Keycloak).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly LdapService $ldap,
        private readonly OauthService $oauth,
        private readonly LocalPasswordHashService $passwordHashes,
    ) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login', ['ssoProviders' => $this->oauth->loginProviders()]);
    }

    /**
     * Begin an interactive SSO sign-in: stash a CSRF state in the session and redirect to the
     * provider's authorization endpoint (callback returns to ssoCallback).
     */
    public function ssoRedirect(Request $request, string $op): RedirectResponse
    {
        $provider = $this->oauth->enabledProvider($op);
        if (! $provider) {
            return redirect()->route('admin.login')->withErrors(['username' => 'Unknown or disabled SSO provider.']);
        }

        $state = Str::random(40);
        $verifier = $provider->pkce_enable ? $this->oauth->pkceVerifier() : '';
        $request->session()->put('admin_sso', [
            'state' => $state,
            'op' => $op,
            'remember' => $request->boolean('remember'),
            'code_verifier' => $verifier,
        ]);

        $url = $this->oauth->webAuthorizationUrl($provider, $state, Str::random(20), $this->ssoCallbackUri($op), $verifier ?: null);
        if ($url === '') {
            return redirect()->route('admin.login')->withErrors(['username' => 'Could not start SSO (provider misconfigured).']);
        }

        return redirect()->away($url);
    }

    /**
     * Complete an interactive SSO sign-in: validate state, exchange the code, resolve the local
     * user, enforce console-access rules, and establish the session.
     */
    public function ssoCallback(Request $request, string $op): RedirectResponse
    {
        $stash = (array) $request->session()->pull('admin_sso', []);
        $state = (string) $request->query('state', '');

        if (($stash['op'] ?? null) !== $op || ($stash['state'] ?? null) === null || ! hash_equals((string) $stash['state'], $state)) {
            return redirect()->route('admin.login')->withErrors(['username' => 'SSO session expired or invalid. Try again.']);
        }

        if ($request->query('error') || ($code = (string) $request->query('code', '')) === '') {
            return redirect()->route('admin.login')->withErrors(['username' => 'SSO sign-in was cancelled or failed.']);
        }

        $provider = $this->oauth->enabledProvider($op);
        if (! $provider) {
            return redirect()->route('admin.login')->withErrors(['username' => 'Unknown or disabled SSO provider.']);
        }

        $user = $this->oauth->webResolveUser($provider, $code, $this->ssoCallbackUri($op), (string) ($stash['code_verifier'] ?? ''));
        if (! $user) {
            return redirect()->route('admin.login')->withErrors(['username' => 'No console account is linked to that identity (and auto-register is off for this provider).']);
        }

        // Console access: full admins and delegated (role-holding) admins only.
        if (! $user->is_admin && $user->adminRoles()->doesntExist()) {
            return redirect()->route('admin.login')->withErrors(['username' => 'This account is not an administrator.']);
        }
        if (! $user->isActive()) {
            return redirect()->route('admin.login')->withErrors(['username' => 'This account is disabled.']);
        }

        // SSO is the trust anchor, so local TOTP is not layered onto this path. Operators must
        // enforce any MFA requirement at the identity provider.
        Auth::login($user, (bool) ($stash['remember'] ?? false));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * The absolute console SSO callback URL for a provider (register this with the IdP).
     */
    private function ssoCallbackUri(string $op): string
    {
        return route('admin.sso.callback', ['op' => $op]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string', 'max:'.AccountPasswordPolicy::MAX_LENGTH],
        ]);

        // Brute-force protection: 5 failed attempts per account+IP per minute, then locked out.
        $throttleKey = 'admin-login:'.Str::lower($credentials['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => "Too many login attempts. Try again in {$seconds} seconds."]);
        }

        // LDAP first: on success sync the user and establish the session directly. On failure,
        // fall back to the unchanged local-credentials path.
        $authenticated = false;
        if ($this->ldap->enabled()) {
            $attrs = $this->ldap->authenticate($credentials['username'], $credentials['password']);
            if ($attrs !== null) {
                // Authenticate only the persisted provider/subject-linked user returned by LDAP.
                // The submitted username is never used to select an existing local account.
                $linkedUser = $this->ldap->syncUser($attrs);
                Auth::login($linkedUser, $request->boolean('remember'));
                $authenticated = true;
            }
        }

        // SSO-only accounts may not authenticate with the local password (LDAP, which sets
        // $authenticated above, is still allowed). Block the local-credentials attempt only.
        if (! $authenticated) {
            /** @var User|null $candidate */
            $candidate = User::where('username', $credentials['username'])
                ->orWhere('email', $credentials['username'])
                ->first();

            if ($candidate && $candidate->force_sso) {
                return back()
                    ->withInput($request->only('username'))
                    ->withErrors(['username' => 'This account must sign in via SSO.']);
            }
        }

        if (! $authenticated) {
            if (! Auth::attempt($credentials, $request->boolean('remember'))) {
                RateLimiter::hit($throttleKey);

                return back()
                    ->withInput($request->only('username'))
                    ->withErrors(['username' => 'Invalid username or password.']);
            }

            /** @var User $localUser */
            $localUser = Auth::user();
            if (! $this->passwordHashes->upgradeIfNeeded($localUser, $credentials['password'])) {
                Auth::logout();
                RateLimiter::hit($throttleKey);

                return back()
                    ->withInput($request->only('username'))
                    ->withErrors(['username' => 'Invalid username or password.']);
            }
        }

        // Credentials accepted — clear the failure counter for this account+IP.
        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = Auth::user();

        // Full admins and delegated admins (holding >=1 scoped admin role) may sign in to the
        // console; per-area access is then enforced by the permission middleware.
        if (! $user->is_admin && $user->adminRoles()->doesntExist()) {
            Auth::logout();

            return back()->withErrors(['username' => 'This account is not an administrator.']);
        }

        if ($user->status === User::STATUS_DISABLED) {
            Auth::logout();

            return back()->withErrors(['username' => 'Account disabled']);
        }

        if ($user->status === User::STATUS_UNVERIFIED) {
            Auth::logout();

            return back()->withErrors(['username' => 'Account not verified']);
        }

        if (! $user->isActive()) {
            Auth::logout();

            return back()->withErrors(['username' => 'This account is disabled.']);
        }

        // Second factor: if TOTP is enabled, defer login to the challenge step (the user is
        // logged back out and only re-authenticated once a valid code is supplied).
        if ($user->two_factor_enabled && $user->two_factor_secret) {
            return TwoFactorController::startChallenge($request, $user, $request->boolean('remember'));
        }

        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
