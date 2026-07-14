<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookCollaborator;
use App\Models\AddressBookPeer;
use App\Models\AdminRole;
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

        $response = $this->actingAs($this->admin)->post(route('admin.address-books.peers.store', $this->book), [
            'rustdesk_id' => '111',
            'alias' => 'Desk & Lobby',
            'password' => 'do-not-flash',
        ]);

        $response
            ->assertRedirect(route('admin.address-books.show', $this->book))
            ->assertSessionHasErrors(['rustdesk_id'], null, 'peer')
            ->assertSessionHas('address_book_modal', ['id' => 'peerModal', 'mode' => 'add'])
            ->assertSessionHasInput('alias', 'Desk & Lobby')
            ->assertSessionMissingInput('password');

        $this->followingRedirects()->post(route('admin.address-books.peers.store', $this->book), [
            'rustdesk_id' => '111',
            'alias' => 'Desk & Lobby',
            'password' => 'do-not-flash',
        ])
            ->assertOk()
            ->assertSee('data-reopen="true"', false)
            ->assertSee('id="peer-error-summary"', false)
            ->assertSee('value="Desk &amp; Lobby"', false);

        $this->assertSame(1, AddressBookPeer::where('rustdesk_id', '111')->count());
    }

    public function test_invalid_peer_edit_restores_the_correct_dialog_action_and_values(): void
    {
        $peer = AddressBookPeer::create([
            'address_book_id' => $this->book->id,
            'user_id' => $this->owner->id,
            'rustdesk_id' => '222',
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.address-books.peers.update', $peer), [
            'rustdesk_id' => '222',
            'alias' => str_repeat('a', 256),
            'note' => 'Keep this note',
        ]);

        $response
            ->assertRedirect(route('admin.address-books.show', $this->book))
            ->assertSessionHasErrors(['alias'], null, 'peer')
            ->assertSessionHas('address_book_modal', [
                'id' => 'peerModal',
                'mode' => 'edit',
                'record_id' => $peer->id,
            ]);

        $this->followingRedirects()->put(route('admin.address-books.peers.update', $peer), [
            'rustdesk_id' => '222',
            'alias' => str_repeat('a', 256),
            'note' => 'Keep this note',
        ])
            ->assertOk()
            ->assertSee('id="peerModalTitle">Edit ID', false)
            ->assertSee('action="'.route('admin.address-books.peers.update', $peer).'"', false)
            ->assertSee('value="Keep this note"', false)
            ->assertSee('name="rustdesk_id" id="peerId" value="222"', false);
    }

    public function test_invalid_tag_submission_reopens_the_tag_dialog(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.address-books.tags.store', $this->book), [
            'name' => '',
            'color' => '#ff0000',
        ]);

        $response
            ->assertRedirect(route('admin.address-books.show', $this->book))
            ->assertSessionHasErrors(['name'], null, 'tag')
            ->assertSessionHas('address_book_modal', ['id' => 'tagModal', 'mode' => 'add']);

        $this->followingRedirects()->post(route('admin.address-books.tags.store', $this->book), [
            'name' => '',
            'color' => '#ff0000',
        ])
            ->assertOk()
            ->assertSee('id="tagModal"', false)
            ->assertSee('id="tag-error-summary"', false)
            ->assertSee('data-reopen="true"', false);
    }

    public function test_invalid_sharing_submission_reopens_the_share_dialog_with_old_input(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.address-books.sharing', $this->book), [
            'is_shared' => '1',
            'note' => 'Team & Operations',
            'max_peers' => -1,
        ]);

        $response
            ->assertRedirect(route('admin.address-books.show', $this->book))
            ->assertSessionHasErrors(['max_peers'], null, 'sharing')
            ->assertSessionHas('address_book_modal', ['id' => 'shareModal', 'section' => 'sharing']);

        $this->followingRedirects()->put(route('admin.address-books.sharing', $this->book), [
            'is_shared' => '1',
            'note' => 'Team & Operations',
            'max_peers' => -1,
        ])
            ->assertOk()
            ->assertSee('id="sharing-error-summary"', false)
            ->assertSee('value="Team &amp; Operations"', false)
            ->assertSee('value="-1"', false);
    }

    public function test_invalid_collaborator_submission_reopens_share_dialog_and_preserves_search(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.address-books.collaborators.store', $this->book), [
            'user_id' => '',
            'user_search' => 'Alice & Bob',
            'rule' => AddressBookCollaborator::RULE_READ_WRITE,
        ]);

        $response
            ->assertRedirect(route('admin.address-books.show', $this->book))
            ->assertSessionHasErrors(['user_id'], null, 'collaborator')
            ->assertSessionHas('address_book_modal', ['id' => 'shareModal', 'section' => 'collaborator']);

        $this->followingRedirects()->post(route('admin.address-books.collaborators.store', $this->book), [
            'user_id' => '',
            'user_search' => 'Alice & Bob',
            'rule' => AddressBookCollaborator::RULE_READ_WRITE,
        ])
            ->assertOk()
            ->assertSee('id="collaborator-error-summary"', false)
            ->assertSee('value="Alice &amp; Bob"', false)
            ->assertSee('data-reopen="true"', false);
    }

    public function test_collaborator_removal_uses_an_inline_confirmation(): void
    {
        $collaboratorUser = User::create([
            'username' => 'bob',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $collaborator = AddressBookCollaborator::create([
            'address_book_id' => $this->book->id,
            'user_id' => $collaboratorUser->id,
            'rule' => AddressBookCollaborator::RULE_READ,
        ]);

        $this->actingAs($this->admin)->get(route('admin.address-books.show', $this->book))
            ->assertOk()
            ->assertSee('aria-controls="collaborator-confirm-'.$collaborator->id.'"', false)
            ->assertSee('id="collaborator-confirm-'.$collaborator->id.'"', false)
            ->assertSee('data-collaborator-remove-confirmation', false);
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

    public function test_view_only_admin_sees_address_book_data_without_mutation_controls(): void
    {
        $role = AdminRole::create([
            'name' => 'Address book viewer',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['address_books.view'],
        ]);
        $viewer = User::create([
            'username' => 'viewer',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $viewer->adminRoles()->attach($role);

        $this->book->forceFill([
            'is_shared' => true,
            'note' => 'Operations collaboration note',
            'max_peers' => 25,
        ])->save();

        $peer = AddressBookPeer::create([
            'address_book_id' => $this->book->id,
            'user_id' => $this->owner->id,
            'rustdesk_id' => '555',
            'note' => 'Viewer-readable peer note',
        ]);
        $tag = Tag::create([
            'address_book_id' => $this->book->id,
            'user_id' => $this->owner->id,
            'name' => 'view-only',
        ]);
        $collaboratorUser = User::create([
            'username' => 'read-only-collaborator',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        AddressBookCollaborator::create([
            'address_book_id' => $this->book->id,
            'user_id' => $collaboratorUser->id,
            'rule' => AddressBookCollaborator::RULE_READ_WRITE,
        ]);

        $this->actingAs($viewer)->get(route('admin.address-books.index'))
            ->assertOk()
            ->assertSee('View')
            ->assertDontSee('<form method="POST" action="'.route('admin.address-books.destroy', $this->book).'"', false);

        $this->get(route('admin.address-books.show', $this->book))
            ->assertOk()
            ->assertSee('555')
            ->assertSee('view-only')
            ->assertSee('Viewer-readable peer note')
            ->assertSee('Operations collaboration note')
            ->assertSee('read-only-collaborator')
            ->assertSee('Read / write')
            ->assertSee('25')
            ->assertSee('Export')
            ->assertDontSee('data-bs-target="#peerModal"', false)
            ->assertDontSee('id="peerModal"', false)
            ->assertDontSee(route('admin.address-books.peers.update', $peer), false)
            ->assertDontSee(route('admin.address-books.peers.destroy', $peer), false)
            ->assertDontSee(route('admin.address-books.tags.update', $tag), false)
            ->assertDontSee(route('admin.address-books.tags.destroy', $tag), false)
            ->assertDontSee(route('admin.address-books.sharing', $this->book), false)
            ->assertDontSee(route('admin.address-books.import', $this->book), false);
    }
}
