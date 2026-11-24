<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A security/operational alarm raised for a peer or device (e.g. a new connection).
 */
#[Fillable([
    'device_id', 'peer_id', 'type', 'message', 'ip', 'client_nonce', 'conn_audit_ref', 'emailed',
])]
class Alarm extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_id' => 'integer',
            'emailed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
