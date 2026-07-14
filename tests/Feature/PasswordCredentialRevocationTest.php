<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\ApiKey;
use App\Models\AuthToken;
use App\Models\DeployToken;
use App\Models\LdapIdentity;
use App\Models\User;
use App\Models\UserThird;
use App\Models\VerifyCode;
use App\Services\DeploymentService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordCredentialRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reset_revokes_every_target_credential_and_preserves_controls(): void
    {
        config(['session.driver' => 'database']);

        $admin = $this->user('reset-admin', true);
        $target = $this->user('reset-target');
        $control = $this->user('reset-control');
        $target->forceFill(['email' => 'target@example.com', 'remember_token' => 'old-remember-token'])->save();
        $control->forceFill(['email' => 'control@example.com'])->save();

        $targetAuth = $this->authToken($target, 'target-auth');
        $controlAuth = $this->authToken($control, 'control-auth');
        [, $targetKey] = $this->apiKey($target, 'Target key');
        [, $controlKey] = $this->apiKey($control, 'Control key');
        $targetDeploy = $this->deployToken($target, 'target-deploy');
        $controlDeploy = $this->deployToken($control, 'control-deploy');
        $targetCode = $this->verifyCode($target, 'target-challenge');
        $controlCode = $this->verifyCode($control, 'control-challenge');

        DB::table('password_reset_tokens')->insert([
            ['email' => 'target@example.com', 'token' => 'target-reset', 'created_at' => now()],
            ['email' => 'control@example.com', 'token' => 'control-reset', 'created_at' => now()],
        ]);
        $this->insertSession('target-session', (int) $target->id);
        $this->insertSession('control-session', (int) $control->id);

        $this->actingAs($admin)
            ->putJson(route('admin.users.password', $target), ['password' => 'replacement-password'])
            ->assertOk()
            ->assertExactJson([]);

        $target->refresh();
        $this->assertTrue(Hash::check('replacement-password', (string) $target->password));
        $this->assertSame(2, $target->credential_version);
        $this->assertNotSame('old-remember-token', $target->remember_token);
        $this->assertSame(AuthToken::STATUS_REVOKED, $targetAuth->refresh()->status);
        $this->assertSame(AuthToken::STATUS_ACTIVE, $controlAuth->refresh()->status);
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $targetCode->refresh()->status);
        $this->assertSame(VerifyCode::STATUS_ACTIVE, $controlCode->refresh()->status);
        $this->assertModelMissing($targetKey);
        $this->assertModelExists($controlKey);
        $this->assertModelMissing($targetDeploy);
        $this->assertModelExists($controlDeploy);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'target@example.com']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'control@example.com']);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'control-session']);
    }

    public function test_credentials_written_with_the_old_version_after_reset_are_rejected(): void
    {
        $target = $this->user('race-target', true);
        $target->forceFill(['credential_version' => 2])->save();
        $challengeSecret = str_repeat('a', 64);

        $auth = $this->authToken($target, 'raced-auth', 1);
        [$plainKey] = $this->apiKey($target, 'Raced key', 1);
        $this->deployToken($target, 'raced-deploy', 1);
        $challenge = $this->verifyCode($target, $challengeSecret, 1, '123456');

        $this->withHeader('Authorization', 'Bearer '.$auth->token)
            ->getJson('/api/user/info')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$plainKey)
            ->getJson('/api/v1/devices')
            ->assertUnauthorized();

        $this->assertNull(app(DeploymentService::class)->resolveToken('raced-deploy'));
        $this->assertFalse(app(TwoFactorService::class)->verifyEmailCode(
            $target,
            'rd-'.$challengeSecret,
            $challengeSecret,
            $challengeSecret,
            '123456',
        ));
        $this->assertSame(VerifyCode::STATUS_INACTIVE, $challenge->refresh()->status);

        $newLogin = $this->postJson('/api/login', [
            'username' => $target->username,
            'password' => 'secret12345',
        ])->assertOk();
        $this->assertSame(
            2,
            AuthToken::where('token', $newLogin->json('access_token'))->firstOrFail()->credential_version,
        );
    }

    public function test_api_password_update_revokes_credentials_and_clears_old_and_new_email_tokens(): void
    {
        $actor = $this->user('api-reset-admin', true);
        [$actorKey] = $this->apiKey($actor, 'Actor key', 1, ['users.write']);
        $target = $this->user('api-reset-target');
        $target->forceFill(['email' => 'old@example.com'])->save();
        $oldAuth = $this->authToken($target, 'api-target-auth');
        [, $oldKey] = $this->apiKey($target, 'Target key');

        DB::table('password_reset_tokens')->insert([
            ['email' => 'old@example.com', 'token' => 'old-reset', 'created_at' => now()],
            ['email' => 'new@example.com', 'token' => 'new-reset', 'created_at' => now()],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$actorKey)
            ->putJson("/api/v1/users/{$target->id}", [
                'password' => 'api-replacement-password',
                'email' => 'new@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');

        $target->refresh();
        $this->assertTrue(Hash::check('api-replacement-password', (string) $target->password));
        $this->assertSame(2, $target->credential_version);
        $this->assertSame(AuthToken::STATUS_REVOKED, $oldAuth->refresh()->status);
        $this->assertModelMissing($oldKey);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'old@example.com']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'new@example.com']);
    }

    public function test_cli_existing_user_reset_uses_the_same_revocation_boundary(): void
    {
        $target = $this->user('cli-reset-target');
        $oldAuth = $this->authToken($target, 'cli-target-auth');
        $oldDeploy = $this->deployToken($target, 'cli-target-deploy');

        $exit = Artisan::call('rustdesk:user', [
            'username' => $target->username,
            'password' => 'cli-replacement-password',
        ]);

        $this->assertSame(0, $exit);
        $target->refresh();
        $this->assertTrue(Hash::check('cli-replacement-password', (string) $target->password));
        $this->assertSame(2, $target->credential_version);
        $this->assertSame(AuthToken::STATUS_REVOKED, $oldAuth->refresh()->status);
        $this->assertModelMissing($oldDeploy);
    }

    public function test_self_reset_logs_out_and_returns_a_login_redirect(): void
    {
        $admin = $this->user('self-reset-admin', true);

        $response = $this->actingAs($admin)
            ->withSession(['auth.credential_version' => 1])
            ->putJson(route('admin.users.password', $admin), ['password' => 'self-replacement-password']);

        $response->assertOk()->assertJsonPath('redirect', route('admin.login'));
        $this->assertGuest();
        $this->assertSame(2, $admin->refresh()->credential_version);
        $this->assertDatabaseHas('console_audits', [
            'user_id' => $admin->id,
            'method' => 'PUT',
            'route_name' => 'admin.users.password',
        ]);
    }

    public function test_validation_failure_does_not_revoke_any_credentials(): void
    {
        $admin = $this->user('validation-admin', true);
        $target = $this->user('validation-target');
        $target->forceFill(['remember_token' => 'remember-me'])->save();
        $auth = $this->authToken($target, 'validation-auth');
        [, $key] = $this->apiKey($target, 'Validation key');
        $deploy = $this->deployToken($target, 'validation-deploy');

        $editUrl = route('admin.users.edit', $target);
        $this->actingAs($admin)
            ->from($editUrl)
            ->put(route('admin.users.password', $target), ['password' => 'short'])
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('password');

        $target->refresh();
        $this->assertSame(1, $target->credential_version);
        $this->assertSame('remember-me', $target->remember_token);
        $this->assertSame(AuthToken::STATUS_ACTIVE, $auth->refresh()->status);
        $this->assertModelExists($key);
        $this->assertModelExists($deploy);
    }

    public function test_full_and_delegated_admins_cannot_assign_local_passwords_to_federated_identities(): void
    {
        $admin = $this->user('federated-full-admin', true);
        $delegate = $this->user('federated-delegate');
        $role = AdminRole::create([
            'name' => 'User manager',
            'type' => AdminRole::TYPE_GLOBAL,
            'scope' => [],
            'perms' => ['users.view', 'users.edit'],
        ]);
        $delegate->adminRoles()->attach($role);

        $ldapUser = $this->user('linked-ldap-user');
        LdapIdentity::create([
            'user_id' => $ldapUser->id,
            'provider' => 'default',
            'subject_hash' => hash('sha256', 'ldap-subject'),
        ]);
        $ldapHash = $ldapUser->password;
        $ldapToken = $this->authToken($ldapUser, 'linked-ldap-auth');

        $ldapEdit = route('admin.users.edit', $ldapUser);
        $this->actingAs($admin)
            ->from($ldapEdit)
            ->put(route('admin.users.password', $ldapUser), ['password' => 'known-local-password'])
            ->assertRedirect($ldapEdit)
            ->assertSessionHasErrors('password');

        $ldapUser->refresh();
        $this->assertSame($ldapHash, $ldapUser->password);
        $this->assertSame(1, $ldapUser->credential_version);
        $this->assertSame(AuthToken::STATUS_ACTIVE, $ldapToken->refresh()->status);

        $oidcUser = $this->user('linked-oidc-user');
        UserThird::create([
            'user_id' => $oidcUser->id,
            'open_id' => 'oidc-subject',
            'type' => 'oidc',
            'op' => 'keycloak',
        ]);
        $oidcHash = $oidcUser->password;

        $oidcEdit = route('admin.users.edit', $oidcUser);
        $this->flushSession();
        $this->actingAs($delegate)
            ->from($oidcEdit)
            ->put(route('admin.users.password', $oidcUser), ['password' => 'delegate-known-password'])
            ->assertRedirect($oidcEdit)
            ->assertSessionHasErrors('password');

        $oidcUser->refresh();
        $this->assertSame($oidcHash, $oidcUser->password);
        $this->assertSame(1, $oidcUser->credential_version);
    }

    public function test_api_and_cli_federated_password_replacement_fail_without_partial_changes(): void
    {
        $actor = $this->user('federated-api-admin', true);
        [$actorKey] = $this->apiKey($actor, 'Federated actor key', 1, ['users.write']);
        $oidcUser = $this->user('federated-api-target');
        $oidcUser->forceFill(['email' => 'original@example.com'])->save();
        UserThird::create([
            'user_id' => $oidcUser->id,
            'open_id' => 'api-oidc-subject',
            'type' => 'oidc',
            'op' => 'keycloak',
        ]);
        $oidcHash = $oidcUser->password;

        $this->withHeader('Authorization', 'Bearer '.$actorKey)
            ->putJson("/api/v1/users/{$oidcUser->id}", [
                'password' => 'api-known-password',
                'email' => 'partial@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $oidcUser->refresh();
        $this->assertSame($oidcHash, $oidcUser->password);
        $this->assertSame('original@example.com', $oidcUser->email);
        $this->assertSame(1, $oidcUser->credential_version);

        $ldapUser = $this->user('federated-cli-target');
        $ldapUser->forceFill(['status' => User::STATUS_DISABLED])->save();
        LdapIdentity::create([
            'user_id' => $ldapUser->id,
            'provider' => 'default',
            'subject_hash' => hash('sha256', 'cli-ldap-subject'),
        ]);
        $ldapHash = $ldapUser->password;

        $exit = Artisan::call('rustdesk:user', [
            'username' => $ldapUser->username,
            'password' => 'cli-known-password',
            '--admin' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Linked LDAP and SSO identities', Artisan::output());
        $ldapUser->refresh();
        $this->assertSame($ldapHash, $ldapUser->password);
        $this->assertSame(User::STATUS_DISABLED, $ldapUser->status);
        $this->assertFalse($ldapUser->is_admin);
        $this->assertSame(1, $ldapUser->credential_version);
    }

    public function test_initial_browser_sessions_upgrade_but_changed_or_missing_versions_fail_closed(): void
    {
        $initial = $this->user('initial-session-admin', true);
        $this->actingAs($initial)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSessionHas('auth.credential_version', 1);

        $this->flushSession();

        $changed = $this->user('changed-session-admin', true);
        $changed->forceFill(['credential_version' => 2])->save();

        $this->actingAs($changed)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();

        $this->actingAs($changed->fresh())
            ->withSession(['auth.credential_version' => 1])
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();

        $this->actingAs($changed->fresh())
            ->withSession(['auth.credential_version' => 2])
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_new_login_seeds_the_current_browser_credential_version(): void
    {
        $admin = $this->user('fresh-version-login', true);
        $admin->forceFill(['credential_version' => 2])->save();

        $this->post('/admin/login', [
            'username' => $admin->username,
            'password' => 'secret12345',
        ])->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('auth.credential_version', 2);

        $this->assertAuthenticatedAs($admin);
    }

    private function user(string $username, bool $admin = false): User
    {
        return User::create([
            'username' => $username,
            'password' => 'secret12345',
            'is_admin' => $admin,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    private function authToken(User $user, string $token, int $version = 1): AuthToken
    {
        return AuthToken::create([
            'user_id' => $user->id,
            'credential_version' => $version,
            'token' => $token,
            'status' => AuthToken::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: string, 1: ApiKey}
     */
    private function apiKey(User $user, string $name, int $version = 1, array $scopes = ['devices.read']): array
    {
        [$plain, $prefix, $hash] = ApiKey::generateSecret();

        return [$plain, ApiKey::create([
            'user_id' => $user->id,
            'credential_version' => $version,
            'name' => $name,
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => $scopes,
        ])];
    }

    private function deployToken(User $user, string $token, int $version = 1): DeployToken
    {
        return DeployToken::create([
            'user_id' => $user->id,
            'credential_version' => $version,
            'token' => $token,
            'name' => $token,
        ]);
    }

    private function verifyCode(
        User $user,
        string $secret,
        int $version = 1,
        string $code = '654321',
    ): VerifyCode {
        return VerifyCode::create([
            'user_id' => $user->id,
            'credential_version' => $version,
            'type' => VerifyCode::TYPE_EMAIL,
            'uuid' => $secret,
            'rustdesk_id' => 'rd-'.$secret,
            'challenge_hash' => hash('sha256', $secret),
            'code' => Hash::make($code),
            'failed_attempts' => 0,
            'max_attempts' => 5,
            'status' => VerifyCode::STATUS_ACTIVE,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function insertSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }
}
