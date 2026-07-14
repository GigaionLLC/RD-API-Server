<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An authentication token issued to a client device for API access.
 *
 * @property int $credential_version
 */
#[Fillable([
    'user_id', 'credential_version', 'rustdesk_id', 'uuid', 'device_os', 'device_type', 'device_name',
    'token', 'expires_at', 'is_admin', 'status', 'last_used_at',
])]
#[Hidden(['token'])]
class AuthToken extends Model
{
    use HasFactory;

    public const STATUS_REVOKED = 0;

    public const STATUS_ACTIVE = 1;

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
