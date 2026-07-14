<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Write coverage for the admin REST API (/api/v1): device reassignment, strategy and user
 * create/update, address-book create/delete — and that write scopes are enforced.
 */
class ApiV1WriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $scopes
     * @return array{0: User, 1: string}
     */
    private function makeKey(array $scopes): array
    {
        $user = User::create([
            'username' => 'op'.uniqid(), 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $user->id, 'name' => 'k', 'token_hash' => $hash,
            'prefix' => $prefix, 'scopes' => $scopes,
        ]);

        return [$user, $plain];
    }

    public function test_write_route_rejects_a_read_only_key(): void
    {
        [, $plain] = $this->makeKey(['devices.read']); // no devices.write
        $device = Device::create(['rustdesk_id' => 'd1', 'uuid' => 'u1']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson("/api/v1/devices/{$device->id}", ['alias' => 'x'])
            ->assertStatus(403);
    }

    public function test_device_reassignment(): void
    {
        [$owner, $plain] = $this->makeKey(['devices.write']);
        $device = Device::create(['rustdesk_id' => 'd1', 'uuid' => 'u1']);
        $group = DeviceGroup::create(['name' => 'Warehouse']);
        $strategy = Strategy::create(['name' => 'S', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson("/api/v1/devices/{$device->id}", [
                'user_id' => $owner->id,
                'device_group_id' => $group->id,
                'strategy_id' => $strategy->id,
                'alias' => 'Front desk',
            ])
            ->assertOk()
            ->assertJsonPath('data.alias', 'Front desk');

        $device->refresh();
        $this->assertSame($owner->id, $device->user_id);
        $this->assertSame($group->id, $device->device_group_id);
        $this->assertSame($strategy->id, $device->strategy_id);
    }

    public function test_strategy_create_and_update_bumps_modified_at(): void
    {
        [, $plain] = $this->makeKey(['strategies.write']);

        $created = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/strategies', [
                'name' => 'Locked', 'enabled' => true,
                'options' => ['enable-audio' => 'N'],
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame(['enable-audio' => 'N'], $created['options']);
        $id = $created['id'];
        $firstModified = $created['modified_at'];

        $res = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson("/api/v1/strategies/{$id}", [
                'options' => ['enable-audio' => 'N', 'access-mode' => 'view'],
            ])
            ->assertOk();

        $this->assertSame('view', $res->json('data.options.access-mode'));
        $this->assertGreaterThanOrEqual($firstModified, $res->json('data.modified_at'));
    }

    public function test_user_create_hashes_password_and_is_unique(): void
    {
        [, $plain] = $this->makeKey(['users.write']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/users', [
                'username' => 'techy', 'password' => 'changeme123', 'email' => 'techy@example.com',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.username', 'techy');

        $user = User::where('username', 'techy')->firstOrFail();
        $this->assertTrue(Hash::check('changeme123', $user->password));

        // Duplicate username is rejected.
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/users', ['username' => 'techy', 'password' => 'another123'])
            ->assertStatus(422);
    }

    public function test_user_update_is_partial(): void
    {
        [, $plain] = $this->makeKey(['users.write']);
        $u = User::create(['username' => 'bob', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson("/api/v1/users/{$u->id}", ['display_name' => 'Bob R.'])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Bob R.');

        $this->assertSame('bob', $u->refresh()->username); // untouched
    }

    public function test_address_book_create_and_delete(): void
    {
        [$owner, $plain] = $this->makeKey(['address_book.write', 'address_book.read']);

        $id = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/address-books', ['name' => 'Warehouse', 'is_shared' => true])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertDatabaseHas('address_books', ['id' => $id, 'user_id' => $owner->id, 'is_shared' => true]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->deleteJson("/api/v1/address-books/{$id}")
            ->assertOk()
            ->assertJsonPath('data', true);

        $this->assertDatabaseMissing('address_books', ['id' => $id]);
    }

    public function test_cannot_delete_another_users_address_book(): void
    {
        [, $plain] = $this->makeKey(['address_book.write']);
        $other = User::create(['username' => 'other', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);
        $book = AddressBook::create(['user_id' => $other->id, 'name' => 'Theirs']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->deleteJson("/api/v1/address-books/{$book->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('address_books', ['id' => $book->id]);
    }

    public function test_cannot_add_a_peer_to_another_users_address_book(): void
    {
        [, $plain] = $this->makeKey(['address_book.write']);
        $other = User::create(['username' => 'peer-other', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);
        $book = AddressBook::create(['user_id' => $other->id, 'name' => 'Theirs']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson("/api/v1/address-books/{$book->id}/peers", ['id' => 'cross-owner'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('address_book_peers', [
            'address_book_id' => $book->id,
            'rustdesk_id' => 'cross-owner',
        ]);
    }
}
