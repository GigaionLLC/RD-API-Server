<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scoped API keys + the admin REST API (/api/v1): authentication, scope enforcement,
 * expiry, and admin key management.
 */
class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $scopes
     * @return array{0: User, 1: string}
     */
    private function makeKey(array $scopes, ?string $expiresAt = null): array
    {
        $user = User::create([
            'username' => 'op'.uniqid(), 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $user->id, 'name' => 'k', 'token_hash' => $hash,
            'prefix' => $prefix, 'scopes' => $scopes, 'expires_at' => $expiresAt,
        ]);

        return [$user, $plain];
    }

    public function test_v1_requires_an_api_key(): void
    {
        $this->getJson('/api/v1/devices')->assertStatus(401);
    }

    public function test_v1_enforces_the_required_scope(): void
    {
        [, $plain] = $this->makeKey(['users.read']); // not devices.read

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/devices')
            ->assertStatus(403);
    }

    public function test_v1_devices_returns_data_with_the_right_scope(): void
    {
        [, $plain] = $this->makeKey(['devices.read']);
        Device::create(['rustdesk_id' => 'x1', 'uuid' => 'u1']);

        $this->withHeader('X-API-Key', $plain)
            ->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page']);
    }

    public function test_v1_address_book_write_creates_a_peer(): void
    {
        [$user, $plain] = $this->makeKey(['address_book.read', 'address_book.write']);
        $book = AddressBook::create(['user_id' => $user->id, 'name' => 'My address book']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson("/api/v1/address-books/{$book->id}/peers", ['id' => '555', 'alias' => 'PC'])
            ->assertStatus(201);

        $this->assertDatabaseHas('address_book_peers', ['address_book_id' => $book->id, 'rustdesk_id' => '555']);
    }

    public function test_v1_cannot_touch_another_users_address_book(): void
    {
        [, $plain] = $this->makeKey(['address_book.read']);
        $other = User::create(['username' => 'other', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);
        $book = AddressBook::create(['user_id' => $other->id, 'name' => 'Theirs']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson("/api/v1/address-books/{$book->id}/peers")
            ->assertStatus(403);
    }

    public function test_v1_address_book_list_is_owner_only_even_for_a_full_admin_key(): void
    {
        [$owner, $plain] = $this->makeKey(['address_book.read']);
        $ownBook = AddressBook::create(['user_id' => $owner->id, 'name' => 'Mine']);
        $other = User::create(['username' => 'list-other', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);
        AddressBook::create(['user_id' => $other->id, 'name' => 'Theirs']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/address-books')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownBook->id);
    }

    public function test_expired_key_is_rejected(): void
    {
        [, $plain] = $this->makeKey(['devices.read'], now()->subDay()->toDateTimeString());

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/devices')
            ->assertStatus(401);
    }

    public function test_admin_creates_then_revokes_a_key(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.api-keys.store'), ['name' => 'CI', 'scopes' => ['devices.read']])
            ->assertSessionHas('new_api_key');

        $key = ApiKey::firstOrFail();
        $this->assertSame(['devices.read'], $key->scopes);

        $this->actingAs($admin)->delete(route('admin.api-keys.destroy', $key))->assertRedirect();
        $this->assertModelMissing($key);
    }
}
