<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verification code (email or TOTP) issued to a user.
 *
 * @property int $credential_version
 */
#[Fillable([
    'user_id', 'credential_version', 'type', 'uuid', 'challenge_hash', 'code', 'failed_attempts',
    'max_attempts', 'rustdesk_id', 'status', 'expires_at', 'consumed_at',
])]
#[Hidden(['challenge_hash', 'code'])]
class VerifyCode extends Model
{
    use HasFactory;

    public const TYPE_EMAIL = 1;

    public const TYPE_TOTP = 2;

    public const STATUS_INACTIVE = 0;

    public const STATUS_ACTIVE = 1;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => 'integer',
            'credential_version' => 'integer',
            'status' => 'integer',
            'failed_attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
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
