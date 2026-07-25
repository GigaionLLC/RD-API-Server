<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ConsoleAudit;
use App\Models\LdapIdentity;
use App\Models\User;
use App\Support\ProtectedAdministrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The shell recovery path. It has to work when the console is unreachable and the identity
 * provider is wrong, which is exactly when nobody is in a position to debug it.
 */
class AdminRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_a_local_password_and_revokes_existing_access(): void
    {
        $user = $this->admin();
        AuthToken::create([
            'user_id' => $user->id, 'credential_version' => (int) $user->credential_version,
            'token' => 'stale-token', 'rustdesk_id' => 'dev', 'uuid' => 'uuid',
            'is_admin' => true, 'status' => AuthToken::STATUS_ACTIVE,
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('rustdesk:admin:reset', ['username' => 'root', '--generate' => true])
            ->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertFalse(Hash::check('secret12345', (string) $fresh?->password));
        $this->assertGreaterThan((int) $user->credential_version, (int) $fresh?->credential_version);
        $this->assertSame(
            AuthToken::STATUS_REVOKED,
            AuthToken::where('token', 'stale-token')->value('status')
        );
    }

    public function test_it_refuses_a_federated_account_until_told_to_unlink_it(): void
    {
        $user = $this->admin();
        LdapIdentity::create([
            'user_id' => $user->id, 'provider' => 'dir-a',
            'subject_hash' => str_repeat('a', 64), 'dn' => 'uid=root,dc=example,dc=com',
        ]);

        // Silently unlinking would be a security change the operator never asked for.
        $this->artisan('rustdesk:admin:reset', ['username' => 'root', '--generate' => true])
            ->assertFailed();
        $this->assertDatabaseCount('ldap_identities', 1);

        $this->artisan('rustdesk:admin:reset', [
            'username' => 'root',
            '--generate' => true,
            '--unlink-federated-identities' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('ldap_identities', 0);
    }

    public function test_it_refuses_an_sso_only_account_until_told_to_clear_the_restriction(): void
    {
        $user = $this->admin();
        $user->forceFill(['force_sso' => true])->save();

        $this->artisan('rustdesk:admin:reset', ['username' => 'root', '--generate' => true])
            ->assertFailed();
        $this->assertTrue((bool) $user->fresh()?->force_sso);

        $this->artisan('rustdesk:admin:reset', [
            'username' => 'root',
            '--generate' => true,
            '--clear-force-sso' => true,
        ])->assertSuccessful();

        $this->assertFalse((bool) $user->fresh()?->force_sso);
    }

    public function test_it_can_recover_an_account_whose_second_factor_is_lost(): void
    {
        // Without this there is no path at all out of a lost TOTP device with no recovery codes.
        $user = $this->admin();
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['spent'],
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ])->save();

        $this->artisan('rustdesk:admin:reset', [
            'username' => 'root',
            '--generate' => true,
            '--clear-2fa' => true,
        ])->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh?->two_factor_enabled);
        $this->assertNull($fresh?->two_factor_secret);
        $this->assertNull($fresh?->two_factor_recovery_codes);
        $this->assertSame(User::LOGIN_VERIFY_OFF, $fresh?->login_verify);
    }

    public function test_a_reset_is_recorded_because_the_console_audit_never_sees_the_cli(): void
    {
        $this->admin();

        $this->artisan('rustdesk:admin:reset', ['username' => 'root', '--generate' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('console_audits', ['route_name' => 'cli.admin.password-reset']);
        $this->assertSame(
            0,
            ConsoleAudit::where('route_name', 'cli.admin.password-reset')->whereNull('user_id')->count()
        );
    }

    public function test_an_unknown_account_fails_without_touching_anything(): void
    {
        $this->artisan('rustdesk:admin:reset', ['username' => 'nobody', '--generate' => true])
            ->assertFailed();
    }

    public function test_the_designation_can_be_shown_transferred_and_cleared(): void
    {
        $first = $this->admin();
        $second = User::create([
            'username' => 'second', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        ProtectedAdministrator::designate($first);

        $this->artisan('rustdesk:admin:protect', ['--show' => true])->assertSuccessful();

        $this->artisan('rustdesk:admin:protect', ['username' => 'second'])->assertSuccessful();
        $this->assertSame((int) $second->id, (int) ProtectedAdministrator::current()?->id);

        // Clearing removes the only guaranteed way back in, so it must be deliberate.
        $this->artisan('rustdesk:admin:protect', ['--clear' => true])->assertFailed();
        $this->assertNotNull(ProtectedAdministrator::current());

        $this->artisan('rustdesk:admin:protect', ['--clear' => true, '--i-understand' => true])
            ->assertSuccessful();
        $this->assertNull(ProtectedAdministrator::current());
    }

    public function test_an_account_that_could_not_recover_access_is_refused_the_designation(): void
    {
        $this->admin();
        $sso = User::create([
            'username' => 'ssoonly', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL, 'force_sso' => true,
        ]);

        $this->artisan('rustdesk:admin:protect', ['username' => 'ssoonly'])->assertFailed();
        $this->assertNotSame((int) $sso->id, (int) ProtectedAdministrator::current()?->id);
    }

    private function admin(): User
    {
        DB::table('users')->update(['is_protected_admin' => false]);

        return User::create([
            'username' => 'root', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }
}
