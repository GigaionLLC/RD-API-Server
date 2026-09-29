<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LdapService;
use App\Support\LoginThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Per-account brute-force ceiling (independent of source address), IPv6 /64 bucketing, and no
 * account-state disclosure before a correct password.
 */
class LoginHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'username' => 'victim', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);
    }

    private function loginFrom(string $ip, string $password, string $username = 'victim')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', ['username' => $username, 'password' => $password, 'id' => 'd', 'uuid' => 'u']);
    }

    public function test_ipv6_addresses_are_bucketed_by_64_prefix(): void
    {
        $this->assertSame('2001:db8:1:2::/64', LoginThrottle::ipBucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame('2001:db8:1:2::/64', LoginThrottle::ipBucket('2001:db8:1:2::1'));
        $this->assertSame('203.0.113.7', LoginThrottle::ipBucket('203.0.113.7'));
        $this->assertSame('203.0.113.7', LoginThrottle::ipBucket('::ffff:203.0.113.7'));
    }

    public function test_rotating_ipv6_addresses_in_one_64_share_the_per_source_limit(): void
    {
        $this->user();

        for ($i = 1; $i <= 10; $i++) {
            $this->loginFrom('2001:db8:5:6::'.dechex($i), 'wrong')->assertOk();
        }

        $this->loginFrom('2001:db8:5:6::ff', 'wrong')->assertStatus(429);
    }

    public function test_per_account_ceiling_holds_across_many_source_addresses(): void
    {
        $this->user();

        // 20 failures spread over 20 addresses: each address stays far below its own limit.
        for ($i = 1; $i <= 20; $i++) {
            $this->loginFrom('198.51.100.'.$i, 'wrong')->assertOk()->assertJsonPath('error', 'Invalid username or password');
        }

        // The 21st guess is refused before the password is even checked — even the right one.
        $this->loginFrom('198.51.100.200', 'secret12345')->assertStatus(429)->assertJsonStructure(['error']);

        // Other accounts are unaffected.
        $this->user(['username' => 'bystander']);
        $this->loginFrom('198.51.100.201', 'secret12345', 'bystander')->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_ordinary_mistakes_do_not_lock_a_user_out_and_success_resets_the_count(): void
    {
        $this->user();

        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $this->loginFrom('192.0.2.'.(10 + $round), 'typo')->assertOk();
            }
            $this->loginFrom('192.0.2.'.(10 + $round), 'secret12345')->assertOk()->assertJsonStructure(['access_token']);
        }
    }

    public function test_locked_account_does_not_reach_ldap(): void
    {
        config()->set('rustdesk.login.account_max_failures', 3);
        $this->mock(LdapService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enabled')->andReturnTrue();
            $mock->shouldReceive('authenticate')->times(3)->andReturnNull();
        });

        for ($i = 1; $i <= 3; $i++) {
            $this->loginFrom('203.0.113.'.$i, 'wrong', 'ad-user')->assertOk();
        }
        for ($i = 4; $i <= 6; $i++) {
            $this->loginFrom('203.0.113.'.$i, 'wrong', 'ad-user')->assertStatus(429);
        }
    }

    public function test_admin_console_login_shares_the_account_ceiling(): void
    {
        config()->set('rustdesk.login.account_max_failures', 3);
        $this->user(['username' => 'adm', 'is_admin' => true]);

        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->post('/admin/login', ['username' => 'adm', 'password' => 'wrong'])
                ->assertSessionHasErrors('username');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->post('/admin/login', ['username' => 'adm', 'password' => 'secret12345'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_account_state_is_not_disclosed_before_a_correct_password(): void
    {
        $this->user(['username' => 'disabled', 'status' => User::STATUS_DISABLED]);
        $this->user(['username' => 'unverified', 'status' => User::STATUS_UNVERIFIED]);
        $this->user(['username' => 'ssoonly', 'force_sso' => true]);

        foreach (['disabled', 'unverified', 'ssoonly', 'nobody'] as $name) {
            $this->loginFrom('192.0.2.50', 'wrong-password', $name)
                ->assertOk()
                ->assertExactJson(['error' => 'Invalid username or password']);
        }

        // The second-factor step carries no password and reveals nothing either.
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.51'])
            ->postJson('/api/login', ['username' => 'disabled', 'type' => 'email_code', 'verificationCode' => '123456'])
            ->assertOk()
            ->assertExactJson(['error' => 'Wrong or expired verification code']);

        // With the right password the specific reason is still shown to the account holder.
        $this->loginFrom('192.0.2.52', 'secret12345', 'disabled')->assertExactJson(['error' => 'Account disabled']);
        $this->loginFrom('192.0.2.52', 'secret12345', 'ssoonly')->assertExactJson(['error' => 'This account must sign in via SSO']);
    }

    public function test_admin_console_force_sso_message_needs_the_password(): void
    {
        $this->user(['username' => 'ssoadmin', 'is_admin' => true, 'force_sso' => true]);

        $this->post('/admin/login', ['username' => 'ssoadmin', 'password' => 'wrong'])
            ->assertSessionHasErrors(['username' => 'Invalid username or password.']);

        $this->post('/admin/login', ['username' => 'ssoadmin', 'password' => 'secret12345'])
            ->assertSessionHasErrors(['username' => 'This account must sign in via SSO.']);
        $this->assertGuest();
    }
}
