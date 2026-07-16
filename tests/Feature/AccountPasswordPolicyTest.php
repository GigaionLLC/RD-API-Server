<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creation_and_reset_enforce_the_password_boundary(): void
    {
        $admin = $this->user('policy-admin', 'legacy-pass', true);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->adminCreatePayload('too-short', str_repeat('a', 11)))
            ->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['username' => 'too-short']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->adminCreatePayload('minimum-user', str_repeat('a', 12)))
            ->assertRedirect(route('admin.users.index'));
        $this->assertTrue(Hash::check(str_repeat('a', 12), User::where('username', 'minimum-user')->firstOrFail()->password));

        $maximum = str_repeat('b', 255);
        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->adminCreatePayload('maximum-user', $maximum))
            ->assertRedirect(route('admin.users.index'));
        $this->assertTrue(Hash::check($maximum, User::where('username', 'maximum-user')->firstOrFail()->password));

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->adminCreatePayload('too-long', str_repeat('c', 256)))
            ->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['username' => 'too-long']);

        $target = $this->user('reset-boundary-target', 'old-password');
        $originalHash = $target->password;

        $this->actingAs($admin)
            ->putJson(route('admin.users.password', $target), ['password' => str_repeat('d', 11)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->assertSame($originalHash, $target->refresh()->password);
        $this->assertSame(1, $target->credential_version);

        $this->actingAs($admin)
            ->putJson(route('admin.users.password', $target), ['password' => str_repeat('d', 12)])
            ->assertOk();
        $this->assertTrue(Hash::check(str_repeat('d', 12), $target->refresh()->password));
        $this->assertSame(2, $target->credential_version);

        $this->actingAs($admin)
            ->putJson(route('admin.users.password', $target), ['password' => str_repeat('e', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->assertSame(2, $target->refresh()->credential_version);
    }

    public function test_api_user_creation_and_update_enforce_the_password_boundary(): void
    {
        $admin = $this->user('policy-api-admin', 'legacy-pass', true);
        $plainKey = $this->apiKey($admin, ['users.write']);

        $this->withHeader('Authorization', 'Bearer '.$plainKey)
            ->postJson('/api/v1/users', ['username' => 'api-short', 'password' => str_repeat('a', 11)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->withHeader('Authorization', 'Bearer '.$plainKey)
            ->postJson('/api/v1/users', ['username' => 'api-minimum', 'password' => str_repeat('a', 12)])
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$plainKey)
            ->postJson('/api/v1/users', ['username' => 'api-long', 'password' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $target = $this->user('api-policy-target', 'old-password');
        $this->withHeader('Authorization', 'Bearer '.$plainKey)
            ->putJson("/api/v1/users/{$target->id}", ['password' => str_repeat('b', 11)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->assertSame(1, $target->refresh()->credential_version);

        $maximum = str_repeat('b', 255);
        $this->withHeader('Authorization', 'Bearer '.$plainKey)
            ->putJson("/api/v1/users/{$target->id}", ['password' => $maximum])
            ->assertOk();
        $this->assertTrue(Hash::check($maximum, $target->refresh()->password));
        $this->assertSame(2, $target->credential_version);
    }

    public function test_login_accepts_legacy_short_passwords_but_rejects_oversized_inputs(): void
    {
        $admin = $this->user('legacy-short-admin', 'shortpass', true);

        $this->post(route('admin.login'), [
            'username' => $admin->username,
            'password' => str_repeat('x', 256),
        ])->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->post(route('admin.login'), [
            'username' => $admin->username,
            'password' => 'shortpass',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);

        Auth::logout();
        $this->flushSession();

        $client = $this->user('legacy-short-client', 'tiny-pass');
        $this->postJson('/api/login', [
            'username' => $client->username,
            'password' => 'tiny-pass',
        ])->assertOk()->assertJsonStructure(['access_token']);

        $this->postJson('/api/login', [
            'username' => $client->username,
            'password' => str_repeat('x', 256),
        ])->assertOk()->assertExactJson(['error' => 'Invalid username or password']);

        $this->postJson('/api/login', [
            'username' => $client->username,
            'password' => ['not-a-string'],
        ])->assertOk()->assertExactJson(['error' => 'Invalid username or password']);

        $this->postJson('/api/login', [
            'username' => $client->username,
            'type' => 'email_code',
        ])->assertOk()->assertExactJson(['error' => 'Wrong or expired verification code']);
    }

    public function test_password_forms_expose_the_same_browser_boundaries(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('maxlength="255"', false)
            ->assertDontSee('minlength="12"', false);

        $admin = $this->user('policy-ui-admin', 'legacy-pass', true);
        $target = $this->user('policy-ui-target', 'legacy-pass');

        $this->actingAs($admin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('minlength="12" maxlength="255"', false);

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('minlength="12" maxlength="255"', false);

    }

    /** @return array<string, mixed> */
    private function adminCreatePayload(string $username, string $password): array
    {
        return [
            'username' => $username,
            'password' => $password,
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_OFF,
        ];
    }

    private function user(string $username, string $password, bool $admin = false): User
    {
        return User::create([
            'username' => $username,
            'password' => $password,
            'is_admin' => $admin,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    /** @param list<string> $scopes */
    private function apiKey(User $user, array $scopes): string
    {
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $user->id,
            'credential_version' => max(1, (int) $user->credential_version),
            'name' => 'Password policy key',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => $scopes,
        ]);

        return $plain;
    }
}
