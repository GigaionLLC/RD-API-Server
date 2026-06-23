<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookCollaborator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared / team address books: client-facing shared/profiles discovery, collaborator read vs
 * write gating on the granular endpoints, and admin sharing management.
 */
class SharedAddressBookTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::create([
            'username' => $name, 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);
    }

    private function tokenFor(string $name): string
    {
        $this->user($name);

        return $this->postJson('/api/login', [
            'username' => $name, 'password' => 'secret12345', 'id' => 'd', 'uuid' => 'u',
        ])->json('access_token');
    }

    public function test_shared_profiles_lists_books_shared_with_the_user(): void
    {
        $token = $this->tokenFor('member');
        $member = User::where('username', 'member')->firstOrFail();
        $owner = $this->user('boss');

        $book = AddressBook::create(['user_id' => $owner->id, 'name' => 'Team', 'is_shared' => true, 'note' => 'Shared book']);
        AddressBookCollaborator::create([
            'address_book_id' => $book->id, 'user_id' => $member->id,
            'rule' => AddressBookCollaborator::RULE_READ,
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/ab/shared/profiles')
            ->assertOk();

        $res->assertJsonPath('total', 1);
        $res->assertJsonPath('data.0.guid', (string) $book->id);
        $res->assertJsonPath('data.0.owner', 'boss');
        $res->assertJsonPath('data.0.rule', AddressBookCollaborator::RULE_READ);
    }

    public function test_read_only_collaborator_can_read_but_not_write(): void
    {
        $token = $this->tokenFor('reader');
        $reader = User::where('username', 'reader')->firstOrFail();
        $owner = $this->user('owner');

        $book = AddressBook::create(['user_id' => $owner->id, 'name' => 'Team', 'is_shared' => true]);
        AddressBookCollaborator::create([
            'address_book_id' => $book->id, 'user_id' => $reader->id,
            'rule' => AddressBookCollaborator::RULE_READ,
        ]);

        // Read is allowed.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/ab/peers', ['ab' => (string) $book->id])
            ->assertOk();

        // Write is rejected with an error envelope (not an empty ack).
        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/ab/peer/add/'.$book->id, ['id' => '777']);
        $res->assertOk();
        $this->assertStringContainsStringIgnoringCase('permission', $res->getContent());
        $this->assertDatabaseMissing('address_book_peers', ['address_book_id' => $book->id, 'rustdesk_id' => '777']);
    }

    public function test_read_write_collaborator_can_add_a_peer(): void
    {
        $token = $this->tokenFor('editor');
        $editor = User::where('username', 'editor')->firstOrFail();
        $owner = $this->user('owner');

        $book = AddressBook::create(['user_id' => $owner->id, 'name' => 'Team', 'is_shared' => true]);
        AddressBookCollaborator::create([
            'address_book_id' => $book->id, 'user_id' => $editor->id,
            'rule' => AddressBookCollaborator::RULE_READ_WRITE,
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/ab/peer/add/'.$book->id, ['id' => '888', 'alias' => 'Server']);
        $res->assertOk();
        $this->assertSame('', $res->getContent()); // empty ack on success
        $this->assertDatabaseHas('address_book_peers', ['address_book_id' => $book->id, 'rustdesk_id' => '888']);
    }

    public function test_non_collaborator_cannot_reach_a_shared_book(): void
    {
        $token = $this->tokenFor('stranger');
        $owner = $this->user('owner');
        $book = AddressBook::create(['user_id' => $owner->id, 'name' => 'Team', 'is_shared' => true]);

        // resolveBook falls back to the stranger's own personal book; the write lands there,
        // never in the shared book.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/ab/peer/add/'.$book->id, ['id' => '999'])
            ->assertOk();

        $this->assertDatabaseMissing('address_book_peers', ['address_book_id' => $book->id, 'rustdesk_id' => '999']);
    }

    public function test_admin_shares_a_book_and_adds_a_collaborator(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $owner = $this->user('owner');
        $collab = $this->user('teammate');
        $book = AddressBook::create(['user_id' => $owner->id, 'name' => 'Team']);

        $this->actingAs($admin)->put(route('admin.address-books.sharing', $book), [
            'is_shared' => '1', 'note' => 'Ops machines',
        ])->assertRedirect();
        $this->assertTrue($book->refresh()->is_shared);

        $this->actingAs($admin)->post(route('admin.address-books.collaborators.store', $book), [
            'user_id' => $collab->id, 'rule' => AddressBookCollaborator::RULE_READ_WRITE,
        ])->assertRedirect();

        $this->assertDatabaseHas('address_book_collaborators', [
            'address_book_id' => $book->id, 'user_id' => $collab->id,
            'rule' => AddressBookCollaborator::RULE_READ_WRITE,
        ]);
    }
}
