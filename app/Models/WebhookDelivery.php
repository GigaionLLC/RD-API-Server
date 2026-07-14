<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One delivery attempt-record for a webhook event. The payload is retained so a failed delivery
 * can be resent (manually from the console, or automatically by `webhooks:retry`).
 *
 * @property array<string, mixed> $payload
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 */
#[Fillable([
    'webhook_id', 'event', 'payload', 'status', 'status_code',
    'attempts', 'error', 'next_attempt_at', 'delivered_at',
])]
#[Hidden(['error'])]
class WebhookDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /** Stop retrying a delivery after this many attempts. */
    public const MAX_ATTEMPTS = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Webhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
