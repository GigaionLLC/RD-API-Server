<?php

namespace Tests\Feature;

use App\Models\OauthProvider;
use App\Models\User;
use App\Models\UserThird;
use App\Services\OidcDnsResolver;
use App\Services\TwoFactorService;
use App\Support\RecentAdminAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Interactive SSO/OIDC sign-in for the admin console (e.g. Keycloak): login-page buttons, the
 * authorize redirect, and the callback that establishes the session.
 */
class AdminSsoLoginTest extends TestCase
{
    use RefreshDatabase;

    private function provider(bool $autoRegister = false): OauthProvider
    {
        return OauthProvider::create([
            'op' => 'keycloak', 'type' => 'oidc', 'client_id' => 'rustdesk',
            'client_secret' => 'shh', 'scopes' => 'openid,profile,email',
            'issuer' => 'https://kc.example.com/realms/test',
            'auto_register' => $autoRegister, 'pkce_enable' => false, 'pkce_method' => 'S256',
            'enabled' => true,
        ]);
    }

    private function fakeOidc(): void
    {
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['8.8.8.8']);
        });

        Http::fake([
            'kc.example.com/realms/test/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://kc.example.com/realms/test',
                'authorization_endpoint' => 'https://kc.example.com/auth',
                'token_endpoint' => 'https://kc.example.com/token',
                'userinfo_endpoint' => 'https://kc.example.com/userinfo',
            ], 200),
            'kc.example.com/token' => Http::response(['access_token' => 'tok'], 200),
            'kc.example.com/userinfo' => Http::response([
                'sub' => 'kc-123', 'email' => 'admin@example.com',
                'preferred_username' => 'kcadmin', 'email_verified' => true, 'name' => 'KC Admin',
            ], 200),
        ]);
    }

    public function test_login_page_shows_sso_button_when_a_provider_is_enabled(): void
    {
        $this->provider();

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('Sign in with Keycloak');
    }

    public function test_redirect_starts_the_authorize_flow(): void
    {
        $this->fakeOidc();
        $this->provider();

        $res = $this->get(route('admin.sso.redirect', ['op' => 'keycloak']));

        $res->assertRedirect();
        $location = (string) $res->headers->get('Location');
        $this->assertStringContainsString('https://kc.example.com/auth', $location);
        $this->assertStringContainsString('state=', $location);
        // redirect_uri must point at the console callback, not the client callback.
        $this->assertStringContainsString(urlencode('/admin/sso/keycloak/callback'), $location);
        $this->assertNotNull(session('admin_sso'));
    }

    public function test_callback_signs_in_a_linked_admin(): void
    {
        $this->fakeOidc();
        $this->provider();

        $admin = User::create([
            'username' => 'kcadmin', 'password' => 'secret12345', 'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        UserThird::create([
            'user_id' => $admin->id, 'op' => 'keycloak', 'open_id' => 'kc-123', 'type' => 'oidc',
            'username' => 'kcadmin', 'email' => 'admin@example.com',
        ]);

        $res = $this->withSession(['admin_sso' => ['state' => 'st4te', 'op' => 'keycloak', 'remember' => false]])
            ->get(route('admin.sso.callback', ['op' => 'keycloak', 'state' => 'st4te', 'code' => 'abc']));

        $res->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(session()->has(RecentAdminAuthentication::SESSION_KEY));

        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('Set up authenticator');

        $this->post(route('admin.2fa.reauthenticate'))
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('url.intended', route('admin.2fa.show'))
            ->assertSessionMissing(RecentAdminAuthentication::SESSION_KEY);
        $this->assertGuest();

        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->get(route('admin.sso.redirect', ['op' => 'keycloak']))
            ->assertRedirect();
        $reauthentication = session('admin_sso');
        $this->assertIsArray($reauthentication);
        $this->assertIsString($reauthentication['state'] ?? null);

        $this->get(route('admin.sso.callback', [
            'op' => 'keycloak',
            'state' => $reauthentication['state'],
            'code' => 'reauthentication-code',
        ]))
            ->assertRedirect(route('admin.2fa.show'));

        $this->assertAuthenticatedAs($admin);
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('Set up authenticator');
        $this->assertTrue(app(RecentAdminAuthentication::class)->isValid(session()->driver(), $admin->fresh()));
    }

    public function test_sso_reauthentication_does_not_prove_possession_of_the_local_factor(): void
    {
        $this->fakeOidc();
        $this->provider();

        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecret();
        $admin = User::create([
            'username' => 'kcadmin',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);
        UserThird::create([
            'user_id' => $admin->id,
            'op' => 'keycloak',
            'open_id' => 'kc-123',
            'type' => 'oidc',
            'username' => 'kcadmin',
            'email' => 'admin@example.com',
        ]);

        $this->withSession(['admin_sso' => ['state' => 'st4te', 'op' => 'keycloak', 'remember' => false]])
            ->get(route('admin.sso.callback', [
                'op' => 'keycloak',
                'state' => 'st4te',
                'code' => 'abc',
            ]))
            ->assertRedirect(route('admin.dashboard'));

        $recentAuthentication = app(RecentAdminAuthentication::class);
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($recentAuthentication->isValid(session()->driver(), $admin->fresh()));
        $this->assertFalse($recentAuthentication->factorWasVerified(session()->driver(), $admin->fresh()));

        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->delete(route('admin.2fa.disable'))
            ->assertSessionHasErrors('code');

        $admin->refresh();
        $this->assertTrue((bool) $admin->two_factor_enabled);
        $this->assertSame($secret, $admin->two_factor_secret);
        $this->assertSame(User::LOGIN_VERIFY_TOTP, $admin->login_verify);

        $this->delete(route('admin.2fa.disable'), [
            'code' => $twoFactor->currentCode($secret),
        ])->assertRedirect(route('admin.2fa.show'));

        $admin->refresh();
        $this->assertFalse((bool) $admin->two_factor_enabled);
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertSame(User::LOGIN_VERIFY_OFF, $admin->login_verify);
    }

    public function test_callback_rejects_a_bad_state(): void
    {
        $this->provider();

        $this->withSession(['admin_sso' => ['state' => 'real', 'op' => 'keycloak', 'remember' => false]])
            ->get(route('admin.sso.callback', ['op' => 'keycloak', 'state' => 'forged', 'code' => 'abc']))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    public function test_callback_rejects_a_non_admin(): void
    {
        $this->fakeOidc();
        $this->provider();

        $user = User::create([
            'username' => 'kcadmin', 'password' => 'secret12345', 'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        UserThird::create([
            'user_id' => $user->id, 'op' => 'keycloak', 'open_id' => 'kc-123', 'type' => 'oidc',
        ]);

        $this->withSession(['admin_sso' => ['state' => 'st4te', 'op' => 'keycloak', 'remember' => false]])
            ->get(route('admin.sso.callback', ['op' => 'keycloak', 'state' => 'st4te', 'code' => 'abc']))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }
}
