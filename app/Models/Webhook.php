<?php

namespace App\Models;

use App\Support\WebhookUrlRedactor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An outbound webhook / notification target. Subscribes to one or more server events and
 * delivers them to a Slack incoming webhook, a Telegram bot, or a generic JSON endpoint.
 *
 * @property array<int, string> $events
 * @property Carbon|null $last_triggered_at
 */
#[Fillable([
    'name', 'type', 'url', 'secret', 'events', 'enabled',
    'last_triggered_at', 'last_status', 'failure_count',
])]
#[Hidden(['url', 'secret'])]
class Webhook extends Model
{
    use HasFactory;

    public const TYPE_GENERIC = 'generic';

    public const TYPE_SLACK = 'slack';

    public const TYPE_TELEGRAM = 'telegram';

    /**
     * Delivery types, with human labels for the admin UI.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::TYPE_GENERIC => 'Generic JSON (POST)',
        self::TYPE_SLACK => 'Slack incoming webhook',
        self::TYPE_TELEGRAM => 'Telegram bot',
    ];

    /**
     * The events a webhook may subscribe to, with human labels for the admin UI.
     *
     * @var array<string, string>
     */
    public const EVENTS = [
        'alarm.raised' => 'Security / operational alarm raised',
        'connection.new' => 'Remote session started',
        'connection.closed' => 'Remote session ended',
        'device.new' => 'New device registered',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'enabled' => 'boolean',
            'last_triggered_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Whether this webhook is subscribed to the given event key.
     */
    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    /**
     * A display-safe destination label. Delivery code must continue using the raw `url` value.
     */
    public function redactedUrl(): string
    {
        return WebhookUrlRedactor::redact((string) $this->url, (string) $this->type);
    }

    /**
     * Remove this webhook's URL and shared secret from a transport or historical error.
     */
    public function redactSensitiveText(string $text): string
    {
        return WebhookUrlRedactor::redactText(
            $text,
            (string) $this->url,
            $this->secret === null ? null : (string) $this->secret,
            (string) $this->type,
        );
    }
}
