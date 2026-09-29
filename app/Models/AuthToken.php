<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An authentication token issued to a client device for API access. Only the SHA-256 digest of
 * the bearer value is stored (`token_hash`); see token().
 *
 * @property int $credential_version
 */
#[Fillable([
    'user_id', 'credential_version', 'rustdesk_id', 'uuid', 'device_os', 'device_type', 'device_name',
    'token', 'expires_at', 'is_admin', 'status', 'last_used_at',
])]
#[Hidden(['token_hash'])]
class AuthToken extends Model
{
    use HasFactory;

    public const STATUS_REVOKED = 0;

    public const STATUS_ACTIVE = 1;

    /** The plaintext value set on this instance (never persisted; null once reloaded). */
    private ?string $plainToken = null;

    /**
     * SHA-256 digest under which a presented bearer value is stored and looked up.
     */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Only the digest is stored. Setting `token` records the digest in `token_hash` and keeps the
     * plaintext on this in-memory instance alone, so the issuing code can hand it to the client
     * exactly once. A token loaded from the database has no readable plaintext.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function token(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->plainToken,
            set: function (?string $value): array {
                $this->plainToken = $value;

                return ['token_hash' => $value === null ? null : self::hashToken($value)];
            },
        )->withoutObjectCaching();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'credential_version' => 'integer',
            'is_admin' => 'boolean',
            'status' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
