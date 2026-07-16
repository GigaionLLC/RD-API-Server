<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\RecentAdminAuthentication;
use App\Support\TotpSecretFormat;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use JsonException;

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

    private const SETUP_VERSION = 1;

    /** Session keys carrying the deferred login between password and the second factor. */
    private const PENDING_USER = '2fa.user';

    private const PENDING_REMEMBER = '2fa.remember';

    private const PENDING_PASSWORD = '2fa.password_fingerprint';

    private const PENDING_EXPIRES_AT = '2fa.expires_at';

    private const CHALLENGE_LIFETIME_SECONDS = 300;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly RecentAdminAuthentication $recentAuthentication,
    ) {}

    // --- Self-service management (authenticated) ----------------------------------------

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $recentlyAuthenticated = $this->recentAuthentication->isValid($request->session(), $user);
        $factorRecentlyVerified = $recentlyAuthenticated
            && $this->recentAuthentication->factorWasVerified($request->session(), $user);
        if (! $recentlyAuthenticated || $user->two_factor_enabled) {
            $request->session()->forget(self::SETUP_KEY);
        }

        $setupSecret = $recentlyAuthenticated && ! $user->two_factor_enabled
            ? $this->setupSecret($request, $user)
            : null;
        $uri = $setupSecret !== null
            ? $this->twoFactor->provisioningUri($setupSecret, (string) $user->username)
            : null;

        // Retire plaintext recovery-code flash data created by older releases. New codes are
        // rendered once in a no-store response and never persisted in the session.
        $request->session()->forget('2fa.recovery_codes');

        return response()->view('admin.two_factor.show', [
            'enabled' => (bool) $user->two_factor_enabled,
            'setupSecret' => $setupSecret,
            'setupUri' => $uri,
            'recoveryCodes' => null,
            'recentlyAuthenticated' => $recentlyAuthenticated,
            'factorRecentlyVerified' => $factorRecentlyVerified,
            'recentAuthenticationWindow' => $this->recentAuthenticationWindow(),
        ])->withHeaders(self::sensitiveResponseHeaders());
    }

    public function enable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_enabled) {
            $request->session()->forget(self::SETUP_KEY);

            return redirect()->route('admin.2fa.show');
        }

        $nonce = $this->recentAuthentication->nonce($request->session(), $user);
        if ($nonce === null) {
            $request->session()->forget(self::SETUP_KEY);

            return $this->recentAuthenticationRequired();
        }

        $createdAt = now()->getTimestamp();
        $payload = json_encode([
            'version' => self::SETUP_VERSION,
            'user_id' => (string) $user->getAuthIdentifier(),
            'credential_version' => max(1, (int) $user->credential_version),
            'authentication_nonce' => $nonce,
            'created_at' => $createdAt,
            'expires_at' => $createdAt + $this->recentAuthentication->timeoutSeconds(),
            'secret' => $this->twoFactor->generateSecret(),
        ], JSON_THROW_ON_ERROR);

        // The candidate only becomes the user's secret after a valid code confirms it. Encrypt
        // the complete account-bound state because database sessions are not encrypted globally.
        $request->session()->put(self::SETUP_KEY, Crypt::encryptString($payload));

        return redirect()->route('admin.2fa.show');
    }

    public function confirm(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $this->recentAuthentication->isValid($request->session(), $user)) {
            $request->session()->forget(self::SETUP_KEY);

            return $this->recentAuthenticationRequired();
        }

        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $secret = $this->setupSecret($request, $user);
        if ($secret === null) {
            return redirect()->route('admin.2fa.show')
                ->with('warning', 'Two-factor setup expired or no longer matches this account. Restart setup to continue.');
        }

        $throttleKey = $this->managementThrottleKey('confirm', $user, $request);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors(['code' => "Too many setup attempts. Try again in {$seconds} seconds."]);
        }

        if (! $this->twoFactor->verifyCode($secret, (string) $request->input('code'))) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors(['code' => 'That code is incorrect or expired. Try again.']);
        }

        RateLimiter::clear($throttleKey);

        /** @var array{status:string,recovery?:list<string>} $outcome */
        $outcome = DB::transaction(function () use ($request, $user, $secret): array {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $this->recentAuthentication->isValid($request->session(), $locked)) {
                return ['status' => 'stale'];
            }

            if ($locked->two_factor_enabled) {
                return ['status' => 'already_enabled'];
            }

            $recovery = $this->twoFactor->generateRecoveryCodes();
            $locked->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_enabled' => true,
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => $this->twoFactor->protectRecoveryCodes($recovery),
                'login_verify' => User::LOGIN_VERIFY_TOTP,
            ])->save();

            return ['status' => 'enabled', 'recovery' => $recovery];
        });

        if ($outcome['status'] === 'stale') {
            $request->session()->forget(self::SETUP_KEY);

            return $this->recentAuthenticationRequired();
        }

        if ($outcome['status'] === 'already_enabled') {
            $request->session()->forget(self::SETUP_KEY);

            return redirect()->route('admin.2fa.show')
                ->with('status', 'Two-factor authentication is already enabled.');
        }

        /** @var list<string> $recovery */
        $recovery = $outcome['recovery'];

        $request->session()->forget([self::SETUP_KEY, '2fa.recovery_codes']);
        $this->recentAuthentication->clear($request->session());

        // Surface plaintext recovery codes exactly once without writing them to the database
        // session or allowing a browser/proxy cache to retain the response.
        return response()->view('admin.two_factor.show', [
            'enabled' => true,
            'setupSecret' => null,
            'setupUri' => null,
            'recoveryCodes' => $recovery,
            'recentlyAuthenticated' => false,
            'factorRecentlyVerified' => false,
            'recentAuthenticationWindow' => $this->recentAuthenticationWindow(),
        ])->withHeaders(self::sensitiveResponseHeaders());
    }

    public function disable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->recentAuthentication->isValid($request->session(), $user)) {
            return $this->recentAuthenticationRequired();
        }

        $factorRecentlyVerified = $this->recentAuthentication
            ->factorWasVerified($request->session(), $user);
        $request->validate([
            'code' => [$factorRecentlyVerified ? 'nullable' : 'required', 'string', 'max:32'],
        ]);

        $throttleKey = $this->managementThrottleKey('disable', $user, $request);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors(['code' => "Too many removal attempts. Try again in {$seconds} seconds."]);
        }

        $code = (string) $request->input('code');
        $outcome = DB::transaction(function () use ($request, $user, $code): string {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $this->recentAuthentication->isValid($request->session(), $locked)) {
                return 'stale';
            }

            if (! $locked->two_factor_enabled) {
                return 'already_disabled';
            }

            $validCode = $this->recentAuthentication
                ->factorWasVerified($request->session(), $locked)
                || $this->twoFactor->verifyTotp($locked, $code)
                || $this->twoFactor->verifyRecoveryCode($locked, $code);
            if (! $validCode) {
                return 'invalid';
            }

            $locked->forceFill([
                'two_factor_secret' => null,
                'two_factor_enabled' => false,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
                'login_verify' => User::LOGIN_VERIFY_OFF,
            ])->save();

            return 'disabled';
        });

        if ($outcome === 'invalid') {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors(['code' => 'That authenticator or recovery code is invalid.']);
        }

        if ($outcome === 'stale') {
            return $this->recentAuthenticationRequired();
        }

        RateLimiter::clear($throttleKey);

        $request->session()->forget(self::SETUP_KEY);
        if ($outcome === 'already_disabled') {
            return redirect()->route('admin.2fa.show');
        }

        $this->recentAuthentication->clear($request->session());

        return redirect()->route('admin.2fa.show')->with('status', 'Two-factor authentication disabled.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SETUP_KEY);

        return redirect()->route('admin.2fa.show')->with('status', 'Two-factor setup cancelled.');
    }

    public function reauthenticate(Request $request): RedirectResponse
    {
        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('url.intended', route('admin.2fa.show'));

        return redirect()->route('admin.login')
            ->with('status', 'Complete your normal sign-in to change two-factor authentication.');
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
        $ok = $this->twoFactor->verifyTotp($user, $code);
        if (! $ok) {
            $ok = $this->twoFactor->verifyRecoveryCode($user, $code);
        }

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
        $this->recentAuthentication->noteFactorVerification($request->session(), $user);

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

    /**
     * Decrypt and validate the account-, credential-, authentication-, and time-bound candidate
     * enrollment state. Unbound state from an older release is intentionally discarded.
     */
    private function setupSecret(Request $request, User $user): ?string
    {
        $stored = $request->session()->get(self::SETUP_KEY);
        if (! is_string($stored)) {
            $request->session()->forget(self::SETUP_KEY);

            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $request->session()->forget(self::SETUP_KEY);

            return null;
        }

        $authenticationNonce = $this->recentAuthentication->nonce($request->session(), $user);
        $now = now()->getTimestamp();
        $valid = is_array($decoded)
            && ($decoded['version'] ?? null) === self::SETUP_VERSION
            && is_string($decoded['user_id'] ?? null)
            && hash_equals((string) $user->getAuthIdentifier(), $decoded['user_id'])
            && ($decoded['credential_version'] ?? null) === max(1, (int) $user->credential_version)
            && is_string($decoded['authentication_nonce'] ?? null)
            && is_string($authenticationNonce)
            && hash_equals($authenticationNonce, $decoded['authentication_nonce'])
            && is_int($decoded['created_at'] ?? null)
            && $decoded['created_at'] <= $now
            && is_int($decoded['expires_at'] ?? null)
            && $decoded['expires_at'] === $decoded['created_at'] + $this->recentAuthentication->timeoutSeconds()
            && $decoded['expires_at'] >= $now
            && is_string($decoded['secret'] ?? null)
            && TotpSecretFormat::isValid($decoded['secret']);

        if (! $valid) {
            $request->session()->forget(self::SETUP_KEY);

            return null;
        }

        return $decoded['secret'];
    }

    private function managementThrottleKey(string $action, User $user, Request $request): string
    {
        return '2fa-management:'.$action.':'.$user->getAuthIdentifier().'|'.$request->ip();
    }

    private function recentAuthenticationRequired(): RedirectResponse
    {
        return redirect()->route('admin.2fa.show')
            ->with('warning', 'Sign in again to change two-factor authentication.');
    }

    private function recentAuthenticationWindow(): string
    {
        $seconds = $this->recentAuthentication->timeoutSeconds();
        if ($seconds % 60 === 0) {
            $minutes = intdiv($seconds, 60);

            return $minutes.' '.str('minute')->plural($minutes);
        }

        return $seconds.' seconds';
    }

    /**
     * @return array<string, string>
     */
    private static function sensitiveResponseHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
