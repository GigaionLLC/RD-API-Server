<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\LdapIdentity;
use App\Models\User;
use App\Services\LdapService;
use App\Support\RecentAdminAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LdapIdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('ldap.sync', false);
    }

    public function test_local_full_admin_username_collision_cannot_be_claimed_by_ldap(): void
    {
        $localAdmin = User::create([
            'username' => 'administrator',
            'email' => 'local-admin@example.test',
            'password' => 'local-admin-password',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        $attrs = $this->ldapAttributes('administrator', 'directory-subject-attacker');
        $this->bindAuthenticatedLdap($attrs);

        $response = $this->postJson('/api/login', [
            'username' => 'administrator',
            'password' => 'ldap-password',
            'id' => 'ldap-device',
            'uuid' => 'ldap-uuid',
            'type' => 'account',
        ]);

        $response->assertOk()
            ->assertJsonPath('type', 'access_token')
            ->assertJsonPath('user.is_admin', false);
        $linkedUsername = $response->json('user.name');
        $this->assertIsString($linkedUsername);
        $this->assertNotSame($localAdmin->username, $linkedUsername);
        $this->assertStringStartsWith('administrator-ldap-', $linkedUsername);

        $identity = LdapIdentity::firstOrFail();
        $linkedUser = User::findOrFail($identity->user_id);
        $token = AuthToken::firstOrFail();
        $this->assertSame($linkedUser->id, $token->user_id);
        $this->assertNotSame($localAdmin->id, $token->user_id);
        $this->assertFalse((bool) $linkedUser->is_admin);
        $this->assertTrue($linkedUser->adminRoles()->doesntExist());

        $localAdmin->refresh();
        $this->assertTrue((bool) $localAdmin->is_admin);
        $this->assertTrue(Hash::check('local-admin-password', (string) $localAdmin->password));
        $this->assertDatabaseMissing('ldap_identities', ['user_id' => $localAdmin->id]);

        // The admin login path must use the same linked non-admin, not the colliding full admin.
        $this->post('/admin/login', [
            'username' => 'administrator',
            'password' => 'ldap-password',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertSame(2, User::count());
        $this->assertSame(1, LdapIdentity::count());
    }

    public function test_ordinary_ldap_provisioning_works_for_admin_and_client_login(): void
    {
        $attrs = $this->ldapAttributes('directory-admin', 'ordinary-subject', true);
        $this->bindAuthenticatedLdap($attrs);

        $this->post('/admin/login', [
            'username' => 'directory-admin',
            'password' => 'ldap-password',
        ])->assertRedirect(route('admin.dashboard'));

        $identity = LdapIdentity::firstOrFail();
        $linkedUser = User::findOrFail($identity->user_id);
        $this->assertAuthenticatedAs($linkedUser);
        $this->assertSame('directory-admin', $linkedUser->username);
        $this->assertTrue((bool) $linkedUser->is_admin);
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
        $this->post('/admin/login', [
            'username' => 'directory-admin',
            'password' => 'ldap-password',
        ])->assertRedirect(route('admin.2fa.show'));

        $this->assertAuthenticatedAs($linkedUser);
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('Set up authenticator');
        $this->assertTrue(app(RecentAdminAuthentication::class)->isValid(session()->driver(), $linkedUser->fresh()));

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->postJson('/api/login', [
            'username' => 'directory-admin',
            'password' => 'ldap-password',
            'id' => 'ordinary-device',
            'uuid' => 'ordinary-uuid',
            'type' => 'account',
        ])->assertOk()->assertJson([
            'type' => 'access_token',
            'user' => ['name' => 'directory-admin', 'is_admin' => true],
        ]);

        $this->assertSame(1, User::count());
        $this->assertSame(1, LdapIdentity::count());
        $this->assertDatabaseHas('auth_tokens', ['user_id' => $linkedUser->id]);
    }

    public function test_linked_identity_is_stable_when_directory_username_changes(): void
    {
        Config::set('ldap.sync', true);
        $service = app(LdapService::class);
        $original = $this->ldapAttributes('alice', 'stable-subject');
        $user = $service->syncUser($original);

        $renamed = $original;
        $renamed['username'] = 'alice-renamed';
        $renamed['email'] = 'alice-renamed@example.test';
        $renamed['display_name'] = 'Alice Renamed';
        $renamed['is_admin'] = true;
        $resolved = $service->syncUser($renamed);

        $this->assertSame($user->id, $resolved->id);
        $this->assertSame('alice', $resolved->username);
        $this->assertSame('alice-renamed@example.test', $resolved->email);
        $this->assertSame('Alice Renamed', $resolved->display_name);
        $this->assertTrue((bool) $resolved->is_admin);
        $this->assertDatabaseMissing('users', ['username' => 'alice-renamed']);
        $this->assertSame(1, User::count());
        $this->assertSame(1, LdapIdentity::count());
    }

    public function test_sync_cannot_relink_users_across_directory_subjects(): void
    {
        Config::set('ldap.sync', true);
        $service = app(LdapService::class);
        $firstAttrs = $this->ldapAttributes('shared-name', 'subject-one');
        $secondAttrs = $this->ldapAttributes('shared-name', 'subject-two');

        $first = $service->syncUser($firstAttrs);
        $second = $service->syncUser($secondAttrs);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('shared-name', $first->username);
        $this->assertNotSame($first->username, $second->username);
        $this->assertStringStartsWith('shared-name-ldap-', $second->username);

        $firstReplay = $firstAttrs;
        $firstReplay['username'] = $second->username;
        $secondReplay = $secondAttrs;
        $secondReplay['username'] = $first->username;

        $this->assertSame($first->id, $service->syncUser($firstReplay)->id);
        $this->assertSame($second->id, $service->syncUser($secondReplay)->id);
        $this->assertDatabaseHas('ldap_identities', [
            'user_id' => $first->id,
            'provider' => $firstAttrs['provider'],
            'subject_hash' => $firstAttrs['subject_hash'],
        ]);
        $this->assertDatabaseHas('ldap_identities', [
            'user_id' => $second->id,
            'provider' => $secondAttrs['provider'],
            'subject_hash' => $secondAttrs['subject_hash'],
        ]);
        $this->assertSame(2, User::count());
        $this->assertSame(2, LdapIdentity::count());
    }

    public function test_same_subject_in_a_different_provider_is_a_distinct_identity(): void
    {
        $service = app(LdapService::class);
        $firstAttrs = $this->ldapAttributes('provider-user', 'shared-subject', false, 'directory-a');
        $secondAttrs = $this->ldapAttributes('provider-user', 'shared-subject', false, 'directory-b');

        $first = $service->syncUser($firstAttrs);
        $second = $service->syncUser($secondAttrs);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, LdapIdentity::count());
        $this->assertDatabaseHas('ldap_identities', [
            'user_id' => $first->id,
            'provider' => 'directory-a',
        ]);
        $this->assertDatabaseHas('ldap_identities', [
            'user_id' => $second->id,
            'provider' => 'directory-b',
        ]);
    }

    /**
     * @return array{username:string,email:string,display_name:string,dn:string,is_admin:bool,groups:array<int,string>,provider:string,subject_hash:string}
     */
    private function ldapAttributes(
        string $username,
        string $subject,
        bool $isAdmin = false,
        string $provider = 'corporate-directory',
    ): array {
        return [
            'username' => $username,
            'email' => $username.'@example.test',
            'display_name' => ucwords(str_replace('-', ' ', $username)),
            'dn' => 'uid='.$username.',ou=people,dc=example,dc=test',
            'is_admin' => $isAdmin,
            'groups' => [],
            'provider' => $provider,
            'subject_hash' => hash('sha256', $subject),
        ];
    }

    /**
     * @param  array{username:string,email:string,display_name:string,dn:string,is_admin:bool,groups:array<int,string>,provider:string,subject_hash:string}  $attrs
     */
    private function bindAuthenticatedLdap(array $attrs): void
    {
        $this->app->instance(LdapService::class, new TestAuthenticatedLdapService($attrs));
    }
}

final class TestAuthenticatedLdapService extends LdapService
{
    /**
     * @param  array{username:string,email:string,display_name:string,dn:string,is_admin:bool,groups:array<int,string>,provider:string,subject_hash:string}  $attrs
     */
    public function __construct(private readonly array $attrs) {}

    public function enabled(): bool
    {
        return true;
    }

    /**
     * @return array{username:string,email:string,display_name:string,dn:string,is_admin:bool,groups:array<int,string>,provider:string,subject_hash:string}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        return $password === 'ldap-password' ? $this->attrs : null;
    }
}
