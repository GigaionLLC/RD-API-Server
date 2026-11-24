<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An audit record of a file transfer event between peers.
 */
#[Fillable([
    'peer_id', 'from_peer', 'from_name', 'info', 'is_file', 'path', 'type', 'ip',
    'num', 'uuid', 'client_nonce',
])]
class AuditFile extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_file' => 'boolean',
            'type' => 'integer',
            'num' => 'integer',
        ];
    }
}
