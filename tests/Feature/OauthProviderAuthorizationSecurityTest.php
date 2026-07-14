<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\OauthProvider;
use App\Models\User;
use App\Models\UserThird;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OauthProviderAuthorizationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_delegated_oauth_editor_cannot_replace_an_identity_provider(): void
    {
        [$provider, $victim] = $this->linkedProvider();
        $delegate = $this->delegatedEditor();

        $this->actingAs($delegate)->get(route('admin.oauth-providers.index'))
            ->assertOk()
            ->assertSee('View')
            ->assertDontSee(route('admin.oauth-providers.create'), false);

        $this->actingAs($delegate)->get(route('admin.oauth-providers.edit', $provider))
            ->assertOk()
            ->assertSee('view-only access')
            ->assertSee('Only a full administrator')
            ->assertSee('disabled', false);

        $this->actingAs($delegate)->post(route('admin.oauth-providers.store'),
            $this->providerPayload('attacker', 'https://attacker.example'))
            ->assertForbidden();

        $this->actingAs($delegate)->put(route('admin.oauth-providers.update', $provider),
            $this->providerPayload('trusted', 'https://attacker.example'))
            ->assertForbidden();

        $this->actingAs($delegate)->delete(route('admin.oauth-providers.destroy', $provider))
            ->assertForbidden();

        $this->assertSame('https://identity.example', $provider->refresh()->issuer);
        $this->assertDatabaseHas('user_thirds', [
            'user_id' => $victim->id,
            'op' => 'trusted',
            'open_id' => 'public-subject',
        ]);
    }

    public function test_linked_trust_domain_cannot_be_changed_in_place(): void
    {
        [$provider] = $this->linkedProvider();
        $admin = $this->fullAdmin();

        $this->actingAs($admin)->put(route('admin.oauth-providers.update', $provider),
            $this->providerPayload('trusted', 'https://attacker.example'))
            ->assertRedirect()
            ->assertSessionHasErrors('op');

        $provider->refresh();
        $this->assertSame('https://identity.example', $provider->issuer);
        $this->assertSame('trusted-client', $provider->client_id);
        $this->assertDatabaseHas('user_thirds', ['op' => 'trusted', 'open_id' => 'public-subject']);
    }

    public function test_full_admin_can_change_non_identity_settings_without_breaking_links(): void
    {
        [$provider] = $this->linkedProvider();
        $admin = $this->fullAdmin();
        $payload = $this->providerPayload('trusted', 'https://identity.example');
        $payload['scopes'] = 'openid,email';
        $payload['client_secret'] = '';
        $payload['enabled'] = '0';

        $this->actingAs($admin)->put(route('admin.oauth-providers.update', $provider), $payload)
            ->assertRedirect(route('admin.oauth-providers.index'));

        $provider->refresh();
        $this->assertSame('openid,email', $provider->scopes);
        $this->assertFalse($provider->enabled);
        $this->assertSame('trusted-secret', $provider->client_secret);
        $this->assertDatabaseHas('user_thirds', ['op' => 'trusted', 'open_id' => 'public-subject']);
    }

    public function test_deleting_a_provider_removes_its_identity_links_before_op_reuse(): void
    {
        [$provider] = $this->linkedProvider();
        $admin = $this->fullAdmin();

        $this->actingAs($admin)->delete(route('admin.oauth-providers.destroy', $provider))
            ->assertRedirect(route('admin.oauth-providers.index'));

        $this->assertDatabaseMissing('oauth_providers', ['id' => $provider->id]);
        $this->assertDatabaseMissing('user_thirds', ['op' => 'trusted']);

        $this->actingAs($admin)->post(route('admin.oauth-providers.store'),
            $this->providerPayload('trusted', 'https://replacement.example'))
            ->assertRedirect(route('admin.oauth-providers.index'));

        $this->assertDatabaseHas('oauth_providers', [
            'op' => 'trusted',
            'issuer' => 'https://replacement.example',
        ]);
        $this->assertDatabaseMissing('user_thirds', ['op' => 'trusted']);
    }

    /**
     * @return array{OauthProvider, User}
     */
    private function linkedProvider(): array
    {
        $provider = OauthProvider::create([
            'op' => 'trusted',
            'type' => 'oidc',
            'client_id' => 'trusted-client',
            'client_secret' => 'trusted-secret',
            'scopes' => 'openid,profile,email',
            'issuer' => 'https://identity.example',
            'auto_register' => false,
            'pkce_enable' => true,
            'pkce_method' => 'S256',
            'enabled' => true,
        ]);
        $victim = $this->fullAdmin('victim-admin');
        UserThird::create([
            'user_id' => $victim->id,
            'open_id' => 'public-subject',
            'username' => $victim->username,
            'type' => 'oidc',
            'op' => $provider->op,
        ]);

        return [$provider, $victim];
    }

    private function delegatedEditor(): User
    {
        $role = AdminRole::create([
            'name' => 'OAuth editor',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['oauth.view', 'oauth.edit'],
        ]);
        $user = User::create([
            'username' => 'oauth-editor',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'is_admin' => false,
        ]);
        $user->adminRoles()->attach($role);

        return $user;
    }

    private function fullAdmin(string $username = 'full-admin'): User
    {
        return User::create([
            'username' => $username,
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'is_admin' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function providerPayload(string $op, string $issuer): array
    {
        return [
            'op' => $op,
            'type' => 'oidc',
            'client_id' => 'trusted-client',
            'client_secret' => 'replacement-secret',
            'scopes' => 'openid,profile,email',
            'issuer' => $issuer,
            'pkce_enable' => '1',
            'pkce_method' => 'S256',
            'enabled' => '1',
        ];
    }
}
