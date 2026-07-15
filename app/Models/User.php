<?php

namespace App\Models;

use App\Services\PermissionService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An account that can sign in to the admin console and/or the RustDesk client.
 * Mirrors the client UserPayload (see docs/modernization/02-client-api-contract.md §3b).
 *
 * @property int $credential_version
 */
#[Fillable([
    'username', 'email', 'password', 'display_name', 'avatar', 'is_admin', 'status',
    'force_sso', 'note', 'group_id', 'login_verify', 'two_factor_enabled', 'two_factor_secret',
    'two_factor_confirmed_at', 'two_factor_recovery_codes', 'email_alarm_notification',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STATUS_DISABLED = 0;

    public const STATUS_NORMAL = 1;

    public const STATUS_UNVERIFIED = -1;

    public const LOGIN_VERIFY_OFF = 'off';

    public const LOGIN_VERIFY_EMAIL = 'email';

    public const LOGIN_VERIFY_TOTP = 'totp';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'credential_version' => 'integer',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'force_sso' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'email_alarm_notification' => 'boolean',
            'status' => 'integer',
            'two_factor_recovery_codes' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_NORMAL;
    }

    /**
     * Scoped admin roles delegated to this account (Admin Role Layer 3,
     * docs/modernization/12-access-control-design.md §Layer 3).
     *
     * @return BelongsToMany<AdminRole, $this>
     */
    public function adminRoles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRole::class, 'admin_role_user');
    }

    /**
     * Whether this account holds the given console permission. `is_admin` always passes
     * (full access); otherwise the permission is granted if any of the user's admin roles
     * grants it. Named `hasPermission` to avoid clashing with the framework Gate's `can()`.
     */
    public function hasPermission(string $permission): bool
    {
        return app(PermissionService::class)->can($this, $permission);
    }
}
