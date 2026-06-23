<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's access grant on a shared address book. `rule` follows the RustDesk client's
 * ShareRule: read < readWrite < fullControl, so a numeric comparison gates capabilities.
 *
 * @property int $rule
 */
#[Fillable(['address_book_id', 'user_id', 'rule'])]
class AddressBookCollaborator extends Model
{
    use HasFactory;

    public const RULE_READ = 1;

    public const RULE_READ_WRITE = 2;

    public const RULE_FULL = 3;

    /**
     * Permission rules with human labels for the admin UI.
     *
     * @var array<int, string>
     */
    public const RULES = [
        self::RULE_READ => 'Read only',
        self::RULE_READ_WRITE => 'Read / write',
        self::RULE_FULL => 'Full control',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AddressBook, $this>
     */
    public function addressBook(): BelongsTo
    {
        return $this->belongsTo(AddressBook::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
