<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single peer entry within an address book collection.
 *
 * @property array<int, string>|null $tags
 */
#[Fillable([
    'address_book_id', 'user_id', 'rustdesk_id', 'username', 'password', 'hostname',
    'alias', 'platform', 'tags', 'hash', 'force_always_relay', 'rdp_port',
    'rdp_username', 'login_name', 'note',
])]
#[Hidden(['password', 'hash'])]
class AddressBookPeer extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'force_always_relay' => 'boolean',
        ];
    }

    /**
     * Read from the writer so a just-won uniqueness race is visible before mapping its error.
     *
     * @phpstan-impure The answer can change between calls when another request commits.
     */
    public static function existsInBook(int $addressBookId, string $rustdeskId): bool
    {
        return self::query()
            ->useWritePdo()
            ->where('address_book_id', $addressBookId)
            ->where('rustdesk_id', $rustdeskId)
            ->exists();
    }

    /**
     * @return BelongsTo<AddressBook, $this>
     */
    public function addressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class);
    }
}
