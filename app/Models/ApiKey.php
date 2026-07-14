<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A scoped API key for the admin REST API. The plaintext secret is shown once at creation;
 * only its SHA-256 hash is stored.
 *
 * @property array<int, string> $scopes
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 */
#[Fillable([
    'user_id', 'name', 'token_hash', 'prefix', 'scopes', 'allowed_ips',
    'expires_at', 'last_used_at', 'last_used_ip',
])]
class ApiKey extends Model
{
    use HasFactory;

    /**
     * The scopes a key can be granted, with human labels for the admin UI.
     *
     * @var array<string, string>
     */
    public const SCOPES = [
        'devices.read' => 'Read devices',
        'devices.write' => 'Modify devices (owner / group / strategy)',
        'users.read' => 'Read users',
        'users.write' => 'Create / update users',
        'strategies.read' => 'Read strategies',
        'strategies.write' => 'Create / update strategies',
        'address_book.read' => 'Read address books',
        'address_book.write' => 'Modify address books & peers',
        'audit.read' => 'Read audit logs',
    ];

    /**
     * Console permission required to issue and continue using each API scope. Scoped keys
     * are delegated authority, so they must never outlive or exceed their owner's current
     * console permissions.
     *
     * @var array<string, string>
     */
    public const SCOPE_PERMISSIONS = [
        'devices.read' => 'devices.view',
        'devices.write' => 'devices.edit',
        'users.read' => 'users.view',
        'users.write' => 'users.edit',
        'strategies.read' => 'strategies.view',
        'strategies.write' => 'strategies.edit',
        'address_book.read' => 'address_books.view',
        'address_book.write' => 'address_books.edit',
        'audit.read' => 'audit.view',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this key carries the given scope (a `*` scope grants everything).
     */
    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    /**
     * Scope choices the given owner is currently allowed to delegate to a key.
     *
     * @return array<string, string>
     */
    public static function scopesAllowedFor(User $user): array
    {
        if ($user->is_admin) {
            return self::SCOPES;
        }

        return array_filter(
            self::SCOPES,
            static fn (string $label, string $scope): bool => $user->hasPermission(self::SCOPE_PERMISSIONS[$scope]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether a request from the given IP may use this key. An empty allowlist permits any IP;
     * otherwise the IP must match one of the comma-separated entries exactly.
     */
    public function ipAllowed(string $ip): bool
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) $this->allowed_ips))));

        return $list === [] || in_array($ip, $list, true);
    }

    /**
     * Generate a fresh plaintext secret. Returns [plaintext, prefix, hash]; store the hash,
     * show the plaintext to the admin exactly once.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function generateSecret(): array
    {
        $plain = 'rdk_'.Str::random(40);

        return [$plain, substr($plain, 0, 12), hash('sha256', $plain)];
    }
}
