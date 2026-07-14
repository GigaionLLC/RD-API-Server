<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LdapTransportAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_page_and_connection_test_explain_blocked_plaintext(): void
    {
        Config::set([
            'ldap.enabled' => true,
            'ldap.host' => 'directory.example.test',
            'ldap.port' => 389,
            'ldap.use_starttls' => false,
            'ldap.tls_verify' => true,
            'ldap.allow_insecure' => false,
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/admin/ldap')
            ->assertOk()
            ->assertSee('Configuration blocked')
            ->assertSee('plaintext LDAP is blocked')
            ->assertSee('LDAP_ALLOW_INSECURE=true');

        $this->actingAs($admin)
            ->post('/admin/ldap/test')
            ->assertRedirect(route('admin.ldap.index'))
            ->assertSessionHas('error', fn (mixed $error): bool => is_string($error)
                && str_contains($error, 'plaintext LDAP is blocked'));
    }

    public function test_blocked_ldap_still_falls_back_to_local_password_login(): void
    {
        Config::set([
            'ldap.enabled' => true,
            'ldap.host' => 'directory.example.test',
            'ldap.port' => 389,
            'ldap.use_starttls' => false,
            'ldap.tls_verify' => true,
            'ldap.allow_insecure' => false,
        ]);
        User::factory()->create([
            'username' => 'local-user',
            'password' => 'local-password',
        ]);

        $this->postJson('/api/login', [
            'username' => 'local-user',
            'password' => 'local-password',
            'id' => 'local-device',
            'uuid' => 'local-uuid',
            'type' => 'account',
        ])->assertOk()->assertJsonPath('type', 'access_token');
    }
}
