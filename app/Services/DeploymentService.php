<?php

namespace App\Services;

use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\DeployToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;

/**
 * Device deployment / CLI assignment (docs/modernization/02-client-api-contract.md §7).
 *
 * Backs `rustdesk.exe --deploy` / `--assign`: a device enrolls itself with a deployment
 * token, returning one of OK | NOT_ENABLED | INVALID_INPUT | ID_TAKEN.
 */
class DeploymentService
{
    public const RESULT_OK = 'OK';

    public const RESULT_NOT_ENABLED = 'NOT_ENABLED';

    public const RESULT_INVALID_INPUT = 'INVALID_INPUT';

    public const RESULT_ID_TAKEN = 'ID_TAKEN';

    /** Permission a deploy token's owner must hold; its admin scope bounds what the token may do. */
    private const PERMISSION = 'deploy.edit';

    public function __construct(private readonly AdminScopeService $scope) {}

    /**
     * Resolve a deploy token string to a non-expired DeployToken, or null.
     */
    public function resolveToken(?string $token): ?DeployToken
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        $deployToken = DeployToken::with('user')
            ->where('token_hash', DeployToken::hashToken(trim($token)))
            ->first();

        if (! $deployToken) {
            return null;
        }

        if ($deployToken->expires_at !== null && $deployToken->expires_at->isPast()) {
            return null;
        }

        // A long-lived token must not outlive the account or permission that authorized it.
        $owner = $deployToken->user;
        if (! $owner || ! $owner->isActive() || ! $owner->hasPermission(self::PERMISSION)) {
            return null;
        }

        if ((int) $deployToken->credential_version !== max(1, (int) $owner->credential_version)) {
            return null;
        }

        return $deployToken;
    }

    /**
     * Enroll a device. Returns one of the RESULT_* constants.
     */
    public function deploy(?DeployToken $token, string $id, string $uuid, string $pk): string
    {
        // Feature gate: deployment must be enabled and a valid token presented.
        if (! config('rustdesk.devices.require_deployment') || ! $token) {
            return self::RESULT_NOT_ENABLED;
        }

        $id = trim($id);
        $uuid = trim($uuid);

        if ($id === '' || $uuid === '') {
            return self::RESULT_INVALID_INPUT;
        }

        $existing = Device::where('rustdesk_id', $id)->first();

        // Existing identifiers can only be touched by the device that originally established
        // them. A blank legacy UUID has no identity proof and therefore fails closed.
        if ($existing && ((string) $existing->uuid === '' || ! hash_equals((string) $existing->uuid, $uuid))) {
            return self::RESULT_ID_TAKEN;
        }

        // A delegated owner's token cannot adopt a device owned outside their admin scope.
        if ($existing && ! $this->mayAdopt($token, $existing)) {
            return self::RESULT_ID_TAKEN;
        }

        $device = $existing ?: new Device(['rustdesk_id' => $id]);
        $device->device_group_id ??= DeviceGroup::ensureDefaultId();

        $device->fill([
            'uuid' => $uuid,
            'user_id' => $token->user_id,
            'approved' => true,
        ])->save();

        $token->forceFill(['last_used_at' => now()])->save();

        return self::RESULT_OK;
    }

    /**
     * Apply a `rustdesk --assign` request (POST /api/devices/cli): locate or register the
     * device, then apply the owner / strategy / device-group / address-book / identity
     * presets carried on the CLI. Shares the OPTION_PRESET_* vocabulary that
     * SystemController::applyPresets reads from sysinfo, but here it is explicit and
     * deploy-token authenticated.
     *
     * Returns '' on success (the client prints "Done!") or a human-readable error string
     * the client prints verbatim — see docs/modernization/02-client-api-contract.md §7.
     *
     * @param  array<string, mixed>  $input
     */
    public function assign(?DeployToken $token, array $input): string
    {
        if (! $token) {
            return 'Invalid or expired deployment token.';
        }

        $id = trim((string) ($input['id'] ?? ''));
        $uuid = trim((string) ($input['uuid'] ?? ''));
        if ($id === '') {
            return 'Device id is required.';
        }
        if ($uuid === '') {
            return 'Device uuid is required.';
        }

        $existing = Device::where('rustdesk_id', $id)->first();
        if ($existing && ((string) $existing->uuid === '' || ! hash_equals((string) $existing->uuid, $uuid))) {
            return 'This id is already taken by another device.';
        }

        $tokenOwner = $token->user;
        if ($existing && ! $this->mayAdopt($token, $existing)) {
            return "This device is outside the deployment token owner's administrative scope.";
        }

        // Owner: an explicit --user_name wins, otherwise the token's owner.
        $ownerId = $token->user_id;
        $userName = $this->inputString($input, 'user_name');
        if ($userName !== null) {
            $user = User::where('username', $userName)->first();
            if (! $user || ! $user->isActive()) {
                return 'Unknown user: '.$userName;
            }

            if ($user->id !== $token->user_id && ! $tokenOwner->is_admin) {
                return 'Only a full administrator can assign a device to another user.';
            }

            $ownerId = $user->id;
        }

        $device = $existing ?: new Device(['rustdesk_id' => $id]);
        $device->fill([
            'uuid' => $uuid !== '' ? $uuid : $device->uuid,
            'user_id' => $ownerId,
            'approved' => true,
        ]);

        $strategyName = $this->inputString($input, 'strategy_name');
        if ($strategyName !== null) {
            $strategy = Strategy::where('name', $strategyName)->first();
            if (! $strategy) {
                return 'Unknown strategy: '.$strategyName;
            }

            $allowed = $this->scope->strategyIds($tokenOwner, self::PERMISSION);
            if ($allowed !== null && ! in_array((int) $strategy->id, $allowed, true)) {
                return 'Strategy '.$strategyName." is outside the deployment token owner's administrative scope.";
            }

            $device->strategy_id = $strategy->id;
        }

        $groupName = $this->inputString($input, 'device_group_name');
        if ($groupName !== null) {
            $allowedGroups = $this->scope->deviceGroupIds($tokenOwner, self::PERMISSION);
            if ($allowedGroups === null) {
                // Unrestricted owners may still create a group on first use, as before.
                $device->device_group_id = DeviceGroup::firstOrCreate(['name' => $groupName])->id;
            } else {
                $groupId = DeviceGroup::where('name', $groupName)->value('id');
                if ($groupId === null || ! in_array((int) $groupId, $allowedGroups, true)) {
                    return 'Device group '.$groupName." is unknown or outside the deployment token owner's administrative scope.";
                }
                $device->device_group_id = (int) $groupId;
            }
        }

        // Fall back to the default group when no explicit group was named.
        $device->device_group_id ??= DeviceGroup::defaultId();

        foreach (['device_username', 'device_name', 'note'] as $field) {
            $value = $this->inputString($input, $field);
            if ($value !== null) {
                $device->{$field} = $value;
            }
        }

        $device->save();

        // File the device into a (possibly new) address book owned by the resolved user.
        $bookName = $this->inputString($input, 'address_book_name');
        if ($bookName !== null) {
            $book = AddressBook::firstOrCreate([
                'name' => $bookName,
                'user_id' => $ownerId,
            ]);

            $tag = $this->inputString($input, 'address_book_tag');
            AddressBookPeer::updateOrCreate(
                ['address_book_id' => $book->id, 'rustdesk_id' => $id],
                array_filter([
                    'user_id' => $ownerId,
                    'hostname' => $device->hostname,
                    'platform' => $device->os,
                    'alias' => $this->inputString($input, 'address_book_alias'),
                    'password' => $this->inputString($input, 'address_book_password'),
                    'note' => $this->inputString($input, 'address_book_note'),
                    'tags' => $tag !== null ? [$tag] : null,
                ], static fn ($v) => $v !== null)
            );
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return '';
    }

    /**
     * Whether the token may (re)enroll an existing device: always for an unrestricted owner;
     * otherwise only a device that is unowned or already inside the owner's admin scope.
     */
    private function mayAdopt(DeployToken $token, Device $device): bool
    {
        $owner = $token->user;
        if ($owner === null) {
            return false;
        }

        $allowed = $this->scope->deviceIds($owner, self::PERMISSION);

        return $allowed === null
            || $device->user_id === null
            || in_array((int) $device->id, $allowed, true);
    }

    /**
     * A CLI preset as a bounded, non-empty string, or null when absent / not a string.
     *
     * @param  array<string, mixed>  $input
     */
    private function inputString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || mb_strlen($value) > 255 ? null : $value;
    }
}
