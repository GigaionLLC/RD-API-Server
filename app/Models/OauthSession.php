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
 * @property array<string, mixed>|null $auth_body
 * @property Carbon $expires_at
 */
#[Fillable([
    'code', 'op', 'rustdesk_id', 'uuid', 'nonce', 'code_verifier',
    'device_os', 'device_type', 'device_name', 'auth_body', 'expires_at',
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
            'auth_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
