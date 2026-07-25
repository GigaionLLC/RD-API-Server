<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One federated authority decision: what changed, or why nothing did.
 */
#[Fillable([
    'user_id', 'username', 'provider_kind', 'provider_key', 'channel',
    'outcome', 'granted', 'revoked', 'matched_groups', 'groups_seen', 'ip',
])]
class SsoRoleSyncLog extends Model
{
    use HasFactory;

    /** Roles were reconciled and the effective set changed. */
    public const OUTCOME_CHANGED = 'changed';

    /** The provider answered and the effective set already matched. Not persisted by default. */
    public const OUTCOME_UNCHANGED = 'unchanged';

    /** The provider could not be asked, so nothing was granted and nothing was revoked. */
    public const OUTCOME_PROVIDER_ERROR = 'provider_error';

    /** The provider answered without a group claim at all. Never revokes. */
    public const OUTCOME_NO_CLAIM = 'no_claim';

    /** The account is a full administrator and is never altered by an identity provider. */
    public const OUTCOME_SKIPPED_FULL_ADMIN = 'skipped_full_admin';

    /** A mapping targeted a global role while that opt-in was disabled. */
    public const OUTCOME_REFUSED_GLOBAL = 'refused_global';

    /** Reconciliation itself failed. Authentication still succeeded. */
    public const OUTCOME_FAILED = 'failed';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted' => 'array',
            'revoked' => 'array',
            'matched_groups' => 'array',
            'groups_seen' => 'integer',
        ];
    }
}
