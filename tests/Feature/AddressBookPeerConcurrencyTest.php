<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddressBookPeerConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        AddressBookPeer::flushEventListeners();
        parent::tearDown();
    }

    public function test_client_api_maps_a_late_duplicate_to_its_wire_compatible_error(): void
    {
        [$user, $token] = $this->clientUser('peer-client-race');
        $bookId = (int) $this->withToken($token)
            ->postJson('/api/ab/personal')
            ->assertOk()
            ->json('guid');
        $book = AddressBook::findOrFail($bookId);
        $this->injectCompetingPeer($book, 'client-race');

        $this->withToken($token)
            ->postJson('/api/ab/peer/add/personal', [
                'id' => 'client-race',
                'alias' => 'Losing request',
            ])
            ->assertOk()
            ->assertJsonPath('error', 'ID already exists');

        $this->assertWinnerPersisted($book, $user, 'client-race');
    }

    public function test_v1_api_maps_a_late_duplicate_to_validation_error(): void
    {
        $owner = User::create([
            'username' => 'peer-v1-race',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $owner->id,
            'name' => 'peer-race-key',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => ['address_book.write'],
        ]);
        $book = AddressBook::create(['user_id' => $owner->id, 'name' => 'V1 race book']);
        $this->injectCompetingPeer($book, 'v1-race');

        $this->withToken($plain)
            ->postJson("/api/v1/address-books/{$book->id}/peers", [
                'id' => 'v1-race',
                'alias' => 'Losing request',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'ID already exists in this address book');

        $this->assertWinnerPersisted($book, $owner, 'v1-race');
    }

    public function test_admin_maps_a_late_duplicate_to_the_existing_modal_error(): void
    {
        $admin = User::create([
            'username' => 'peer-admin-race',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        $owner = User::create([
            'username' => 'peer-admin-owner',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $book = AddressBook::create(['user_id' => $owner->id, 'name' => 'Admin race book']);
        $this->injectCompetingPeer($book, 'admin-race');

        $this->actingAs($admin)
            ->post(route('admin.address-books.peers.store', $book), [
                'rustdesk_id' => 'admin-race',
                'alias' => 'Preserved alias',
                'password' => 'do-not-flash',
            ])
            ->assertRedirect(route('admin.address-books.show', $book))
            ->assertSessionHasErrors(['rustdesk_id'], null, 'peer')
            ->assertSessionHas('address_book_modal', ['id' => 'peerModal', 'mode' => 'add'])
            ->assertSessionHasInput('alias', 'Preserved alias')
            ->assertSessionMissingInput('password');

        $this->assertWinnerPersisted($book, $owner, 'admin-race');
    }

    public function test_csv_import_counts_a_late_duplicate_as_skipped(): void
    {
        $admin = User::create([
            'username' => 'peer-import-race',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        $book = AddressBook::create(['user_id' => $admin->id, 'name' => 'Import race book']);
        $this->injectCompetingPeer($book, 'import-race');
        $file = UploadedFile::fake()->createWithContent(
            'peers.csv',
            "id,alias\nimport-race,Losing request\n",
        );

        $this->actingAs($admin)
            ->post(route('admin.address-books.import', $book), ['file' => $file])
            ->assertRedirect(route('admin.address-books.show', $book))
            ->assertSessionHas('status', 'Imported 0 peer(s); skipped 1.');

        $this->assertWinnerPersisted($book, $admin, 'import-race');
    }

    public function test_csv_duplicate_mapping_keeps_quota_count_correct_for_database_collation(): void
    {
        config(['rustdesk.ab_max_peers' => 2]);
        $admin = User::create([
            'username' => 'peer-import-collation',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        $book = AddressBook::create(['user_id' => $admin->id, 'name' => 'Import collation book']);
        AddressBookPeer::create([
            'address_book_id' => $book->id,
            'user_id' => $admin->id,
            'rustdesk_id' => 'Case-ID',
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'peers.csv',
            "id,alias\ncase-id,Duplicate spelling\nnew-id,Available slot\n",
        );

        $this->actingAs($admin)
            ->post(route('admin.address-books.import', $book), ['file' => $file])
            ->assertRedirect(route('admin.address-books.show', $book))
            ->assertSessionHas('status', 'Imported 1 peer(s); skipped 1.');

        $this->assertSame(2, AddressBookPeer::where('address_book_id', $book->id)->count());
        $this->assertDatabaseHas('address_book_peers', [
            'address_book_id' => $book->id,
            'rustdesk_id' => 'new-id',
            'alias' => 'Available slot',
        ]);
    }

    public function test_legacy_full_replace_collapses_duplicate_payload_ids_transactionally(): void
    {
        [$user, $token] = $this->clientUser('peer-legacy-duplicate');
        $payload = json_encode([
            'tags' => [],
            'peers' => [
                ['id' => 'legacy-duplicate', 'alias' => 'First alias'],
                ['id' => 'legacy-duplicate', 'alias' => 'Final alias'],
            ],
        ]);

        $this->withToken($token)
            ->postJson('/api/ab', ['data' => $payload])
            ->assertOk()
            ->assertContent('');

        $book = AddressBook::query()
            ->where('user_id', $user->id)
            ->where('is_personal', true)
            ->firstOrFail();
        $peers = AddressBookPeer::query()
            ->where('address_book_id', $book->id)
            ->where('rustdesk_id', 'legacy-duplicate')
            ->get();
        $this->assertCount(1, $peers);
        $this->assertSame('Final alias', $peers->firstOrFail()->alias);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function clientUser(string $username): array
    {
        $user = User::create([
            'username' => $username,
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $token = $this->postJson('/api/login', [
            'username' => $username,
            'password' => 'secret12345',
            'id' => $username.'-device',
            'uuid' => $username.'-uuid',
        ])->assertOk()->json('access_token');

        return [$user, $token];
    }

    private function injectCompetingPeer(AddressBook $book, string $rustdeskId): void
    {
        $inserted = false;
        AddressBookPeer::creating(function (AddressBookPeer $peer) use ($book, $rustdeskId, &$inserted): void {
            if ($inserted
                || $peer->address_book_id !== $book->id
                || $peer->rustdesk_id !== $rustdeskId) {
                return;
            }
            $inserted = true;

            DB::table('address_book_peers')->insert([
                'address_book_id' => $book->id,
                'user_id' => $book->user_id,
                'rustdesk_id' => $rustdeskId,
                'alias' => 'Concurrent winner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function assertWinnerPersisted(AddressBook $book, User $owner, string $rustdeskId): void
    {
        $peers = AddressBookPeer::query()
            ->where('address_book_id', $book->id)
            ->where('rustdesk_id', $rustdeskId)
            ->get();

        $this->assertCount(1, $peers);
        $this->assertSame($owner->id, $peers->firstOrFail()->user_id);
        $this->assertSame('Concurrent winner', $peers->firstOrFail()->alias);
    }
}
