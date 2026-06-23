<?php

namespace Tests\Feature;

use App\Models\AuditConn;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SSO provider presets on the OAuth create form, and the dashboard's enhanced activity metrics.
 */
class OauthPresetsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'admin'.uniqid(), 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_create_form_offers_presets_and_redirect_uri(): void
    {
        config(['rustdesk.api_server' => 'https://api.example.com']);

        $this->actingAs($this->admin())
            ->get(route('admin.oauth-providers.create'))
            ->assertOk()
            ->assertSee('Quick setup')
            ->assertSee('Keycloak')                 // preset present (emphasis)
            ->assertSee('Microsoft Entra ID (Azure AD)')
            ->assertSee('https://api.example.com/api/oauth/callback'); // redirect URI shown
    }

    public function test_keycloak_preset_is_first(): void
    {
        $presets = array_keys(config('oauth_presets'));
        $this->assertSame('keycloak', $presets[0]);
    }

    public function test_dashboard_renders_activity_metrics(): void
    {
        Device::create(['rustdesk_id' => 'd1', 'uuid' => 'u1', 'is_online' => true]);
        AuditConn::create(['action' => 'new', 'conn_id' => 1, 'peer_id' => 'd1', 'ip' => '10.0.0.1']);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Activity (last 14 days)')
            ->assertSee('New devices');
    }
}
