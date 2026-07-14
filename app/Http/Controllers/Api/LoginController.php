<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthToken;
use App\Models\User;
use App\Services\LdapService;
use App\Services\OauthService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Account login / logout / current-user endpoints for the RustDesk client
 * (docs/modernization/02-client-api-contract.md §3, §4).
 */
class LoginController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly OauthService $oauth,
        private readonly LdapService $ldap,
    ) {}

    /**
     * GET /api/login-options
     * The client reads the list of available SSO providers (Go LoginOptions parity). The
     * response is an array of strings:
     *   - "common-oidc/<json>" — one entry, json is an array of {"name":"<op>"} descriptors
     *   - "oidc/<op>"          — one entry per enabled provider
     * Returns an empty array when no providers are enabled (backward-compatible).
     */
    public function loginOptions(): JsonResponse
    {
        $ops = $this->oauth->enabledProviderKeys();

        if ($ops === []) {
            return response()->json([]);
        }

        $descriptors = array_map(static fn (string $op): array => ['name' => $op], $ops);

        $options = ['common-oidc/'.json_encode($descriptors, JSON_UNESCAPED_SLASHES)];
        foreach ($ops as $op) {
            $options[] = 'oidc/'.$op;
        }

        return response()->json($options);
    }

    /**
     * POST /api/login
     * First call: account + password. Negotiates 2FA per the contract:
     *   - login_verify == 'totp'  → return a bound email_check/tfa_check challenge
     *   - login_verify == 'email' → mail a code and return a bound email_check challenge
     * Second call (type == 'email_code'): verify the bound email/TOTP challenge, then issue.
     */
    public function login(Request $request): JsonResponse
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $type = (string) $request->input('type', '');

        if ($username === '') {
            return response()->json(['error' => 'Username is required']);
        }

        // LDAP first-factor: on a credentials submission (not the email_code second step),
        // try LDAP before any local lookup. On success the user is synced locally and we skip
        // the local password check; on failure we fall through to the unchanged local path.
        $ldapAuthenticated = false;
        if ($type !== 'email_code' && $this->ldap->enabled()) {
            $attrs = $this->ldap->authenticate($username, $password);
            if ($attrs !== null) {
                $this->ldap->syncUser($attrs);
                $ldapAuthenticated = true;
            }
        }

        /** @var User|null $user */
        $user = User::where('username', $username)
            ->orWhere('email', $username)
            ->first();

        if (! $user) {
            return response()->json(['error' => 'Invalid username or password']);
        }

        // Reject non-active accounts with a status-specific message before any token issuance.
        if ($user->status === User::STATUS_DISABLED) {
            return response()->json(['error' => 'Account disabled']);
        }
        if ($user->status === User::STATUS_UNVERIFIED) {
            return response()->json(['error' => 'Account not verified']);
        }
        if (! $user->isActive()) {
            return response()->json(['error' => 'This account is disabled']);
        }

        // SSO-only accounts may not authenticate with a local password. The LDAP path (which
        // sets $ldapAuthenticated) and the OIDC flow are unaffected — only block the local
        // password submission (a credentials first-factor that did not pass LDAP).
        if ($user->force_sso && ! $ldapAuthenticated && $type !== 'email_code') {
            return response()->json(['error' => 'This account must sign in via SSO']);
        }

        $rustdeskId = (string) $request->input('id', '');
        $uuid = (string) $request->input('uuid', '');

        // --- Second-factor submission ---------------------------------------------------
        if ($type === 'email_code') {
            $verificationCode = (string) ($request->input('verificationCode') ?? $request->input('code') ?? '');
            $secret = (string) $request->input('secret', '');

            $verified = false;
            if ($user->login_verify === User::LOGIN_VERIFY_EMAIL) {
                $verified = $this->twoFactor->verifyEmailCode(
                    $user,
                    $rustdeskId,
                    $uuid,
                    $secret,
                    $verificationCode,
                );
            } elseif ($user->login_verify === User::LOGIN_VERIFY_TOTP) {
                // Stock Flutter sends both fields with the same code. Requiring that exact
                // shape avoids ambiguous parameter handling before consuming the challenge.
                $tfaCode = (string) $request->input('tfaCode', '');
                $verified = $verificationCode !== ''
                    && $tfaCode !== ''
                    && hash_equals($verificationCode, $tfaCode)
                    && $this->twoFactor->verifyTotpChallenge(
                        $user,
                        $rustdeskId,
                        $uuid,
                        $secret,
                        $tfaCode,
                    );
            }

            if (! $verified) {
                return response()->json(['error' => 'Wrong or expired verification code']);
            }

            return response()->json($this->authBody($user, $request));
        }

        // First-factor: verify the password. LDAP-verified logins skip the local hash check
        // (LDAP users authenticate against the directory, not the local password).
        if (! $ldapAuthenticated && ($password === '' || ! Hash::check($password, $user->password))) {
            return response()->json(['error' => 'Invalid username or password']);
        }

        // TOTP submitted alongside credentials (client may send tfaCode immediately).
        $tfaCode = (string) ($request->input('tfaCode') ?? '');

        // --- 2FA negotiation -------------------------------------------------------------
        if ($user->login_verify === User::LOGIN_VERIFY_TOTP) {
            // Preserve the one-request custom-client path when password and TOTP arrive
            // together. The stock Flutter client instead uses the bound second step below.
            if ($tfaCode !== '') {
                if (! $this->twoFactor->verifyTotp($user, $tfaCode)) {
                    return response()->json(['error' => 'Wrong 2FA code']);
                }

                return response()->json($this->authBody($user, $request));
            }

            if ($rustdeskId === '' || $uuid === ''
                || mb_strlen($rustdeskId) > 255 || mb_strlen($uuid) > 255) {
                return response()->json(['error' => 'Device identity is required for two-factor verification']);
            }

            $challengeSecret = $this->twoFactor->issueTotpChallenge($user, $uuid, $rustdeskId);

            // Flutter enters the verification dialog only for type=email_check and selects
            // its TOTP variant via tfa_type=tfa_check. It echoes the opaque challenge secret.
            return response()->json([
                'type' => 'email_check',
                'tfa_type' => 'tfa_check',
                'secret' => $challengeSecret,
                'user' => $this->userPayload($user),
            ]);
        }

        if ($user->login_verify === User::LOGIN_VERIFY_EMAIL) {
            if ($rustdeskId === '' || $uuid === ''
                || mb_strlen($rustdeskId) > 255 || mb_strlen($uuid) > 255) {
                return response()->json(['error' => 'Device identity is required for email verification']);
            }
            if (empty($user->email)) {
                return response()->json(['error' => 'No email address is configured for this account']);
            }

            $challenge = $this->twoFactor->issueEmailCode($user, $uuid, $rustdeskId);

            Mail::raw(
                "Your RustDesk verification code is: {$challenge['code']}\n\nIt expires in 5 minutes.",
                function ($message) use ($user): void {
                    $message->to($user->email)->subject('RustDesk verification code');
                }
            );

            return response()->json([
                // The stock Flutter client switches on type=email_check and needs user.name
                // to populate the username on its second request.
                'type' => 'email_check',
                'tfa_type' => 'email_check',
                'secret' => $challenge['secret'],
                'user' => $this->userPayload($user),
            ]);
        }

        // No second factor required.
        return response()->json($this->authBody($user, $request));
    }

    /**
     * POST /api/logout — revoke the presented bearer token.
     */
    public function logout(Request $request): JsonResponse
    {
        $authToken = $request->attributes->get('auth_token');

        if ($authToken instanceof AuthToken) {
            $authToken->forceFill(['status' => AuthToken::STATUS_REVOKED])->save();
        }

        return response()->json((object) []);
    }

    /**
     * POST /api/currentUser and GET /api/user/info — return the current UserPayload.
     */
    public function currentUser(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->userPayload($user));
    }

    /**
     * Issue a bearer AuthToken and assemble the AuthBody (contract §3b).
     *
     * @return array<string, mixed>
     */
    private function authBody(User $user, Request $request): array
    {
        $token = $this->issueToken($user, $request);

        return [
            'access_token' => $token->token,
            'type' => 'access_token',
            'tfa_type' => '',
            'secret' => '',
            'user' => $this->userPayload($user),
        ];
    }

    /**
     * Persist a fresh AuthToken for this login.
     */
    private function issueToken(User $user, Request $request): AuthToken
    {
        $device = $request->input('deviceInfo', []);
        $device = is_array($device) ? $device : [];

        return AuthToken::create([
            'user_id' => $user->id,
            'rustdesk_id' => (string) $request->input('id', '') ?: null,
            'uuid' => (string) $request->input('uuid', '') ?: null,
            'device_os' => (string) ($device['os'] ?? '') ?: null,
            'device_type' => (string) ($device['type'] ?? '') ?: null,
            'device_name' => (string) ($device['name'] ?? '') ?: null,
            'token' => Str::random(60),
            'is_admin' => (bool) $user->is_admin,
            'status' => AuthToken::STATUS_ACTIVE,
            'expires_at' => now()->addDays((int) config('rustdesk.token_ttl_days', 90)),
            'last_used_at' => now(),
        ]);
    }

    /**
     * Build the UserPayload sub-object (contract §3b).
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'name' => (string) $user->username,
            'display_name' => (string) ($user->display_name ?? ''),
            'avatar' => (string) ($user->avatar ?? ''),
            'email' => (string) ($user->email ?? ''),
            'note' => (string) ($user->note ?? ''),
            'status' => (int) $user->status,
            'is_admin' => (bool) $user->is_admin,
            'third_auth_type' => '',
            'info' => [
                'email_verification' => $user->login_verify === User::LOGIN_VERIFY_EMAIL,
                'email_alarm_notification' => (bool) $user->email_alarm_notification,
                'login_device_whitelist' => [],
            ],
        ];
    }
}
