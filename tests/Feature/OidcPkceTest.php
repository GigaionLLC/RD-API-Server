<?php

namespace Tests\Feature;

use App\Models\OauthProvider;
use App\Models\User;
use App\Models\UserThird;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OIDC device-login PKCE: when the provider enables PKCE the authorize URL carries a
 * code_challenge and the token exchange sends the matching code_verifier — required by
 * Keycloak clients configured for Proof Key for Code Exchange. Also guards that the polled
 * auth body is delivered once the callback resolves.
 */
class OidcPkceTest extends TestCase
{
    use RefreshDatabase;

    private function provider(bool $pkce): OauthProvider
    {
        return OauthProvider::create([
            'op' => 'keycloak', 'type' => 'oidc', 'client_id' => 'rustdesk', 'client_secret' => 'shh',
            'scopes' => 'openid,profile,email', 'issuer' => 'https://kc.example.com/realms/test',
            'auto_register' => true, 'pkce_enable' => $pkce, 'pkce_method' => 'S256', 'enabled' => true,
        ]);
    }

    private function fakeOidc(): void
    {
        Http::fake([
            'kc.example.com/realms/test/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://kc.example.com/auth',
                'token_endpoint' => 'https://kc.example.com/token',
                'userinfo_endpoint' => 'https://kc.example.com/userinfo',
            ], 200),
            'kc.example.com/token' => Http::response(['access_token' => 'tok'], 200),
            'kc.example.com/userinfo' => Http::response([
                'sub' => 'kc-1', 'email' => 'u@example.com', 'preferred_username' => 'u',
                'email_verified' => true, 'name' => 'U',
            ], 200),
        ]);
    }

    public function test_authorize_url_carries_pkce_challenge_when_enabled(): void
    {
        $this->fakeOidc();
        $this->provider(true);
        $oauth = app(OauthService::class);

        [$code, $url] = $oauth->beginAuth('keycloak', 'dev', 'uuid', []);
        $this->assertNotSame('', $code);
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function test_no_pkce_params_when_disabled(): void
    {
        $this->fakeOidc();
        $this->provider(false);

        [, $url] = app(OauthService::class)->beginAuth('keycloak', 'dev', 'uuid', []);
        $this->assertStringNotContainsString('code_challenge', $url);
    }

    public function test_token_exchange_sends_verifier_and_completes(): void
    {
        $this->fakeOidc();
        $this->provider(true);
        $oauth = app(OauthService::class);

        [$code] = $oauth->beginAuth('keycloak', 'dev', 'uuid', []);

        // Provider redirects back: server exchanges the code (state == polling code).
        $result = $oauth->handleCallback($code, 'auth-code-xyz');
        $this->assertTrue($result['ok'], $result['error']);

        // The token request must include the PKCE verifier.
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/token')
                && ! empty($req['code_verifier']);
        });

        // The client poll now receives the auth body (not the pending error).
        $body = $oauth->pollResult($code);
        $this->assertStringContainsString('access_token', $body);
        $this->assertStringNotContainsString('No authed oidc is found', $body);
    }

    public function test_pending_session_is_persisted_in_the_database(): void
    {
        $this->fakeOidc();
        $this->provider(true);
        $oauth = app(OauthService::class);

        [$code] = $oauth->beginAuth('keycloak', 'dev', 'uuid', []);

        // Persisted in the DB (shared across instances), not just an in-memory cache.
        $this->assertDatabaseHas('oauth_sessions', ['code' => $code, 'op' => 'keycloak']);
    }

    public function test_poll_resolves_across_separate_service_instances(): void
    {
        // Simulates a load-balanced deployment: the callback and the poll are handled by
        // different OauthService instances (different workers). The DB-backed session must
        // still bridge them — this is exactly the "Waiting account auth" hang.
        $this->fakeOidc();
        $this->provider(true);

        [$code] = app(OauthService::class)->beginAuth('keycloak', 'dev', 'uuid', []);

        // Callback handled by one instance...
        $this->assertTrue(app()->make(OauthService::class)->handleCallback($code, 'auth-code')['ok']);

        // ...client poll handled by a freshly-resolved instance.
        $body = app()->make(OauthService::class)->pollResult($code);
        $this->assertStringContainsString('access_token', $body);

        // Idempotent: a re-poll still returns the token (not consumed on first read), so a
        // client retry can't lose it. The row is pruned on expiry, not on delivery.
        $this->assertStringContainsString('access_token', app()->make(OauthService::class)->pollResult($code));
        $this->assertDatabaseHas('oauth_sessions', ['code' => $code]);
    }

    public function test_auth_query_returns_token_at_top_level_and_in_body(): void
    {
        $this->fakeOidc();
        $this->provider(true);
        $oauth = app(OauthService::class);

        [$code] = $oauth->beginAuth('keycloak', 'dev', 'uuid', []);
        $oauth->handleCallback($code, 'auth-code');

        $res = $this->getJson("/api/oidc/auth-query?code={$code}&id=dev&uuid=uuid")->assertOk();

        // Top level (stable clients / lejianwen-style parse the response directly).
        $res->assertJsonPath('access_token', fn ($t) => is_string($t) && $t !== '');
        $res->assertJsonPath('type', 'access_token');
        $res->assertJsonPath('user.name', 'u');
        // user.info MUST serialize as an object {} (the client's serde UserInfo expects a map);
        // an empty array [] here would fail AuthBody deserialization.
        $this->assertStringContainsString('"info":{}', $res->getContent());
        $this->assertStringNotContainsString('"info":[]', $res->getContent());
        // Also mirrored in `body` for newer clients that read {"body":"<json>"}.
        $this->assertStringContainsString('access_token', $res->json('body'));
    }

    public function test_auth_query_pending_has_error_at_top_level_and_in_body(): void
    {
        $this->provider(true);

        $res = $this->getJson('/api/oidc/auth-query?code=nope&id=d&uuid=u')->assertOk();

        $res->assertJsonPath('error', 'No authed oidc is found');           // top level
        $this->assertStringContainsString('No authed oidc is found', $res->json('body')); // and body
    }

    public function test_provider_without_pkce_still_completes(): void
    {
        $this->fakeOidc();
        $this->provider(false);
        $oauth = app(OauthService::class);

        [$code] = $oauth->beginAuth('keycloak', 'dev', 'uuid', []);
        $this->assertTrue($oauth->handleCallback($code, 'code')['ok']);
        $this->assertStringContainsString('access_token', $oauth->pollResult($code));

        $this->assertDatabaseHas('user_thirds', ['op' => 'keycloak', 'open_id' => 'kc-1']);
        $this->assertNotNull(User::where('username', 'u')->first());
        $this->assertSame(1, UserThird::where('op', 'keycloak')->count());
    }
}
