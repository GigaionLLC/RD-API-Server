<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUserTwoFactorStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_creation_rejects_totp_policy_and_factor_material(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'username' => 'crafted-totp-user',
            'password' => 'secret123456',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now()->toIso8601String(),
            'two_factor_recovery_codes' => ['ABCDEF-123456'],
        ])->assertSessionHasErrors([
            'login_verify',
            'two_factor_enabled',
            'two_factor_secret',
            'two_factor_confirmed_at',
            'two_factor_recovery_codes',
        ]);

        $this->assertDatabaseMissing('users', ['username' => 'crafted-totp-user']);
    }

    public function test_generic_update_allows_only_off_or_email_for_inactive_accounts(): void
    {
        $admin = $this->admin();
        $target = $this->user('inactive-policy-target');

        $this->actingAs($admin)->putJson(route('admin.users.update', $target), [
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ])->assertUnprocessable()->assertJsonValidationErrors('login_verify');

        $this->assertSame(User::LOGIN_VERIFY_OFF, $target->refresh()->login_verify);

        $this->actingAs($admin)->putJson(route('admin.users.update', $target), [
            'email' => 'target@example.com',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_EMAIL,
        ])->assertOk();

        $target->refresh();
        $this->assertSame(User::LOGIN_VERIFY_EMAIL, $target->login_verify);
        $this->assertFalse($target->two_factor_enabled);
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertNull($target->two_factor_recovery_codes);
    }

    public function test_generic_update_preserves_an_active_totp_factor_as_read_only(): void
    {
        $admin = $this->admin();
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();
        $confirmedAt = now()->subMinute()->startOfSecond();
        $recovery = $service->protectRecoveryCodes(['ABCDEF-123456']);
        $target = User::create([
            'username' => 'active-totp-target',
            'password' => 'secret123456',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => $confirmedAt,
            'two_factor_recovery_codes' => $recovery,
        ]);
        $rawSecret = DB::table('users')->where('id', $target->id)->value('two_factor_secret');
        $rawRecovery = DB::table('users')->where('id', $target->id)->value('two_factor_recovery_codes');

        $this->actingAs($admin)->putJson(route('admin.users.update', $target), [
            'display_name' => 'Updated without factor access',
            'status' => User::STATUS_NORMAL,
        ])->assertOk();

        $target->refresh();
        $this->assertSame('Updated without factor access', $target->display_name);
        $this->assertTrue($target->hasActiveTotp());
        $this->assertSame($secret, $target->two_factor_secret);
        $this->assertTrue($confirmedAt->equalTo($target->two_factor_confirmed_at));
        $this->assertSame($recovery, $target->two_factor_recovery_codes);
        $this->assertSame(
            $rawSecret,
            DB::table('users')->where('id', $target->id)->value('two_factor_secret'),
        );

        $this->actingAs($admin)->putJson(route('admin.users.update', $target), [
            'status' => User::STATUS_NORMAL,
            'login_verify' => '',
            'two_factor_enabled' => null,
            'two_factor_secret' => '',
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'login_verify',
            'two_factor_enabled',
            'two_factor_secret',
            'two_factor_confirmed_at',
            'two_factor_recovery_codes',
        ]);

        $target->refresh();
        $this->assertTrue($target->hasActiveTotp());
        $this->assertSame($secret, $target->two_factor_secret);
        $this->assertSame($recovery, $target->two_factor_recovery_codes);
        $this->assertSame(
            $rawSecret,
            DB::table('users')->where('id', $target->id)->value('two_factor_secret'),
        );
        $this->assertSame(
            $rawRecovery,
            DB::table('users')->where('id', $target->id)->value('two_factor_recovery_codes'),
        );
    }

    public function test_generic_user_forms_leave_totp_enrollment_to_the_account_owner(): void
    {
        $admin = $this->admin();
        $inactive = $this->user('inactive-form-target');
        $active = User::create([
            'username' => 'active-form-target',
            'password' => 'secret123456',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.users.create'))
            ->assertOk()
            ->assertDontSee('<option value="totp"', false)
            ->assertSee('TOTP enrollment is available only to accounts with console access');

        $this->actingAs($admin)->get(route('admin.users.edit', $inactive))
            ->assertOk()
            ->assertSee('name="login_verify"', false)
            ->assertDontSee('<option value="totp"', false);

        $this->actingAs($admin)->get(route('admin.users.edit', $active))
            ->assertOk()
            ->assertDontSee('name="login_verify"', false)
            ->assertSee('This factor is read-only here');
    }

    public function test_delegated_user_editor_cannot_mutate_an_accounts_active_factor(): void
    {
        $delegate = $this->user('delegated-factor-editor');
        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Delegated user editor',
            'type' => AdminRole::TYPE_GLOBAL,
            'scope' => null,
            'perms' => ['users.view', 'users.edit'],
        ]));

        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();
        $recovery = $service->protectRecoveryCodes(['ABCDEF-123456']);
        $target = User::create([
            'username' => 'delegated-factor-target',
            'password' => 'secret123456',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recovery,
        ]);

        $this->actingAs($delegate)->putJson(route('admin.users.update', $target), [
            'display_name' => 'Delegated safe profile update',
            'status' => User::STATUS_NORMAL,
        ])->assertOk();

        $this->actingAs($delegate)->putJson(route('admin.users.update', $target), [
            'status' => User::STATUS_NORMAL,
            'two_factor_secret' => '',
            'two_factor_recovery_codes' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'two_factor_secret',
            'two_factor_recovery_codes',
        ]);

        $target->refresh();
        $this->assertSame('Delegated safe profile update', $target->display_name);
        $this->assertTrue($target->hasActiveTotp());
        $this->assertSame($secret, $target->two_factor_secret);
        $this->assertSame($recovery, $target->two_factor_recovery_codes);
    }

    private function admin(): User
    {
        return User::create([
            'username' => 'totp-state-admin',
            'password' => 'secret123456',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    private function user(string $username): User
    {
        return User::create([
            'username' => $username,
            'password' => 'secret123456',
            'status' => User::STATUS_NORMAL,
        ]);
    }
}
