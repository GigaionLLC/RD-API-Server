<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An address book collection owned by a user. May be marked shared, in which case other users
 * are granted access through AddressBookCollaborator rows (read / read-write / full control).
 *
 * @property bool $is_shared
 */
#[Fillable(['user_id', 'name', 'is_shared', 'note'])]
class AddressBook extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AddressBookPeer, $this>
     */
    public function peers(): HasMany
    {
        return $this->hasMany(AddressBookPeer::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * @return HasMany<AddressBookCollaborator, $this>
     */
    public function collaborators(): HasMany
    {
        return $this->hasMany(AddressBookCollaborator::class);
    }

    /**
     * The effective permission rule a user holds on this book, or null if they have none.
     * The owner always has full control; otherwise a collaborator row decides.
     */
    public function ruleFor(User $user): ?int
    {
        if ($this->user_id === $user->id) {
            return AddressBookCollaborator::RULE_FULL;
        }

        return $this->collaborators()
            ->where('user_id', $user->id)
            ->value('rule');
    }

    public function canRead(User $user): bool
    {
        return $this->ruleFor($user) !== null;
    }

    public function canWrite(User $user): bool
    {
        return ($this->ruleFor($user) ?? 0) >= AddressBookCollaborator::RULE_READ_WRITE;
    }
}
