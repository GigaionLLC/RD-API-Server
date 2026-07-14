<?php

namespace App\Services;

use App\Models\AdminRole;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Group;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the resource boundary attached to a delegated admin permission.
 *
 * Full administrators and global roles are unrestricted. Individual roles are limited to the
 * actor and devices they own. Group roles are limited to the selected user groups, their users,
 * and devices owned by those users (plus the legacy direct devices.group_id association).
 * Multiple roles are cumulative, but only roles granting the requested permission contribute.
 */
class AdminScopeService
{
    /** @var array<string, list<int>|null> */
    private array $idCache = [];

    /** @var array<string, list<string>|null> */
    private array $stringCache = [];

    public function isUnrestricted(User $user, string $permission): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $this->roles($user)
            ->contains(static fn (AdminRole $role): bool => $role->type === AdminRole::TYPE_GLOBAL);
    }

    /**
     * User ids inside this permission's boundary. Null means unrestricted.
     *
     * @return list<int>|null
     */
    public function userIds(User $user, string $permission): ?array
    {
        $key = $this->cacheKey($user, $permission, 'users');
        if (array_key_exists($key, $this->idCache)) {
            return $this->idCache[$key];
        }

        if ($this->isUnrestricted($user, $permission)) {
            return $this->idCache[$key] = null;
        }

        $ids = [];
        $groupIds = [];
        foreach ($this->scopedRoles($user, $permission) as $role) {
            if ($role->type === AdminRole::TYPE_INDIVIDUAL) {
                $ids[] = (int) $user->id;
            } elseif ($role->type === AdminRole::TYPE_GROUP) {
                $groupIds = array_merge($groupIds, $this->normalizedIds((array) $role->scope));
            }
        }

        if ($groupIds !== []) {
            $ids = array_merge($ids, User::query()
                ->whereIn('group_id', array_values(array_unique($groupIds)))
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all());
        }

        return $this->idCache[$key] = $this->uniqueIds($ids);
    }

    /**
     * Explicit user-group ids contributed by group-scoped roles. Individual roles deliberately
     * get no group-management authority because a group contains other accounts.
     *
     * @return list<int>|null
     */
    public function userGroupIds(User $user, string $permission): ?array
    {
        $key = $this->cacheKey($user, $permission, 'user-groups');
        if (array_key_exists($key, $this->idCache)) {
            return $this->idCache[$key];
        }

        if ($this->isUnrestricted($user, $permission)) {
            return $this->idCache[$key] = null;
        }

        $ids = [];
        foreach ($this->scopedRoles($user, $permission) as $role) {
            if ($role->type === AdminRole::TYPE_GROUP) {
                $ids = array_merge($ids, $this->normalizedIds((array) $role->scope));
            }
        }

        return $this->idCache[$key] = $this->uniqueIds($ids);
    }

    /**
     * Device ids inside this permission's boundary. Null means unrestricted.
     *
     * @return list<int>|null
     */
    public function deviceIds(User $user, string $permission): ?array
    {
        $key = $this->cacheKey($user, $permission, 'devices');
        if (array_key_exists($key, $this->idCache)) {
            return $this->idCache[$key];
        }

        if ($this->isUnrestricted($user, $permission)) {
            return $this->idCache[$key] = null;
        }

        $userIds = $this->userIds($user, $permission) ?? [];
        $groupIds = $this->userGroupIds($user, $permission) ?? [];
        if ($userIds === [] && $groupIds === []) {
            return $this->idCache[$key] = [];
        }

        $ids = Device::query()
            ->where(function (Builder $query) use ($userIds, $groupIds): void {
                if ($userIds !== []) {
                    $query->whereIn('user_id', $userIds);
                }
                if ($groupIds !== []) {
                    $method = $userIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('group_id', $groupIds);
                }
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $this->idCache[$key] = $this->uniqueIds($ids);
    }

    /**
     * RustDesk peer ids for devices inside this permission's boundary. Null means unrestricted.
     *
     * @return list<string>|null
     */
    public function devicePeerIds(User $user, string $permission): ?array
    {
        $key = $this->cacheKey($user, $permission, 'device-peers');
        if (array_key_exists($key, $this->stringCache)) {
            return $this->stringCache[$key];
        }

        $deviceIds = $this->deviceIds($user, $permission);
        if ($deviceIds === null) {
            return $this->stringCache[$key] = null;
        }
        if ($deviceIds === []) {
            return $this->stringCache[$key] = [];
        }

        return $this->stringCache[$key] = Device::query()
            ->whereIn('id', $deviceIds)
            ->pluck('rustdesk_id')
            ->map(static fn ($id): string => (string) $id)
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Device groups are exposed conservatively: a group must contain at least one in-scope
     * device and no out-of-scope devices. Empty or mixed-ownership groups remain global-only.
     *
     * @return list<int>|null
     */
    public function deviceGroupIds(User $user, string $permission): ?array
    {
        $key = $this->cacheKey($user, $permission, 'device-groups');
        if (array_key_exists($key, $this->idCache)) {
            return $this->idCache[$key];
        }

        $deviceIds = $this->deviceIds($user, $permission);
        if ($deviceIds === null) {
            return $this->idCache[$key] = null;
        }
        if ($deviceIds === []) {
            return $this->idCache[$key] = [];
        }

        $ids = DeviceGroup::query()
            ->whereHas('devices', fn (Builder $query) => $query->whereIn('devices.id', $deviceIds))
            ->whereDoesntHave('devices', fn (Builder $query) => $query->whereNotIn('devices.id', $deviceIds))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $this->idCache[$key] = $this->uniqueIds($ids);
    }

    /**
     * Strategies are in-scope only when they are not global defaults and every assignment is
     * resolvable inside the same permission boundary. Unassigned strategies are global-only.
     *
     * @return list<int>|null
     */
    public function strategyIds(User $user, string $permission): ?array
    {
        $key = $this->cacheKey($user, $permission, 'strategies');
        if (array_key_exists($key, $this->idCache)) {
            return $this->idCache[$key];
        }

        if ($this->isUnrestricted($user, $permission)) {
            return $this->idCache[$key] = null;
        }

        $deviceIds = array_flip($this->deviceIds($user, $permission) ?? []);
        $userIds = array_flip($this->userIds($user, $permission) ?? []);
        $deviceGroupIds = array_flip($this->deviceGroupIds($user, $permission) ?? []);

        $ids = Strategy::query()
            ->where('is_default', false)
            ->with('assignments')
            ->get()
            ->filter(function (Strategy $strategy) use ($deviceIds, $userIds, $deviceGroupIds): bool {
                if ($strategy->assignments->isEmpty()) {
                    return false;
                }

                return $strategy->assignments->every(static function (StrategyAssignment $assignment) use (
                    $deviceIds,
                    $userIds,
                    $deviceGroupIds,
                ): bool {
                    $id = (int) $assignment->target_id;

                    return match ($assignment->target_type) {
                        StrategyAssignment::TARGET_DEVICE => isset($deviceIds[$id]),
                        StrategyAssignment::TARGET_USER => isset($userIds[$id]),
                        StrategyAssignment::TARGET_DEVICE_GROUP => isset($deviceGroupIds[$id]),
                        default => false,
                    };
                });
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $this->idCache[$key] = $this->uniqueIds($ids);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeUsers(Builder $query, User $user, string $permission, string $column = 'id'): Builder
    {
        return $this->scopeIntegerIds($query, $this->userIds($user, $permission), $column);
    }

    /**
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    public function scopeDevices(Builder $query, User $user, string $permission, string $column = 'id'): Builder
    {
        return $this->scopeIntegerIds($query, $this->deviceIds($user, $permission), $column);
    }

    /**
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    public function scopeUserGroups(Builder $query, User $user, string $permission, string $column = 'id'): Builder
    {
        return $this->scopeIntegerIds($query, $this->userGroupIds($user, $permission), $column);
    }

    /**
     * @param  Builder<DeviceGroup>  $query
     * @return Builder<DeviceGroup>
     */
    public function scopeDeviceGroups(Builder $query, User $user, string $permission, string $column = 'id'): Builder
    {
        return $this->scopeIntegerIds($query, $this->deviceGroupIds($user, $permission), $column);
    }

    /**
     * @param  Builder<Strategy>  $query
     * @return Builder<Strategy>
     */
    public function scopeStrategies(Builder $query, User $user, string $permission, string $column = 'id'): Builder
    {
        return $this->scopeIntegerIds($query, $this->strategyIds($user, $permission), $column);
    }

    /**
     * Scope a peer-keyed log/resource query through its controlled RustDesk peer column.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopePeerRecords(Builder $query, User $user, string $permission, string $column = 'peer_id'): Builder
    {
        $ids = $this->devicePeerIds($user, $permission);
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($column, $ids);
    }

    /**
     * Scope any model carrying a user ownership/subject column (address books, login logs,
     * console audit rows, and similar records).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeUserOwnedRecords(
        Builder $query,
        User $user,
        string $permission,
        string $column = 'user_id',
    ): Builder {
        return $this->scopeIntegerIds($query, $this->userIds($user, $permission), $column);
    }

    /**
     * Scope records that may identify their device by either database id or RustDesk peer id.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeDeviceLinkedRecords(
        Builder $query,
        User $user,
        string $permission,
        string $deviceColumn = 'device_id',
        string $peerColumn = 'peer_id',
    ): Builder {
        $deviceIds = $this->deviceIds($user, $permission);
        $peerIds = $this->devicePeerIds($user, $permission);
        if ($deviceIds === null || $peerIds === null) {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($deviceIds, $peerIds, $deviceColumn, $peerColumn): void {
            $nested->whereIn($deviceColumn, $deviceIds)
                ->orWhereIn($peerColumn, $peerIds);
        });
    }

    public function authorizeUser(User $actor, User $subject, string $permission): void
    {
        $this->authorizeIntegerId($this->userIds($actor, $permission), (int) $subject->id);
    }

    public function authorizeUserId(User $actor, int $userId, string $permission): void
    {
        $this->authorizeIntegerId($this->userIds($actor, $permission), $userId);
    }

    public function authorizeDevice(User $actor, Device $device, string $permission): void
    {
        $this->authorizeIntegerId($this->deviceIds($actor, $permission), (int) $device->id);
    }

    public function authorizeUserGroup(User $actor, int $groupId, string $permission): void
    {
        $this->authorizeIntegerId($this->userGroupIds($actor, $permission), $groupId);
    }

    public function authorizeDeviceGroup(User $actor, int $groupId, string $permission): void
    {
        $this->authorizeIntegerId($this->deviceGroupIds($actor, $permission), $groupId);
    }

    public function authorizeStrategy(User $actor, int $strategyId, string $permission): void
    {
        $this->authorizeIntegerId($this->strategyIds($actor, $permission), $strategyId);
    }

    /** @param list<int> $ids */
    public function authorizeUserIds(User $actor, array $ids, string $permission): void
    {
        $this->authorizeIntegerIds($this->userIds($actor, $permission), $ids);
    }

    /** @param list<int> $ids */
    public function authorizeDeviceIds(User $actor, array $ids, string $permission): void
    {
        $this->authorizeIntegerIds($this->deviceIds($actor, $permission), $ids);
    }

    public function authorizePeerId(User $actor, string $peerId, string $permission): void
    {
        $allowed = $this->devicePeerIds($actor, $permission);
        if ($allowed !== null && ! in_array($peerId, $allowed, true)) {
            abort(403, 'This resource is outside your administrative scope.');
        }
    }

    public function authorizeDeviceLinkedRecord(
        User $actor,
        ?int $deviceId,
        string $peerId,
        string $permission,
    ): void {
        $deviceIds = $this->deviceIds($actor, $permission);
        if ($deviceIds === null) {
            return;
        }

        $peerIds = $this->devicePeerIds($actor, $permission) ?? [];
        if (($deviceId === null || ! in_array($deviceId, $deviceIds, true))
            && ! in_array($peerId, $peerIds, true)) {
            abort(403, 'This resource is outside your administrative scope.');
        }
    }

    public function authorizeUnrestricted(User $actor, string $permission): void
    {
        if (! $this->isUnrestricted($actor, $permission)) {
            abort(403, 'This operation requires an unrestricted administrator.');
        }
    }

    /**
     * @return Collection<int, AdminRole>
     */
    private function roles(User $user): Collection
    {
        $user->loadMissing('adminRoles');

        return $user->adminRoles;
    }

    /**
     * @return Collection<int, AdminRole>
     */
    private function scopedRoles(User $user, string $permission): Collection
    {
        return $this->roles($user)->filter(static fn (AdminRole $role): bool => in_array($role->type, [AdminRole::TYPE_INDIVIDUAL, AdminRole::TYPE_GROUP], true)
            && in_array($permission, (array) $role->perms, true));
    }

    private function cacheKey(User $user, string $permission, string $resource): string
    {
        return $user->getKey().'|'.$permission.'|'.$resource;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function normalizedIds(array $values): array
    {
        return $this->uniqueIds(array_map(static fn ($value): int => (int) $value, $values));
    }

    /**
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeIntegerIds(Builder $query, ?array $ids, string $column): Builder
    {
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($column, $ids);
    }

    private function authorizeIntegerId(?array $allowed, int $id): void
    {
        if ($allowed !== null && ! in_array($id, $allowed, true)) {
            abort(403, 'This resource is outside your administrative scope.');
        }
    }

    /** @param list<int> $ids */
    private function authorizeIntegerIds(?array $allowed, array $ids): void
    {
        if ($allowed === null) {
            return;
        }

        foreach ($ids as $id) {
            if (! in_array((int) $id, $allowed, true)) {
                abort(403, 'One or more resources are outside your administrative scope.');
            }
        }
    }
}
