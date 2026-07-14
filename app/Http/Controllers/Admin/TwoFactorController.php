<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\AccountPasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
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

    private const PENDING_PASSWORD = '2fa.password_fingerprint';

    private const PENDING_EXPIRES_AT = '2fa.expires_at';

    private const CHALLENGE_LIFETIME_SECONDS = 300;

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
        $request->validate([
            'password' => ['required', 'string', 'max:'.AccountPasswordPolicy::MAX_LENGTH],
        ]);

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
        if (! $this->pendingUser($request)) {
            return redirect()->route('admin.login');
        }

        return view('admin.two_factor.challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), ['code' => ['required', 'string']]);
        if ($validator->fails()) {
            self::clearPendingChallenge($request);

            return redirect()->route('admin.login')->withErrors($validator);
        }

        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('admin.login');
        }

        $throttleKey = '2fa-challenge:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            self::clearPendingChallenge($request);

            return redirect()->route('admin.login')
                ->withErrors(['username' => "Too many two-factor attempts. Try again in {$seconds} seconds."]);
        }

        $code = (string) $request->input('code');
        $ok = $this->twoFactor->verifyTotp($user, $code)
            || $this->twoFactor->verifyRecoveryCode($user, $code);

        if (! $ok) {
            RateLimiter::hit($throttleKey);
            self::clearPendingChallenge($request);

            return redirect()->route('admin.login')
                ->withErrors(['code' => 'Invalid authentication code. Sign in again to retry.']);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) $request->session()->get(self::PENDING_REMEMBER, false);
        self::clearPendingChallenge($request);

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
        $request->session()->regenerate();
        self::clearPendingChallenge($request);
        $request->session()->put([
            self::PENDING_USER => $user->getAuthIdentifier(),
            self::PENDING_REMEMBER => $remember,
            self::PENDING_PASSWORD => self::passwordFingerprint($user),
            self::PENDING_EXPIRES_AT => now()->getTimestamp() + self::CHALLENGE_LIFETIME_SECONDS,
        ]);

        return redirect()->route('admin.2fa.challenge');
    }

    /**
     * Resolve a still-valid deferred login. The HMAC deliberately binds the challenge to the
     * password hash and credential version accepted during primary authentication without
     * storing either value in the session. Password replacement or explicit credential
     * revocation therefore makes the pending challenge unusable.
     */
    private function pendingUser(Request $request): ?User
    {
        $userId = $request->session()->get(self::PENDING_USER);
        $fingerprint = $request->session()->get(self::PENDING_PASSWORD);
        $expiresAt = $request->session()->get(self::PENDING_EXPIRES_AT);

        $user = is_scalar($userId) ? User::find($userId) : null;
        $valid = $user instanceof User
            && is_string($fingerprint)
            && is_numeric($expiresAt)
            && (int) $expiresAt > now()->getTimestamp()
            && $user->isActive()
            && ($user->is_admin || $user->adminRoles()->exists())
            && $user->two_factor_enabled
            && is_string($user->two_factor_secret)
            && $user->two_factor_secret !== ''
            && hash_equals(self::passwordFingerprint($user), $fingerprint);

        if (! $valid) {
            self::clearPendingChallenge($request);

            return null;
        }

        return $user;
    }

    private static function passwordFingerprint(User $user): string
    {
        return hash_hmac(
            'sha256',
            $user->getAuthIdentifier().'|'.max(1, (int) $user->credential_version).'|'.$user->getAuthPassword(),
            (string) config('app.key'),
        );
    }

    private static function clearPendingChallenge(Request $request): void
    {
        $request->session()->forget([
            self::PENDING_USER,
            self::PENDING_REMEMBER,
            self::PENDING_PASSWORD,
            self::PENDING_EXPIRES_AT,
        ]);
    }
}
