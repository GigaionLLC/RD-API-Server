<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Admin address-book CSV export + import (peers), which round-trip through the same column
 * shape; existing IDs and over-cap rows are skipped on import.
 */
class AddressBookImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'admin'.uniqid(), 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_export_streams_peer_csv(): void
    {
        $book = AddressBook::create(['user_id' => $this->admin()->id, 'name' => 'Book']);
        AddressBookPeer::create(['address_book_id' => $book->id, 'user_id' => $book->user_id, 'rustdesk_id' => '111', 'alias' => 'PC', 'tags' => ['lobby']]);

        $csv = $this->actingAs($this->admin())
            ->get(route('admin.address-books.export', $book))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('id,alias,note,tags', $csv);
        $this->assertStringContainsString('111', $csv);
        $this->assertStringContainsString('lobby', $csv);
    }

    public function test_import_adds_new_peers_and_skips_existing(): void
    {
        $admin = $this->admin();
        $book = AddressBook::create(['user_id' => $admin->id, 'name' => 'Book']);
        AddressBookPeer::create(['address_book_id' => $book->id, 'user_id' => $book->user_id, 'rustdesk_id' => '111']);

        $csv = "id,alias,note,tags\n111,Dup,,\n222,Server,prod,web;db\n333,Laptop,,\n";
        $file = UploadedFile::fake()->createWithContent('peers.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.address-books.import', $book), ['file' => $file])
            ->assertRedirect();

        // 111 already existed (skipped); 222 + 333 added.
        $this->assertSame(3, AddressBookPeer::where('address_book_id', $book->id)->count());
        $peer = AddressBookPeer::where('address_book_id', $book->id)->where('rustdesk_id', '222')->firstOrFail();
        $this->assertSame('Server', $peer->alias);
        $this->assertSame(['web', 'db'], $peer->tags);
    }

    public function test_import_respects_the_peer_cap(): void
    {
        config(['rustdesk.ab_max_peers' => 2]);
        $admin = $this->admin();
        $book = AddressBook::create(['user_id' => $admin->id, 'name' => 'Book']);

        $csv = "id\n1\n2\n3\n4\n";
        $file = UploadedFile::fake()->createWithContent('peers.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.address-books.import', $book), ['file' => $file])
            ->assertRedirect();

        $this->assertSame(2, AddressBookPeer::where('address_book_id', $book->id)->count());
    }

    public function test_invalid_import_reopens_the_dialog_with_a_named_error_bag(): void
    {
        $admin = $this->admin();
        $book = AddressBook::create(['user_id' => $admin->id, 'name' => 'Book']);

        $response = $this->actingAs($admin)
            ->post(route('admin.address-books.import', $book));

        $response
            ->assertRedirect(route('admin.address-books.show', $book))
            ->assertSessionHasErrors(['file'], null, 'import')
            ->assertSessionHas('address_book_modal', ['id' => 'importModal']);

        $this->followingRedirects()->post(route('admin.address-books.import', $book))
            ->assertOk()
            ->assertSee('id="import-error-summary"', false)
            ->assertSee('data-reopen="true"', false)
            ->assertSee('Select the file again before importing.');
    }
}
