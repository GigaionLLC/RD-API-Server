<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brute-force protection on the two login surfaces: the client API (JSON {error} + 429)
 * and the admin web console (redirect back with a form error). See AppServiceProvider
 * (api-login limiter) and Admin\AuthController (in-controller throttle).
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_login_is_throttled_after_repeated_failures(): void
    {
        User::create([
            'username' => 'victim', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);

        // The per-account+IP limit allows 10 attempts/min; each wrong password returns 200 {error}.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['username' => 'victim', 'password' => 'wrong'])
                ->assertOk();
        }

        // The 11th is blocked with the throttle's {error} body (client surfaces it).
        $this->postJson('/api/login', ['username' => 'victim', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJsonStructure(['error']);
    }

    public function test_client_login_throttle_is_scoped_per_account(): void
    {
        User::create([
            'username' => 'victim', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);
        User::create([
            'username' => 'other', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);

        // Exhaust 'victim'.
        for ($i = 0; $i < 11; $i++) {
            $this->postJson('/api/login', ['username' => 'victim', 'password' => 'wrong']);
        }

        // A different account from the same IP is still under the looser per-IP cap (30/min).
        $this->postJson('/api/login', ['username' => 'other', 'password' => 'wrong'])
            ->assertOk()
            ->assertJsonStructure(['error']); // wrong password, but NOT throttled
    }

    public function test_admin_login_is_throttled_after_five_failures(): void
    {
        User::create([
            'username' => 'adm', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['username' => 'adm', 'password' => 'wrong'])
                ->assertSessionHasErrors('username');
        }

        $this->post('/admin/login', ['username' => 'adm', 'password' => 'wrong'])
            ->assertSessionHasErrors('username');
        $this->assertStringContainsString('Too many', session('errors')->get('username')[0]);
        $this->assertGuest();
    }
}
