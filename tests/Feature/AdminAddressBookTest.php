<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin address-book manager: an admin can add / edit / delete peers and tags on ANY
 * user's address book (not just its own).
 */
class AdminAddressBookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private AddressBook $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $this->owner = User::create([
            'username' => 'alice', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
        ]);
        $this->book = AddressBook::create(['user_id' => $this->owner->id, 'name' => 'My address book']);
    }

    public function test_admin_adds_a_peer_to_another_users_book(): void
    {
        $this->actingAs($this->admin)->post(route('admin.address-books.peers.store', $this->book), [
            'rustdesk_id' => '123456789', 'alias' => 'Front desk', 'note' => 'lobby', 'tags' => ['work'],
        ])->assertRedirect(route('admin.address-books.show', $this->book));

        $peer = AddressBookPeer::where('address_book_id', $this->book->id)->where('rustdesk_id', '123456789')->first();
        $this->assertNotNull($peer);
        $this->assertSame($this->owner->id, $peer->user_id); // owned by the book's user, not the admin
        $this->assertSame('Front desk', $peer->alias);
        $this->assertSame(['work'], $peer->tags);
    }

    public function test_duplicate_peer_id_is_rejected(): void
    {
        AddressBookPeer::create([
            'address_book_id' => $this->book->id, 'user_id' => $this->owner->id, 'rustdesk_id' => '111',
        ]);

        $this->actingAs($this->admin)->post(route('admin.address-books.peers.store', $this->book), [
            'rustdesk_id' => '111',
        ])->assertSessionHasErrors('rustdesk_id');

        $this->assertSame(1, AddressBookPeer::where('rustdesk_id', '111')->count());
    }

    public function test_admin_edits_a_peer(): void
    {
        $peer = AddressBookPeer::create([
            'address_book_id' => $this->book->id, 'user_id' => $this->owner->id,
            'rustdesk_id' => '222', 'alias' => 'old', 'password' => 'keep-me',
        ]);

        $this->actingAs($this->admin)->put(route('admin.address-books.peers.update', $peer), [
            'rustdesk_id' => '222', 'alias' => 'new', 'tags' => ['a', 'b'], 'password' => '',
        ])->assertRedirect(route('admin.address-books.show', $this->book->id));

        $peer->refresh();
        $this->assertSame('new', $peer->alias);
        $this->assertSame(['a', 'b'], $peer->tags);
        $this->assertSame('keep-me', $peer->password); // blank password leaves it unchanged
    }

    public function test_admin_adds_a_tag_with_colour(): void
    {
        $this->actingAs($this->admin)->post(route('admin.address-books.tags.store', $this->book), [
            'name' => 'work', 'color' => '#ff0000',
        ])->assertRedirect();

        $tag = Tag::where('address_book_id', $this->book->id)->where('name', 'work')->first();
        $this->assertNotNull($tag);
        $this->assertSame((string) (0xFF000000 | 0xFF0000), $tag->color); // opaque ARGB int as text
    }

    public function test_renaming_a_tag_propagates_to_peers(): void
    {
        $tag = Tag::create(['address_book_id' => $this->book->id, 'user_id' => $this->owner->id, 'name' => 'old']);
        $peer = AddressBookPeer::create([
            'address_book_id' => $this->book->id, 'user_id' => $this->owner->id,
            'rustdesk_id' => '333', 'tags' => ['old', 'keep'],
        ]);

        $this->actingAs($this->admin)->put(route('admin.address-books.tags.update', $tag), [
            'name' => 'renamed', 'color' => '#00ff00',
        ])->assertRedirect();

        $this->assertSame(['renamed', 'keep'], $peer->fresh()->tags);
    }

    public function test_manager_page_renders(): void
    {
        AddressBookPeer::create([
            'address_book_id' => $this->book->id, 'user_id' => $this->owner->id, 'rustdesk_id' => '444',
        ]);

        $this->actingAs($this->admin)->get(route('admin.address-books.show', $this->book))
            ->assertOk()
            ->assertSee('Add ID')
            ->assertSee('444');
    }
}
