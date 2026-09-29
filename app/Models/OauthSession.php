<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A pending OIDC/OAuth device-login session (DB-backed so it is shared across API instances).
 * Keyed by the SHA-256 digest of the polling `code` the client echoes back (keyFor()); carries the
 * issued AuthBody, encrypted with the application key, once approved.
 *
 * `auth_body` is a raw JSON string (NOT an array cast) so the exact bytes — including an empty
 * `{}` object — are returned verbatim to the client; the Rust client deserializes it with serde,
 * which is stricter than the array-cast round-trip would preserve. It is encrypted at rest.
 *
 * @property string|null $auth_body
 * @property string|null $request_ip
 * @property int|null $user_id
 * @property string|null $confirm_hash
 * @property string|null $browser_hash
 * @property Carbon|null $resolved_at
 * @property int $delivery_count
 * @property Carbon|null $delivered_at
 * @property Carbon $expires_at
 */
#[Fillable([
    'code', 'op', 'rustdesk_id', 'uuid', 'nonce', 'code_verifier',
    'device_os', 'device_type', 'device_name', 'request_ip', 'auth_body', 'delivery_count',
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
            // Exact AuthBody JSON string, encrypted at rest (the `encrypted` cast round-trips the
            // string byte-for-byte).
            'auth_body' => 'encrypted',
            'resolved_at' => 'datetime',
            'user_id' => 'integer',
            'delivery_count' => 'integer',
        ];
    }

    /**
     * Primary-key value for a plaintext polling code. The code doubles as the provider `state` and
     * is a bearer-like secret, so only its digest is stored.
     */
    public static function keyFor(string $code): string
    {
        return hash('sha256', $code);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
