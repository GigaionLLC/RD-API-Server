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
 * @property bool|null $is_personal
 * @property bool $is_shared
 */
#[Fillable(['user_id', 'is_personal', 'name', 'is_shared', 'note', 'max_peers'])]
class AddressBook extends Model
{
    use HasFactory;

    public const PERSONAL_NAME = 'My address book';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'is_shared' => 'boolean',
            'max_peers' => 'integer',
        ];
    }

    /**
     * Resolve the owner's durable personal collection. The database marker makes concurrent
     * first-use inserts converge on the same row through Eloquent's create-or-first retry.
     */
    public static function personalFor(User $user): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $user->id, 'is_personal' => true],
            ['name' => self::PERSONAL_NAME],
        );
    }

    /**
     * The peer cap that applies to this book: the per-book override when set, otherwise the
     * server-wide default. 0 = unlimited.
     */
    public function effectiveMaxPeers(): int
    {
        return $this->max_peers ?? (int) config('rustdesk.ab_max_peers', 0);
    }

    /**
     * Whether the book has reached its effective peer cap (always false when unlimited).
     */
    public function isFull(): bool
    {
        $limit = $this->effectiveMaxPeers();

        return $limit > 0 && $this->peers()->count() >= $limit;
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

        // Keep collaborator rows when sharing is paused so an owner can safely restore the
        // same access later, but make those grants dormant until sharing is enabled again.
        if (! $this->is_shared) {
            return null;
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
