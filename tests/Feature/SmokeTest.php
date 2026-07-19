<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * High-level smoke tests covering the web shell, the client API contract, and auth.
 * Exercised by `php artisan test` and in CI.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_admin_login_page_loads(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Welcome back');
    }

    public function test_admin_area_requires_auth(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_api_version_endpoint(): void
    {
        $this->getJson('/api/version')->assertOk()->assertExactJson(['version' => '1.1.0']);
    }

    public function test_heartbeat_accepts_a_device(): void
    {
        config()->set('rustdesk.devices.require_deployment', false);
        config()->set('rustdesk.devices.auto_register', true);

        $this->postJson('/api/heartbeat', [
            'id' => 'test-1', 'uuid' => 'uuid-1', 'modified_at' => 0,
        ])->assertOk();

        $this->assertDatabaseHas('devices', ['rustdesk_id' => 'test-1']);
    }

    public function test_sysinfo_unknown_device_without_deployment(): void
    {
        config()->set('rustdesk.devices.require_deployment', false);
        config()->set('rustdesk.devices.auto_register', true);

        // Legacy open enrollment remains available only through explicit opt-in.
        $this->postJson('/api/sysinfo', [
            'id' => 'sys-1', 'uuid' => 'u-sys-1', 'os' => 'Linux', 'hostname' => 'box',
        ])->assertOk()->assertSee('SYSINFO_UPDATED');
    }

    public function test_admin_can_sign_in(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_non_admin_cannot_sign_in_to_console(): void
    {
        User::create([
            'username' => 'bob', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);

        $this->post('/admin/login', ['username' => 'bob', 'password' => 'secret12345'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_api_login_issues_access_token(): void
    {
        User::create([
            'username' => 'carol', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);

        $this->postJson('/api/login', [
            'username' => 'carol', 'password' => 'secret12345',
            'id' => 'dev-1', 'uuid' => 'uuid-dev-1',
        ])->assertOk()->assertJsonStructure(['access_token', 'type', 'user' => ['name']]);
    }
}
