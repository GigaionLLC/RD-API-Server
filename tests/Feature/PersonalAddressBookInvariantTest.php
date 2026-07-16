<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalAddressBookInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_endpoint_reuses_one_marked_book_and_ignores_same_named_ordinary_books(): void
    {
        $user = User::create([
            'username' => 'personal-owner',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $ordinary = AddressBook::create([
            'user_id' => $user->id,
            'name' => AddressBook::PERSONAL_NAME,
        ]);
        $token = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'secret12345',
            'id' => 'personal-device',
            'uuid' => 'personal-device-uuid',
        ])->assertOk()->json('access_token');

        $firstGuid = $this->withToken($token)
            ->postJson('/api/ab/personal')
            ->assertOk()
            ->assertJsonPath('name', AddressBook::PERSONAL_NAME)
            ->json('guid');
        $secondGuid = $this->withToken($token)
            ->postJson('/api/ab/personal')
            ->assertOk()
            ->json('guid');

        $this->assertSame($firstGuid, $secondGuid);
        $this->assertNotSame((string) $ordinary->id, $firstGuid);
        $this->assertSame(
            1,
            AddressBook::query()->where('user_id', $user->id)->where('is_personal', true)->count(),
        );
        $this->assertNull($ordinary->refresh()->is_personal);
    }

    public function test_database_allows_ordinary_books_but_rejects_invalid_or_duplicate_personal_markers(): void
    {
        $owner = User::create([
            'username' => 'marker-owner',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $other = User::create([
            'username' => 'marker-other',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);

        AddressBook::create(['user_id' => $owner->id, 'name' => 'Ordinary one']);
        AddressBook::create(['user_id' => $owner->id, 'name' => 'Ordinary two']);
        $personal = AddressBook::personalFor($owner);
        $this->assertTrue($personal->is_personal);
        $this->assertSame($personal->id, AddressBook::personalFor($owner)->id);
        $this->assertTrue(AddressBook::personalFor($other)->is_personal);

        $this->assertDatabaseRejects(
            [
                'user_id' => $owner->id,
                'is_personal' => true,
                'name' => 'Second personal book',
            ],
            'address_books_one_personal_per_user',
        );
        $this->assertDatabaseRejects(
            [
                'user_id' => $owner->id,
                'is_personal' => false,
                'name' => 'False marker',
            ],
            'address_books_personal_marker_valid',
        );
        $this->assertDatabaseRejects(
            [
                'user_id' => null,
                'is_personal' => true,
                'name' => 'Ownerless personal book',
            ],
            'address_books_personal_marker_valid',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertDatabaseRejects(array $attributes, string $constraint): void
    {
        try {
            AddressBook::create($attributes);
            $this->fail("The database accepted invalid address-book state for {$constraint}.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}
