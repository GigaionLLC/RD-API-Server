<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A session recording file produced for a peer connection.
 */
#[Fillable([
    'peer_id', 'from_peer', 'conn_id', 'filename', 'path', 'size', 'status',
    'source_ip', 'started_at', 'finished_at',
])]
class Recording extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conn_id' => 'integer',
            'size' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
