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

    /**
     * Resolve a deploy token string to a non-expired DeployToken, or null.
     */
    public function resolveToken(?string $token): ?DeployToken
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        $deployToken = DeployToken::with('user')->where('token', trim($token))->first();

        if (! $deployToken) {
            return null;
        }

        if ($deployToken->expires_at !== null && $deployToken->expires_at->isPast()) {
            return null;
        }

        // A long-lived token must not outlive the account or permission that authorized it.
        $owner = $deployToken->user;
        if (! $owner || ! $owner->isActive() || ! $owner->hasPermission('deploy.edit')) {
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

        // Owner: an explicit --user_name wins, otherwise the token's owner.
        $ownerId = $token->user_id;
        if (! empty($input['user_name'])) {
            $user = User::where('username', $input['user_name'])->first();
            if (! $user || ! $user->isActive()) {
                return 'Unknown user: '.$input['user_name'];
            }

            $tokenOwner = $token->user;
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

        if (! empty($input['strategy_name'])) {
            $strategy = Strategy::where('name', $input['strategy_name'])->first();
            if (! $strategy) {
                return 'Unknown strategy: '.$input['strategy_name'];
            }
            $device->strategy_id = $strategy->id;
        }

        if (! empty($input['device_group_name'])) {
            $device->device_group_id = DeviceGroup::firstOrCreate(['name' => (string) $input['device_group_name']])->id;
        }

        // Fall back to the default group when no explicit group was named.
        $device->device_group_id ??= DeviceGroup::defaultId();

        foreach (['device_username', 'device_name', 'note'] as $field) {
            if (! empty($input[$field])) {
                $device->{$field} = (string) $input[$field];
            }
        }

        $device->save();

        // File the device into a (possibly new) address book owned by the resolved user.
        if (! empty($input['address_book_name'])) {
            $book = AddressBook::firstOrCreate([
                'name' => (string) $input['address_book_name'],
                'user_id' => $ownerId,
            ]);

            AddressBookPeer::updateOrCreate(
                ['address_book_id' => $book->id, 'rustdesk_id' => $id],
                array_filter([
                    'user_id' => $ownerId,
                    'hostname' => $device->hostname,
                    'platform' => $device->os,
                    'alias' => $input['address_book_alias'] ?? null,
                    'password' => $input['address_book_password'] ?? null,
                    'note' => $input['address_book_note'] ?? null,
                    'tags' => ! empty($input['address_book_tag']) ? [$input['address_book_tag']] : null,
                ], static fn ($v) => $v !== null)
            );
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return '';
    }
}
