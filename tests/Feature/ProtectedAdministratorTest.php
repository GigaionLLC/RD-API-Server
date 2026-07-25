<?php

namespace Tests\Feature;

use App\Models\LdapIdentity;
use App\Models\User;
use App\Services\LdapService;
use App\Support\GeneratedAdminPassword;
use App\Support\InitialAdminPassword;
use App\Support\ProtectedAdministrator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The break-glass administrator: the one account no external system can take away.
 *
 * Every assertion here is about availability rather than confidentiality. The failure this guards
 * against is an operator locked out of their own console by a directory they no longer control.
 */
class ProtectedAdministratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Other tests boot the seeder, which publishes a real credential file. Start clean so this
        // suite never asserts against another test's leftovers.
        InitialAdminPassword::forget();
    }

    protected function tearDown(): void
    {
        InitialAdminPassword::forget();

        parent::tearDown();
    }

    public function test_a_directory_cannot_demote_the_break_glass_administrator(): void
    {
        // This is the live defect the feature exists to close: isAdmin() returns false whenever
        // LDAP_ADMIN_GROUP is unset, which is its default, so LDAP_SYNC=true silently demoted a
        // linked administrator on every single sign-in.
        config()->set('ldap.sync', true);
        config()->set('ldap.admin_group', '');

        $admin = $this->protectedAdmin();
        LdapIdentity::create([
            'user_id' => $admin->id,
            'provider' => 'dir-a',
            'subject_hash' => str_repeat('a', 64),
            'dn' => 'uid=root,dc=example,dc=com',
        ]);

        app(LdapService::class)->syncUser([
            'username' => 'root',
            'email' => 'root@example.com',
            'display_name' => 'Root',
            'dn' => 'uid=root,dc=example,dc=com',
            'is_admin' => false,
            'groups' => [],
            'groups_known' => true,
            'provider' => 'dir-a',
            'subject_hash' => str_repeat('a', 64),
        ]);

        $this->assertTrue((bool) $admin->fresh()?->is_admin);
    }

    public function test_an_ordinary_administrator_is_still_demoted_by_the_directory(): void
    {
        // The guard must be narrow: it protects one designated account, not every administrator.
        config()->set('ldap.sync', true);
        config()->set('ldap.admin_group', '');

        $this->protectedAdmin();
        $other = User::create([
            'username' => 'linked', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        LdapIdentity::create([
            'user_id' => $other->id,
            'provider' => 'dir-a',
            'subject_hash' => str_repeat('b', 64),
            'dn' => 'uid=linked,dc=example,dc=com',
        ]);

        app(LdapService::class)->syncUser([
            'username' => 'linked',
            'email' => 'linked@example.com',
            'display_name' => 'Linked',
            'dn' => 'uid=linked,dc=example,dc=com',
            'is_admin' => false,
            'groups' => [],
            'groups_known' => true,
            'provider' => 'dir-a',
            'subject_hash' => str_repeat('b', 64),
        ]);

        $this->assertFalse((bool) $other->fresh()?->is_admin);
    }

    public function test_the_console_refuses_to_demote_disable_or_force_sso_the_protected_account(): void
    {
        $admin = $this->protectedAdmin();
        $actor = $this->otherFullAdmin();

        $this->actingAs($actor)->putJson(route('admin.users.update', $admin), [
            'status' => User::STATUS_DISABLED,
            'login_verify' => User::LOGIN_VERIFY_OFF,
            'is_admin' => 0,
            'force_sso' => 1,
        ])->assertJsonValidationErrors(['is_admin', 'status', 'force_sso']);

        $fresh = $admin->fresh();
        $this->assertTrue((bool) $fresh?->is_admin);
        $this->assertSame(User::STATUS_NORMAL, (int) $fresh?->status);
        $this->assertFalse((bool) $fresh?->force_sso);
    }

    public function test_the_console_refuses_to_delete_the_protected_account(): void
    {
        $admin = $this->protectedAdmin();

        $this->actingAs($this->otherFullAdmin())
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.index'));

        $this->assertNotNull($admin->fresh());
    }

    public function test_a_bulk_action_cannot_disable_or_delete_the_protected_account(): void
    {
        // Bulk actions use mass builder writes, which fire no model events, so this path needs its
        // own guard and its own test.
        $admin = $this->protectedAdmin();
        $actor = $this->otherFullAdmin();
        $bystander = User::create([
            'username' => 'bystander', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($actor)->post(route('admin.users.bulk'), [
            'action' => 'disable',
            'ids' => [$admin->id, $bystander->id],
        ]);
        $this->assertSame(User::STATUS_NORMAL, (int) $admin->fresh()?->status);
        $this->assertSame(User::STATUS_DISABLED, (int) $bystander->fresh()?->status);

        $this->actingAs($actor)->post(route('admin.users.bulk'), [
            'action' => 'delete',
            'ids' => [$admin->id, $bystander->id],
        ]);
        $this->assertNotNull($admin->fresh());
        $this->assertNull($bystander->fresh());
    }

    public function test_at_most_one_account_can_hold_the_designation(): void
    {
        $first = $this->protectedAdmin();
        $second = $this->otherFullAdmin();

        ProtectedAdministrator::designate($second);

        $this->assertSame(1, User::where('is_protected_admin', true)->count());
        $this->assertSame((int) $second->id, (int) ProtectedAdministrator::current()?->id);
        $this->assertFalse((bool) $first->fresh()?->is_protected_admin);
    }

    public function test_the_designation_cannot_be_set_by_mass_assignment(): void
    {
        $user = User::create([
            'username' => 'sneaky', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
            'is_protected_admin' => true,
        ]);

        $this->assertFalse((bool) $user->fresh()?->is_protected_admin);
    }

    public function test_a_generated_password_satisfies_the_production_bootstrap_policy(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $password = GeneratedAdminPassword::create('admin');

            $this->assertGreaterThanOrEqual(12, mb_strlen($password));
            $this->assertLessThanOrEqual(255, mb_strlen($password));
            // The alphabet deliberately excludes characters that are ambiguous when transcribed.
            $this->assertDoesNotMatchRegularExpression('/[0O1Il]/', $password);
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}(-[A-Z2-9]{5})+$/', $password);
        }
    }

    public function test_seeding_twice_never_rotates_an_existing_password(): void
    {
        // A boot that silently changed the administrator's password would lock out whoever is
        // already using it, so this is the single most important property of the bootstrap.
        $this->seed(DatabaseSeeder::class);
        $before = User::where('username', 'admin')->value('password');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, User::where('username', 'admin')->value('password'));
    }

    public function test_the_seeded_administrator_is_designated_as_break_glass(): void
    {
        User::query()->delete();
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue((bool) User::where('username', 'admin')->value('is_protected_admin'));
    }

    public function test_the_initial_password_file_is_removed_when_the_account_signs_in(): void
    {
        $admin = $this->protectedAdmin();

        if (! is_writable(dirname(InitialAdminPassword::path()))) {
            $this->markTestSkipped('storage/app is not writable in this environment.');
        }

        file_put_contents(InitialAdminPassword::path(), "placeholder\n");
        $this->assertTrue(InitialAdminPassword::exists());

        $this->post('/admin/login', [
            'username' => $admin->username,
            'password' => 'secret12345',
        ]);

        $this->assertFalse(InitialAdminPassword::exists());
    }

    private function protectedAdmin(): User
    {
        // The migration designates an existing administrator, so start from a clean slate.
        DB::table('users')->update(['is_protected_admin' => false]);

        $admin = User::create([
            'username' => 'root', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        ProtectedAdministrator::designate($admin);

        return $admin->refresh();
    }

    private function otherFullAdmin(): User
    {
        return User::create([
            'username' => 'operator', 'password' => Hash::make('secret12345'),
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }
}
