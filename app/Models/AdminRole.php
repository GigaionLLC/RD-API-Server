<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A scoped admin role granting delegated console permissions (Admin Role Layer 3,
 * docs/modernization/12-access-control-design.md §Layer 3). A user may hold several roles;
 * their effective permissions are the union. A `global` role implies every permission.
 */
#[Fillable(['name', 'type', 'scope', 'perms'])]
class AdminRole extends Model
{
    use HasFactory;

    public const TYPE_GLOBAL = 'global';

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_GROUP = 'group';

    /**
     * Allowed role types.
     *
     * @var array<int, string>
     */
    public const TYPES = [
        self::TYPE_GLOBAL,
        self::TYPE_INDIVIDUAL,
        self::TYPE_GROUP,
    ];

    /**
     * The canonical permission catalogue, grouped by console area. The keys are the area
     * labels used in the UI; each value is the list of permission strings for that area.
     *
     * @var array<string, array<int, string>>
     */
    public const PERMISSION_CATALOG = [
        'Dashboard' => ['dashboard.view'],
        'Devices' => ['devices.view', 'devices.edit'],
        'Users' => ['users.view', 'users.edit'],
        'Groups' => ['groups.view', 'groups.edit'],
        'Device Groups' => ['device_groups.view', 'device_groups.edit'],
        'Address Books' => ['address_books.view', 'address_books.edit'],
        'Strategies' => ['strategies.view', 'strategies.edit'],
        'Live Sessions' => ['sessions.view', 'sessions.edit'],
        'Audit Logs' => ['audit.view'],
        'Alarms' => ['alarms.view', 'alarms.edit'],
        'Recordings' => ['recordings.view', 'recordings.edit'],
        'Deploy' => ['deploy.view', 'deploy.edit'],
        'Webhooks' => ['webhooks.view', 'webhooks.edit'],
        'OAuth' => ['oauth.view', 'oauth.edit'],
        'LDAP' => ['ldap.view'],
        'Settings' => ['settings.view', 'settings.edit'],
        'API Keys' => ['api_keys.view', 'api_keys.edit'],
        // Role mutation is intentionally full-admin-only until the application has a
        // formal role hierarchy. Delegated administrators may inspect roles but cannot
        // rewrite a role assigned to themselves into a more privileged one.
        'Admin Roles' => ['roles.view'],
    ];

    /**
     * The flat list of every permission string in the catalogue.
     *
     * @return array<int, string>
     */
    public static function allPermissions(): array
    {
        return array_merge(...array_values(self::PERMISSION_CATALOG));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'perms' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'admin_role_user');
    }
}
