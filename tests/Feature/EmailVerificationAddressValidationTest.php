<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationAddressValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creation_requires_an_address_for_email_verification(): void
    {
        $admin = $this->admin();

        foreach ([
            'missing' => [],
            'null' => ['email' => null],
            'whitespace' => ['email' => " \t "],
        ] as $suffix => $emailInput) {
            $username = 'email-policy-without-address-'.$suffix;
            $this->actingAs($admin)->post(route('admin.users.store'), [
                'username' => $username,
                'password' => 'secret123456',
                'status' => User::STATUS_NORMAL,
                'login_verify' => User::LOGIN_VERIFY_EMAIL,
                ...$emailInput,
            ])->assertSessionHasErrors('email');

            $this->assertDatabaseMissing('users', ['username' => $username]);
        }

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'username' => 'email-policy-with-address',
            'email' => 'verified@example.test',
            'password' => 'secret123456',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_EMAIL,
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'username' => 'email-policy-with-address',
            'email' => 'verified@example.test',
            'login_verify' => User::LOGIN_VERIFY_EMAIL,
        ]);
    }

    public function test_admin_update_cannot_enable_email_verification_without_an_address(): void
    {
        $admin = $this->admin();
        $target = $this->user('email-policy-update-target');

        $this->actingAs($admin)->putJson(route('admin.users.update', $target), [
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_EMAIL,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $target->refresh();
        $this->assertSame(User::LOGIN_VERIFY_OFF, $target->login_verify);
        $this->assertNull($target->email);
    }

    public function test_admin_forms_explain_the_email_verification_requirement(): void
    {
        $admin = $this->admin();
        $target = $this->user('email-policy-copy-target');

        $this->actingAs($admin)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Required when login verification uses an email code.');

        $this->actingAs($admin)->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('Required when login verification uses an email code.');
    }

    public function test_admin_update_cannot_clear_an_email_verification_address(): void
    {
        $admin = $this->admin();
        $target = $this->emailVerificationUser('admin-email-policy-target');

        $this->actingAs($admin)->putJson(route('admin.users.update', $target), [
            'email' => null,
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_EMAIL,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $target->refresh();
        $this->assertSame('admin-email-policy-target@example.test', $target->email);
        $this->assertSame(User::LOGIN_VERIFY_EMAIL, $target->login_verify);
    }

    public function test_admin_can_explicitly_disable_email_verification_and_clear_the_address(): void
    {
        $admin = $this->admin();
        $target = $this->emailVerificationUser('admin-disable-email-policy-target');

        $this->actingAs($admin)->putJson(route('admin.users.update', $target), [
            'email' => null,
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_OFF,
        ])->assertOk();

        $target->refresh();
        $this->assertNull($target->email);
        $this->assertSame(User::LOGIN_VERIFY_OFF, $target->login_verify);
    }

    public function test_api_update_cannot_clear_an_email_verification_address_but_remains_partial(): void
    {
        $plain = $this->usersWriteKey();
        $target = $this->emailVerificationUser('api-email-policy-target');

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson("/api/v1/users/{$target->id}", ['email' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson("/api/v1/users/{$target->id}", ['display_name' => 'Address preserved'])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Address preserved');

        $target->refresh();
        $this->assertSame('api-email-policy-target@example.test', $target->email);
        $this->assertSame(User::LOGIN_VERIFY_EMAIL, $target->login_verify);
    }

    private function admin(): User
    {
        return User::create([
            'username' => 'email-policy-admin',
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

    private function emailVerificationUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => 'secret123456',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_EMAIL,
        ]);
    }

    private function usersWriteKey(): string
    {
        $admin = User::create([
            'username' => 'email-policy-api-admin',
            'password' => 'secret123456',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $admin->id,
            'name' => 'Email policy writer',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => ['users.write'],
        ]);

        return $plain;
    }
}
