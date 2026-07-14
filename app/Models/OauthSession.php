<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pending OIDC/OAuth device-login session (DB-backed so it is shared across API instances).
 * Keyed by the polling `code` the client echoes back; carries the issued AuthBody once resolved.
 *
 * `auth_body` is stored as a raw JSON string (NOT an array cast) so the exact bytes — including
 * an empty `{}` object — are returned verbatim to the client; the Rust client deserializes it
 * with serde, which is stricter than the array-cast round-trip would preserve.
 *
 * @property string|null $auth_body
 * @property int $delivery_count
 * @property Carbon|null $delivered_at
 * @property Carbon $expires_at
 */
#[Fillable([
    'code', 'op', 'rustdesk_id', 'uuid', 'nonce', 'code_verifier',
    'device_os', 'device_type', 'device_name', 'auth_body', 'delivery_count',
    'delivered_at', 'expires_at',
])]
class OauthSession extends Model
{
    use HasFactory;

    /** The primary key is the string `code`, not an auto-increment id. */
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_count' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
