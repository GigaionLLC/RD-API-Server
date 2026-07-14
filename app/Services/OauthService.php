<?php

namespace App\Services;

use App\Models\AuthToken;
use App\Models\OauthProvider;
use App\Models\OauthSession;
use App\Models\User;
use App\Models\UserThird;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider as SocialiteProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * OAuth / OIDC device-login service for the RustDesk client poll-based flow.
 *
 * Mirrors the legacy Go implementation (service/oauth.go):
 *   - POST /api/oidc/auth     → start a pending session, hand back {code,url}
 *   - GET  /api/oauth/callback → exchange the provider code, resolve the local user,
 *                                 store the issued AuthBody against the pending session
 *   - GET  /api/oidc/auth-query → poll the pending session for the AuthBody
 *
 * The pending session is persisted in the `oauth_sessions` table (NOT the cache) for 5 minutes,
 * so the provider callback and the client's poll resolve the same session even when the API
 * runs as multiple instances / workers behind a load balancer.
 */
class OauthService
{
    public const TYPE_GITHUB = 'github';

    public const TYPE_GOOGLE = 'google';

    public const TYPE_OIDC = 'oidc';

    /** Default OIDC scopes when a provider leaves the scopes column empty. */
    public const DEFAULT_SCOPES = 'openid,profile,email';

    public const CACHE_TTL = 300;

    private const OIDC_CONNECT_TIMEOUT_SECONDS = 5;

    private const OIDC_TIMEOUT_SECONDS = 10;

    private const POLL_RETRY_WINDOW_SECONDS = 15;

    public function __construct(private readonly OidcDestinationGuard $oidcDestinationGuard) {}

    /**
     * The list of supported provider types.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::TYPE_GITHUB, self::TYPE_GOOGLE, self::TYPE_OIDC];
    }

    /**
     * Begin a device-login flow for the given provider key (`op`).
     *
     * Returns [code, url] on success, or ['', ''] when the provider is unknown / disabled.
     * The pending session is stored in the cache carrying op/id/uuid + device info.
     *
     * @param  array<string, mixed>  $deviceInfo
     * @return array{0: string, 1: string}
     */
    public function beginAuth(string $op, string $id, string $uuid, array $deviceInfo): array
    {
        $id = trim($id);
        $uuid = trim($uuid);
        if ($id === '' || strlen($id) > 255 || $uuid === '' || strlen($uuid) > 255) {
            return ['', ''];
        }

        $provider = $this->enabledProvider($op);
        if (! $provider) {
            return ['', ''];
        }

        // State doubles as the polling code the client echoes back.
        $code = Str::random(32);
        $nonce = Str::random(16);

        // PKCE: when the provider enables it, generate a verifier now, send its challenge on the
        // authorize request, and keep the verifier in the pending session for the token exchange.
        $verifier = $provider->pkce_enable ? $this->pkceVerifier() : '';

        $url = $this->authorizationUrl($provider, $code, $nonce, null, $verifier ?: null);
        if ($url === '') {
            return ['', ''];
        }

        // Opportunistically clear expired sessions, then persist this one in the DB so the
        // provider callback and the client's poll resolve it even across multiple API instances.
        OauthSession::where('expires_at', '<', now())->delete();

        OauthSession::create([
            'code' => $code,
            'op' => $provider->op,
            'rustdesk_id' => $id,
            'uuid' => $uuid,
            'nonce' => $nonce,
            'code_verifier' => $verifier,
            'device_os' => (string) ($deviceInfo['os'] ?? ''),
            'device_type' => (string) ($deviceInfo['type'] ?? ''),
            'device_name' => (string) ($deviceInfo['name'] ?? ''),
            'auth_body' => null,
            'expires_at' => now()->addSeconds(self::CACHE_TTL),
        ]);

        return [$code, $url];
    }

    /**
     * Build the provider authorization URL with redirect_uri pointing at our callback
     * and state = the polling code.
     */
    private function authorizationUrl(OauthProvider $provider, string $state, string $nonce, ?string $redirectUri = null, ?string $codeVerifier = null): string
    {
        $redirectUri ??= $this->redirectUri();
        $params = ['state' => $state];

        if ($provider->type === self::TYPE_OIDC || $provider->type === self::TYPE_GOOGLE) {
            $params['nonce'] = $nonce;
        }

        if ($provider->type === self::TYPE_GITHUB || $provider->type === self::TYPE_GOOGLE) {
            $driver = $this->socialiteDriver($provider, $redirectUri);
            if (! $driver) {
                return '';
            }

            return $driver->stateless()->with($params)->redirect()->getTargetUrl();
        }

        // Generic OIDC: discover the authorization endpoint and build the URL by hand.
        $config = $this->discoverOidc($provider->issuer ?? '');
        if (! $config || empty($config['authorization_endpoint'])) {
            return '';
        }

        $query = array_merge([
            'client_id' => $provider->client_id,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => str_replace(',', ' ', $this->scopes($provider)),
        ], $params);

        // PKCE: include the challenge derived from the verifier (e.g. Keycloak clients that
        // require Proof Key for Code Exchange).
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $method = $provider->pkce_method ?: 'S256';
            $query['code_challenge'] = $this->pkceChallenge($codeVerifier, $method);
            $query['code_challenge_method'] = $method;
        }

        $separator = str_contains($config['authorization_endpoint'], '?') ? '&' : '?';

        return $config['authorization_endpoint'].$separator.http_build_query($query);
    }

    /**
     * Build a stateless Socialite driver from an OauthProvider row (github/google only).
     */
    private function socialiteDriver(OauthProvider $provider, ?string $redirectUri = null): ?SocialiteProvider
    {
        $class = match ($provider->type) {
            self::TYPE_GITHUB => GithubProvider::class,
            self::TYPE_GOOGLE => GoogleProvider::class,
            default => null,
        };

        if ($class === null) {
            return null;
        }

        return Socialite::buildProvider($class, [
            'client_id' => $provider->client_id,
            'client_secret' => $provider->client_secret,
            'redirect' => $redirectUri ?? $this->redirectUri(),
            'scopes' => $this->scopeList($provider),
        ]);
    }

    /**
     * Handle the provider callback: exchange `code`, resolve/create the local user and store
     * the issued AuthBody against the pending session.
     *
     * @return array{ok: bool, error: string}
     */
    public function handleCallback(string $state, string $code): array
    {
        if ($state === '') {
            return ['ok' => false, 'error' => 'Missing state'];
        }

        $session = OauthSession::find($state);
        if (! $session || $session->isExpired()) {
            return ['ok' => false, 'error' => 'Session expired'];
        }

        // Already resolved — nothing more to do (idempotent).
        if (! empty($session->auth_body)) {
            return ['ok' => true, 'error' => ''];
        }

        $provider = $this->enabledProvider((string) $session->op);
        if (! $provider) {
            return ['ok' => false, 'error' => 'Provider not found'];
        }

        $oauthUser = $this->fetchOauthUser($provider, $code, null, (string) ($session->code_verifier ?? ''));
        if ($oauthUser === null) {
            return ['ok' => false, 'error' => 'Failed to fetch user info'];
        }

        $user = $this->findOrCreateUser($provider, $oauthUser);
        if ($user === null) {
            return ['ok' => false, 'error' => 'No bound user; auto-register is disabled'];
        }

        if (! $user->isActive()) {
            return ['ok' => false, 'error' => 'This account is disabled'];
        }

        // Store the AuthBody as a raw JSON string so its exact bytes (incl. empty {} objects)
        // reach the client's strict serde parser unchanged.
        $session->auth_body = (string) json_encode($this->authBody($user, $provider->op, [
            'id' => $session->rustdesk_id,
            'uuid' => $session->uuid,
            'device_os' => $session->device_os,
            'device_type' => $session->device_type,
            'device_name' => $session->device_name,
        ]), JSON_UNESCAPED_SLASHES);
        $session->save();

        Log::channel('stderr')->info('OIDC callback resolved user', [
            'op' => $provider->op,
            'user_id' => $user->id,
            'status' => (int) $user->status,
        ]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Exchange the provider `code` for a normalized OAuth user.
     *
     * @return array<string, mixed>|null ['open_id','name','username','email','verified_email','picture']
     */
    private function fetchOauthUser(OauthProvider $provider, string $code, ?string $redirectUri = null, string $codeVerifier = ''): ?array
    {
        if ($provider->type === self::TYPE_GITHUB || $provider->type === self::TYPE_GOOGLE) {
            $driver = $this->socialiteDriver($provider, $redirectUri);
            if (! $driver) {
                return null;
            }

            try {
                /** @var SocialiteUser $su */
                $su = $driver->stateless()->user();
            } catch (\Throwable $e) {
                Log::warning('OAuth socialite user fetch failed', ['op' => $provider->op, 'error' => $e->getMessage()]);

                return null;
            }

            return $this->normalizeSocialiteUser($provider, $su);
        }

        return $this->oidcExchange($provider, $code, $redirectUri, $codeVerifier);
    }

    /**
     * Normalize a Socialite user (github/google) into our open_id-keyed shape.
     *
     * @return array<string, mixed>
     */
    private function normalizeSocialiteUser(OauthProvider $provider, SocialiteUser $su): array
    {
        $raw = $su->getRaw();
        $email = (string) ($su->getEmail() ?? '');
        $username = '';

        if ($provider->type === self::TYPE_GITHUB) {
            $username = strtolower((string) ($su->getNickname() ?? ($raw['login'] ?? '')));
        } else {
            $username = strtolower((string) ($raw['preferred_username'] ?? $email));
        }

        if ($username === '' && $email !== '') {
            $username = strtolower($email);
        }

        return [
            'open_id' => (string) $su->getId(),
            'name' => (string) ($su->getName() ?? ''),
            'username' => $username,
            'email' => $email,
            'verified_email' => (bool) ($raw['email_verified'] ?? false),
            'picture' => (string) ($su->getAvatar() ?? ''),
        ];
    }

    /**
     * Generic OIDC code exchange: discover endpoints, swap code → token, decode userinfo.
     *
     * @return array<string, mixed>|null
     */
    private function oidcExchange(OauthProvider $provider, string $code, ?string $redirectUri = null, string $codeVerifier = ''): ?array
    {
        $config = $this->discoverOidc($provider->issuer ?? '');
        if (! $config || empty($config['token_endpoint']) || empty($config['userinfo_endpoint'])) {
            Log::warning('OIDC discovery failed validation', ['op' => $provider->op]);

            return null;
        }

        try {
            $form = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri ?? $this->redirectUri(),
                'client_id' => $provider->client_id,
                'client_secret' => $provider->client_secret,
            ];
            // PKCE: prove possession of the verifier whose challenge was sent at authorize time.
            if ($codeVerifier !== '') {
                $form['code_verifier'] = $codeVerifier;
            }

            $tokenEndpoint = (string) $config['token_endpoint'];
            $tokenResponse = $this->oidcRequest($tokenEndpoint)
                ->asForm()
                ->acceptJson()
                ->post($tokenEndpoint, $form);

            if (! $tokenResponse->successful()) {
                Log::warning('OIDC token exchange failed', [
                    'op' => $provider->op,
                    'status' => $tokenResponse->status(),
                ]);

                return null;
            }

            $accessToken = (string) ($tokenResponse->json('access_token') ?? '');
            if ($accessToken === '') {
                Log::warning('OIDC token response had no access_token', ['op' => $provider->op]);

                return null;
            }

            $userinfoEndpoint = (string) $config['userinfo_endpoint'];
            $userResponse = $this->oidcRequest($userinfoEndpoint)
                ->withToken($accessToken)
                ->acceptJson()
                ->get($userinfoEndpoint);

            if (! $userResponse->successful()) {
                Log::warning('OIDC userinfo fetch failed', ['op' => $provider->op, 'status' => $userResponse->status()]);

                return null;
            }

            $info = (array) $userResponse->json();
        } catch (\Throwable $e) {
            Log::warning('OIDC exchange threw', [
                'op' => $provider->op,
                'exception' => $e::class,
            ]);

            return null;
        }

        $email = (string) ($info['email'] ?? '');
        $username = (string) ($info['preferred_username'] ?? '');
        if ($username === '' && $email !== '') {
            $username = strtolower($email);
        }

        return [
            'open_id' => (string) ($info['sub'] ?? ''),
            'name' => (string) ($info['name'] ?? ''),
            'username' => strtolower($username),
            'email' => $email,
            'verified_email' => (bool) ($info['email_verified'] ?? false),
            'picture' => (string) ($info['picture'] ?? ''),
        ];
    }

    /**
     * Discover an OIDC provider's endpoints from its issuer's well-known document.
     *
     * @return array<string, mixed>|null
     */
    private function discoverOidc(string $issuer): ?array
    {
        try {
            $issuer = $this->oidcDestinationGuard->normalizeIssuer($issuer);
            $url = $issuer.'/.well-known/openid-configuration';
            $response = $this->oidcRequest($url)->acceptJson()->get($url);
            if (! $response->successful()) {
                return null;
            }

            $config = $response->json();
            if (! is_array($config)
                || ! is_string($config['issuer'] ?? null)
                || ! $this->oidcDestinationGuard->issuerMatches($issuer, $config['issuer'])) {
                return null;
            }

            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $endpoint) {
                if (! is_string($config[$endpoint] ?? null) || trim($config[$endpoint]) === '') {
                    return null;
                }

                // Validate every discovered endpoint now so a malicious document cannot even
                // start a login flow. Token and userinfo are resolved again immediately before
                // their requests to close the DNS-rebinding window.
                $this->oidcDestinationGuard->resolve($config[$endpoint]);
            }

            return $config;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build a secure request for one generic OIDC hop. Resolution happens immediately before
     * the request and is pinned into cURL; redirects and environment proxies stay disabled.
     */
    private function oidcRequest(string $url): PendingRequest
    {
        $destination = $this->oidcDestinationGuard->resolve($url);

        return Http::connectTimeout(self::OIDC_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::OIDC_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->withOptions($this->oidcDestinationGuard->requestOptions($destination));
    }

    /**
     * Find a local user linked to this provider identity, or create one when the provider
     * allows auto-registration. Returns null when no user is linked and auto_register is off.
     */
    private function findOrCreateUser(OauthProvider $provider, array $oauthUser): ?User
    {
        $openId = (string) ($oauthUser['open_id'] ?? '');
        if ($openId === '') {
            return null;
        }

        /** @var UserThird|null $third */
        $third = UserThird::where('op', $provider->op)
            ->where('open_id', $openId)
            ->first();

        if ($third) {
            return User::find($third->user_id);
        }

        if (! $provider->auto_register) {
            return null;
        }

        $user = $this->registerUser($oauthUser);

        UserThird::create([
            'user_id' => $user->id,
            'open_id' => $openId,
            'name' => (string) ($oauthUser['name'] ?? ''),
            'username' => (string) ($oauthUser['username'] ?? ''),
            'email' => (string) ($oauthUser['email'] ?? ''),
            'verified_email' => (bool) ($oauthUser['verified_email'] ?? false),
            'picture' => (string) ($oauthUser['picture'] ?? ''),
            'type' => $provider->type,
            'op' => $provider->op,
        ]);

        return $user;
    }

    /**
     * Create a new local user from the provider identity, ensuring a unique username.
     */
    private function registerUser(array $oauthUser): User
    {
        $base = (string) ($oauthUser['username'] ?? '');
        if ($base === '') {
            $email = (string) ($oauthUser['email'] ?? '');
            $base = $email !== '' ? strtolower(explode('@', $email)[0]) : 'user';
        }

        $username = $base;
        $suffix = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base.$suffix;
            $suffix++;
        }

        return User::create([
            'username' => $username,
            'email' => (string) ($oauthUser['email'] ?? '') ?: null,
            'password' => Str::random(32),
            'display_name' => (string) ($oauthUser['name'] ?? ''),
            'avatar' => (string) ($oauthUser['picture'] ?? ''),
            'is_admin' => false,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    /**
     * Poll a pending session. Returns the AuthBody JSON string when ready, otherwise the
     * pending error JSON the client recognizes ("No authed oidc is found").
     */
    public function pollResult(string $code, string $rustdeskId, string $uuid): string
    {
        $code = trim($code);
        $rustdeskId = trim($rustdeskId);
        $uuid = trim($uuid);
        if ($code === '' || $rustdeskId === '' || $uuid === '') {
            return $this->pendingPollResult();
        }

        return DB::transaction(function () use ($code, $rustdeskId, $uuid): string {
            $session = OauthSession::whereKey($code)->lockForUpdate()->first();
            if (! $session || $session->isExpired()) {
                $session?->delete();

                return $this->pendingPollResult();
            }

            $storedId = (string) $session->rustdesk_id;
            $storedUuid = (string) $session->uuid;
            if ($storedId === '' || $storedUuid === ''
                || ! hash_equals($storedId, $rustdeskId)
                || ! hash_equals($storedUuid, $uuid)) {
                return $this->pendingPollResult();
            }

            $deliveryCount = (int) $session->delivery_count;
            $retryExpired = $deliveryCount > 0 && (
                $session->delivered_at === null
                || $session->delivered_at->lt(now()->subSeconds(self::POLL_RETRY_WINDOW_SECONDS))
            );
            if (empty($session->auth_body) || $deliveryCount >= 2 || $retryExpired) {
                if (! empty($session->auth_body)) {
                    $session->forceFill(['auth_body' => null])->save();
                }

                return $this->pendingPollResult();
            }

            // The stored value is already the exact JSON string required by the client's
            // strict parser. Permit one short retry for a dropped response, then erase it.
            $json = (string) $session->auth_body;
            $deliveryCount++;
            $session->forceFill([
                'delivery_count' => $deliveryCount,
                'delivered_at' => $session->delivered_at ?? now(),
                'auth_body' => $deliveryCount >= 2 ? null : $session->auth_body,
            ])->save();

            Log::channel('stderr')->info('OIDC auth-query delivered token', [
                'op' => (string) $session->op,
                'bytes' => strlen($json),
                'delivery' => $deliveryCount,
            ]);

            return $json;
        });
    }

    private function pendingPollResult(): string
    {
        return (string) json_encode(['error' => 'No authed oidc is found']);
    }

    /**
     * Issue an AuthToken and assemble the AuthBody (contract §3b) for an SSO login.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function authBody(User $user, string $op, array $session): array
    {
        $token = AuthToken::create([
            'user_id' => $user->id,
            'credential_version' => max(1, (int) $user->credential_version),
            'rustdesk_id' => (string) ($session['id'] ?? '') ?: null,
            'uuid' => (string) ($session['uuid'] ?? '') ?: null,
            'device_os' => (string) ($session['device_os'] ?? '') ?: null,
            'device_type' => (string) ($session['device_type'] ?? '') ?: null,
            'device_name' => (string) ($session['device_name'] ?? '') ?: null,
            'token' => Str::random(60),
            'is_admin' => (bool) $user->is_admin,
            'status' => AuthToken::STATUS_ACTIVE,
            'expires_at' => now()->addDays((int) config('rustdesk.token_ttl_days', 90)),
            'last_used_at' => now(),
        ]);

        return [
            'access_token' => $token->token,
            'type' => 'access_token',
            'tfa_type' => '',
            'secret' => '',
            'user' => [
                'name' => (string) $user->username,
                'display_name' => (string) ($user->display_name ?? ''),
                'avatar' => (string) ($user->avatar ?? ''),
                'email' => (string) ($user->email ?? ''),
                'note' => (string) ($user->note ?? ''),
                // The client's UserStatus enum only accepts -1 / 0 / 1; clamp anything else so a
                // stray DB value can't break deserialization of the whole AuthBody.
                'status' => in_array((int) $user->status, [User::STATUS_DISABLED, User::STATUS_NORMAL, User::STATUS_UNVERIFIED], true)
                    ? (int) $user->status
                    : User::STATUS_NORMAL,
                'is_admin' => (bool) $user->is_admin,
                'third_auth_type' => $op,
                // Empty object: the client's UserInfo fields all default. We deliberately omit
                // the flattened settings (`#[serde(flatten)]` UserSettings) + whitelist here,
                // because the client parses the OIDC poll with serde_json::from_value, and
                // flatten + from_value silently fails to deserialize populated content — which
                // made the client receive the token yet keep polling ("Waiting account auth").
                // Password login is parsed by the Dart layer, so it was unaffected.
                'info' => (object) [],
            ],
        ];
    }

    /**
     * Interactive (admin-console) SSO: build the provider authorization URL with a redirect_uri
     * pointing at the console callback (not the client polling callback).
     */
    public function webAuthorizationUrl(OauthProvider $provider, string $state, string $nonce, string $redirectUri, ?string $codeVerifier = null): string
    {
        return $this->authorizationUrl($provider, $state, $nonce, $redirectUri, $codeVerifier);
    }

    /**
     * Interactive (admin-console) SSO: exchange the callback `code` and resolve/create the
     * local user. Returns null when the exchange fails or no user is linked and auto-register
     * is off. The same `redirectUri` (and PKCE verifier, if used) from the start must be passed.
     */
    public function webResolveUser(OauthProvider $provider, string $code, string $redirectUri, string $codeVerifier = ''): ?User
    {
        $oauthUser = $this->fetchOauthUser($provider, $code, $redirectUri, $codeVerifier);
        if ($oauthUser === null) {
            return null;
        }

        return $this->findOrCreateUser($provider, $oauthUser);
    }

    /**
     * Enabled providers offered as interactive sign-in buttons on the console login page.
     *
     * @return Collection<int, OauthProvider>
     */
    public function loginProviders(): Collection
    {
        return OauthProvider::where('enabled', true)->orderBy('op')->get();
    }

    /**
     * Look up an enabled provider by its `op` key. Returns null when missing/disabled.
     */
    public function enabledProvider(string $op): ?OauthProvider
    {
        if ($op === '') {
            return null;
        }

        return OauthProvider::where('op', $op)
            ->where('enabled', true)
            ->first();
    }

    /**
     * The list of enabled provider keys (`op`), used by /api/login-options.
     *
     * @return list<string>
     */
    public function enabledProviderKeys(): array
    {
        return OauthProvider::where('enabled', true)
            ->orderBy('op')
            ->pluck('op')
            ->all();
    }

    /**
     * The OAuth redirect URI the client/provider returns to (app's callback).
     */
    public function redirectUri(): string
    {
        return rtrim((string) config('rustdesk.api_server'), '/').'/api/oauth/callback';
    }

    /**
     * A fresh PKCE code verifier (43–128 chars from the unreserved set).
     */
    public function pkceVerifier(): string
    {
        return Str::random(64);
    }

    /**
     * Derive the PKCE code challenge from a verifier. S256 = base64url(sha256(verifier));
     * "plain" returns the verifier unchanged.
     */
    public function pkceChallenge(string $verifier, string $method = 'S256'): string
    {
        if ($method === 'plain') {
            return $verifier;
        }

        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Effective scopes string (comma-separated), falling back to the OIDC defaults.
     */
    private function scopes(OauthProvider $provider): string
    {
        $scopes = trim((string) ($provider->scopes ?? ''));

        return $scopes !== '' ? $scopes : self::DEFAULT_SCOPES;
    }

    /**
     * Effective scopes as a list for Socialite.
     *
     * @return list<string>
     */
    private function scopeList(OauthProvider $provider): array
    {
        $scopes = trim((string) ($provider->scopes ?? ''));
        if ($scopes === '') {
            return $provider->type === self::TYPE_GITHUB
                ? ['read:user', 'user:email']
                : ['openid', 'profile', 'email'];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $scopes) ?: [])));
    }
}
