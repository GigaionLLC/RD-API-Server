<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LdapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Session-based authentication for the admin console (separate from the client
 * bearer-token auth used by /api/*).
 */
class AuthController extends Controller
{
    public function __construct(private readonly LdapService $ldap) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
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
                $user = $this->ldap->syncUser($attrs);
                Auth::login($user, $request->boolean('remember'));
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

        if (! $authenticated && ! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'Invalid username or password.']);
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
