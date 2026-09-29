<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OauthController;
use App\Models\AuthToken;
use App\Models\OauthProvider;
use App\Services\OidcDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * OIDC device-login consent phishing: the provider callback must not bind the signed-in identity
 * to a device until the account holder explicitly approves it on the callback page. The client's
 * polling contract stays unchanged.
 */
class OidcDeviceApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        OauthProvider::create([
            'op' => 'keycloak', 'type' => 'oidc', 'client_id' => 'rustdesk', 'client_secret' => 'shh',
            'scopes' => 'openid,profile,email', 'issuer' => 'https://kc.example.com/realms/test',
            'auto_register' => true, 'pkce_enable' => false, 'enabled' => true,
        ]);
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['8.8.8.8']);
        });
        Http::fake([
            'kc.example.com/realms/test/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://kc.example.com/realms/test',
                'authorization_endpoint' => 'https://kc.example.com/auth',
                'token_endpoint' => 'https://kc.example.com/token',
                'userinfo_endpoint' => 'https://kc.example.com/userinfo',
            ]),
            'kc.example.com/token' => Http::response(['access_token' => 'tok']),
            'kc.example.com/userinfo' => Http::response([
                'sub' => 'kc-7', 'email' => 'victim@example.com', 'preferred_username' => 'victim',
                'email_verified' => true, 'name' => 'Victim',
            ]),
        ]);
    }

    /** @return array{string, TestResponse} */
    private function startAndCallback(): array
    {
        $code = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/oidc/auth', [
                'op' => 'keycloak', 'id' => '123456789', 'uuid' => 'dev-uuid',
                'deviceInfo' => ['os' => 'windows', 'type' => 'client', 'name' => 'Attacker laptop'],
            ])->assertOk()->json('code');
        $this->assertIsString($code);

        $page = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->get('/api/oauth/callback?state='.$code.'&code=provider-code')
            ->assertOk();

        return [$code, $page];
    }

    private function poll(string $code): TestResponse
    {
        return $this->getJson("/api/oidc/auth-query?code={$code}&id=123456789&uuid=dev-uuid")->assertOk();
    }

    private function nonce(TestResponse $page): string
    {
        $this->assertSame(1, preg_match('/name="nonce" value="([^"]+)"/', $page->getContent(), $m));

        return html_entity_decode($m[1]);
    }

    private function browserSecret(TestResponse $page): string
    {
        $cookie = $page->getCookie(OauthController::APPROVAL_COOKIE, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        return (string) $cookie->getValue();
    }

    public function test_callback_shows_the_requesting_device_and_issues_no_token_until_approved(): void
    {
        [$code, $page] = $this->startAndCallback();

        $page->assertSee('Approve sign-in on this device')
            ->assertSee('Attacker laptop')
            ->assertSee('123456789')
            ->assertSee('203.0.113.9')
            ->assertSee('victim')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(0, AuthToken::count());
        $this->poll($code)->assertJsonPath('error', 'No authed oidc is found');

        $this->withUnencryptedCookie(OauthController::APPROVAL_COOKIE, $this->browserSecret($page))
            ->post('/api/oidc/confirm', ['state' => $code, 'nonce' => $this->nonce($page), 'decision' => 'approve'])
            ->assertOk()
            ->assertSee('Sign-in complete');

        $this->assertSame(1, AuthToken::count());
        $this->poll($code)->assertJsonPath('access_token', fn ($t) => is_string($t) && $t !== '')
            ->assertJsonPath('user.name', 'victim');
    }

    public function test_approval_requires_both_the_page_nonce_and_the_browser_cookie(): void
    {
        [$code, $page] = $this->startAndCallback();
        $nonce = $this->nonce($page);
        $secret = $this->browserSecret($page);

        // No cookie (e.g. a cross-site form post or a different browser).
        $this->post('/api/oidc/confirm', ['state' => $code, 'nonce' => $nonce, 'decision' => 'approve'])
            ->assertOk()->assertSee('Sign-in failed');
        // Wrong nonce.
        $this->withUnencryptedCookie(OauthController::APPROVAL_COOKIE, $secret)
            ->post('/api/oidc/confirm', ['state' => $code, 'nonce' => 'guess', 'decision' => 'approve'])
            ->assertOk()->assertSee('Sign-in failed');
        // Array-shaped input does not error.
        $this->withUnencryptedCookie(OauthController::APPROVAL_COOKIE, $secret)
            ->post('/api/oidc/confirm', ['state' => [$code], 'nonce' => [$nonce], 'decision' => 'approve'])
            ->assertOk()->assertSee('Sign-in failed');

        $this->assertSame(0, AuthToken::count());
        $this->poll($code)->assertJsonPath('error', 'No authed oidc is found');
    }

    public function test_deny_discards_the_sign_in(): void
    {
        [$code, $page] = $this->startAndCallback();

        $this->withUnencryptedCookie(OauthController::APPROVAL_COOKIE, $this->browserSecret($page))
            ->post('/api/oidc/confirm', ['state' => $code, 'nonce' => $this->nonce($page), 'decision' => 'deny'])
            ->assertOk()
            ->assertSee('Sign-in denied');

        $this->assertSame(0, AuthToken::count());
        $this->assertDatabaseCount('oauth_sessions', 0);
        $this->poll($code)->assertJsonPath('error', 'No authed oidc is found');
    }

    public function test_a_second_callback_for_the_same_session_cannot_mint_a_new_approval_page(): void
    {
        [$code] = $this->startAndCallback();

        // Whoever holds the polling code (the phisher) replays the callback to get their own page.
        $replay = $this->get('/api/oauth/callback?state='.$code.'&code=another-code')->assertOk();
        $replay->assertSee('Sign-in failed')->assertDontSee('Approve sign-in on this device');
        $this->assertNull($replay->getCookie(OauthController::APPROVAL_COOKIE, false));
    }

    public function test_oidc_auth_is_rate_limited_per_source(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
                ->postJson('/api/oidc/auth', ['op' => 'keycloak', 'id' => 'dev-'.$i, 'uuid' => 'u-'.$i])
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
            ->postJson('/api/oidc/auth', ['op' => 'keycloak', 'id' => 'dev-x', 'uuid' => 'u-x'])
            ->assertStatus(429)
            ->assertJsonStructure(['error']);
    }
}
