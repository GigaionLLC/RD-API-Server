<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * TOTP two-factor authentication for the admin console. Reuses the same secret/columns as
 * the client login 2FA (TwoFactorService), so enabling it here also protects this account's
 * client login. The post-password challenge step (challenge/verifyChallenge) runs while the
 * user is NOT yet authenticated — it is gated by a one-time session marker, not `auth`.
 */
class TwoFactorController extends Controller
{
    /** Session key carrying the candidate secret during enrollment. */
    private const SETUP_KEY = '2fa.setup_secret';

    /** Session keys carrying the deferred login between password and the second factor. */
    private const PENDING_USER = '2fa.user';

    private const PENDING_REMEMBER = '2fa.remember';

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    // --- Self-service management (authenticated) ----------------------------------------

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $setupSecret = $request->session()->get(self::SETUP_KEY);
        $uri = is_string($setupSecret)
            ? $this->twoFactor->provisioningUri($setupSecret, (string) $user->username)
            : null;

        return view('admin.two_factor.show', [
            'enabled' => (bool) $user->two_factor_enabled,
            'setupSecret' => is_string($setupSecret) ? $setupSecret : null,
            'setupUri' => $uri,
            'recoveryCodes' => $request->session()->get('2fa.recovery_codes'),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_enabled) {
            return redirect()->route('admin.2fa.show');
        }

        // Stash a candidate secret in the session; it only becomes the user's once a code confirms it.
        $request->session()->put(self::SETUP_KEY, $this->twoFactor->generateSecret());

        return redirect()->route('admin.2fa.show');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();
        $secret = $request->session()->get(self::SETUP_KEY);

        if (! is_string($secret) || ! $this->twoFactor->verifyCode($secret, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is incorrect or expired. Try again.']);
        }

        $recovery = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recovery,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ])->save();

        $request->session()->forget(self::SETUP_KEY);

        // Surface the recovery codes exactly once.
        return redirect()->route('admin.2fa.show')->with('2fa.recovery_codes', $recovery);
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'login_verify' => User::LOGIN_VERIFY_OFF,
        ])->save();

        $request->session()->forget(self::SETUP_KEY);

        return redirect()->route('admin.2fa.show')->with('status', 'Two-factor authentication disabled.');
    }

    // --- Post-password challenge (NOT authenticated; session-marker gated) ---------------

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::PENDING_USER)) {
            return redirect()->route('admin.login');
        }

        return view('admin.two_factor.challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $userId = $request->session()->get(self::PENDING_USER);
        $user = is_scalar($userId) ? User::find($userId) : null;

        if (! $user) {
            return redirect()->route('admin.login');
        }

        $throttleKey = '2fa-challenge:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors(['code' => "Too many attempts. Try again in {$seconds} seconds."]);
        }

        $code = (string) $request->input('code');
        $ok = $this->twoFactor->verifyTotp($user, $code)
            || $this->twoFactor->verifyRecoveryCode($user, $code);

        if (! $ok) {
            RateLimiter::hit($throttleKey);

            return back()->withErrors(['code' => 'Invalid authentication code.']);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) $request->session()->get(self::PENDING_REMEMBER, false);
        $request->session()->forget([self::PENDING_USER, self::PENDING_REMEMBER]);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Called by Admin\AuthController after a correct password to defer login until the second
     * factor is supplied. Logs the just-authenticated user back out and stashes the pending
     * login in the session.
     */
    public static function startChallenge(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::logout();
        $request->session()->put(self::PENDING_USER, $user->id);
        $request->session()->put(self::PENDING_REMEMBER, $remember);

        return redirect()->route('admin.2fa.challenge');
    }
}
