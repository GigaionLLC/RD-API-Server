<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Services\DeviceAutoRegistrationGuard;
use App\Services\StrategyService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Device telemetry endpoints from the RustDesk client contract
 * (docs/modernization/02-client-api-contract.md §1–§2).
 *
 * These are unauthenticated (the client posts them without a bearer token), keyed by the
 * device's rustdesk id + uuid.
 */
class SystemController extends Controller
{
    public function __construct(
        private readonly StrategyService $strategies,
        private readonly WebhookService $webhooks,
        private readonly DeviceAutoRegistrationGuard $autoRegistration,
    ) {}

    /**
     * POST /api/heartbeat
     * Records liveness and pushes the effective Strategy (Security-Settings sync).
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $rustdeskId = trim((string) $request->input('id', ''));
        $uuid = trim((string) $request->input('uuid', ''));

        if (! $this->validIdentityPart($rustdeskId)) {
            return response()->json(['error' => 'missing id']);
        }

        $device = Device::where('rustdesk_id', $rustdeskId)->first();
        $wasAutoRegistered = false;

        if ($device) {
            if (! $this->identityMatches($device, $uuid) || ! $device->approved) {
                return response()->json((object) []);
            }
        } else {
            if (! $this->validIdentityPart($uuid)) {
                return response()->json((object) []);
            }

            $device = $this->autoRegistration->register($request, $rustdeskId, $uuid);
            if ($device === null) {
                return response()->json((object) []);
            }

            $wasAutoRegistered = $device->wasRecentlyCreated;
        }

        // Place new / still-ungrouped devices into the default group (auto-provisioned when
        // none is designated) so a group-level strategy applies instead of them sitting in "None".
        $device->device_group_id ??= DeviceGroup::ensureDefaultId();

        $conns = $request->input('conns', []);
        $device->fill([
            'is_online' => true,
            'conns' => is_array($conns) ? count($conns) : 0,
            'last_online_at' => now(),
            'last_online_ip' => $request->ip(),
        ])->save();

        // Notify webhooks the first time we see a device (auto-registered on this heartbeat).
        if ($wasAutoRegistered) {
            $this->webhooks->dispatch('device.new', [
                'peer_id' => $rustdeskId,
                'uuid' => $uuid,
                'ip' => $request->ip(),
            ]);
        }

        $clientModifiedAt = (int) $request->input('modified_at', 0);
        $payload = $this->strategies->heartbeatPayload($device, $clientModifiedAt);

        // Force-disconnect: admins queue connection ids per device; deliver + clear them once.
        $disconnect = Cache::pull('rd:disconnect:'.$rustdeskId, []);
        if (! empty($disconnect)) {
            $payload['disconnect'] = array_values(array_unique(array_map('intval', (array) $disconnect)));
        }

        return response()->json($payload ?: (object) []);
    }

    /**
     * POST /api/sysinfo
     * Stores the device inventory and applies any baked-in presets (auto-registration).
     * Returns plain text: "SYSINFO_UPDATED" or "ID_NOT_FOUND".
     */
    public function sysinfo(Request $request): Response
    {
        $rustdeskId = trim((string) $request->input('id', ''));
        $uuid = trim((string) $request->input('uuid', ''));

        if (! $this->validIdentityPart($rustdeskId)) {
            return response('ID_NOT_FOUND')->header('Content-Type', 'text/plain');
        }

        $device = Device::where('rustdesk_id', $rustdeskId)->first();

        if ($device) {
            if (! $this->identityMatches($device, $uuid) || ! $device->approved) {
                return response('ID_NOT_FOUND')->header('Content-Type', 'text/plain');
            }
        } else {
            if (! $this->validIdentityPart($uuid)) {
                return response('ID_NOT_FOUND')->header('Content-Type', 'text/plain');
            }

            $device = $this->autoRegistration->register($request, $rustdeskId, $uuid);
            if ($device === null) {
                return response('ID_NOT_FOUND')->header('Content-Type', 'text/plain');
            }
        }

        $device->fill([
            'cpu' => $this->inventoryString($request, 'cpu', $device->cpu),
            'hostname' => $this->inventoryString($request, 'hostname', $device->hostname),
            'memory' => $this->inventoryString($request, 'memory', $device->memory),
            'os' => $this->inventoryString($request, 'os', $device->os),
            'username' => $this->inventoryString($request, 'username', $device->username),
            'version' => $this->inventoryString($request, 'version', $device->version),
        ]);

        // Device-supplied presets are honoured only inside the enrollment window: the first
        // accepted sysinfo upload after the device was registered/approved. Later uploads (or a
        // hand-crafted request from anyone who can read this machine's id + uuid) can no longer
        // move the device between strategies, groups or address books.
        $enrollmentWindow = $device->presets_applied_at === null;

        if ($enrollmentWindow) {
            $this->applyPresets($device, $request);
            $device->presets_applied_at = now();
        }

        // Default group for new / ungrouped devices (a device_group_name preset, applied above,
        // takes precedence over this).
        $device->device_group_id ??= DeviceGroup::defaultId();

        $device->save();

        if ($enrollmentWindow) {
            $this->applyAddressBookPreset($device, $request);
        }

        return response('SYSINFO_UPDATED')->header('Content-Type', 'text/plain');
    }

    /**
     * POST /api/sysinfo_ver — opaque version string the client uses to skip re-uploads.
     */
    public function sysinfoVer(): Response
    {
        return response((string) config('app.version'))->header('Content-Type', 'text/plain');
    }

    /**
     * Auto-registration from OPTION_PRESET_* keys (custom client / --assign).
     * docs/modernization/02-client-api-contract.md §2.
     *
     * Presets only fill blanks: an assignment an administrator (or an authenticated
     * `/api/devices/cli` deployment) already made is never overwritten. Strategies and device
     * groups are matched by exact name against existing rows; unknown names are ignored, so a
     * device can never create groups.
     */
    private function applyPresets(Device $device, Request $request): void
    {
        // Displayed identity overrides.
        foreach (['device_username', 'device_name', 'note'] as $field) {
            $value = $this->presetString($request, $field);
            if ($value !== null && (string) $device->{$field} === '') {
                $device->{$field} = $value;
            }
        }

        // Assign to a named strategy (only when none is assigned directly yet).
        $strategyName = $this->presetString($request, 'strategy_name');
        if ($strategyName !== null && $device->strategy_id === null) {
            $strategyId = Strategy::where('name', $strategyName)->value('id');
            if ($strategyId !== null) {
                $device->strategy_id = (int) $strategyId;
            }
        }

        // Join an existing device group, unless an explicit (non-default) group is already set.
        $groupName = $this->presetString($request, 'device_group_name');
        if ($groupName !== null
            && ($device->device_group_id === null || (int) $device->device_group_id === DeviceGroup::defaultId())) {
            $groupId = DeviceGroup::where('name', $groupName)->value('id');
            if ($groupId !== null) {
                $device->device_group_id = (int) $groupId;
            }
        }
    }

    /**
     * Auto-file the device into its owner's address book (enrollment window only).
     */
    private function applyAddressBookPreset(Device $device, Request $request): void
    {
        $abName = $this->presetString($request, 'address_book_name');
        if ($abName === null) {
            return;
        }

        $book = AddressBook::firstOrCreate(['name' => $abName, 'user_id' => $device->user_id]);

        $tag = $this->presetString($request, 'address_book_tag');
        AddressBookPeer::updateOrCreate(
            ['address_book_id' => $book->id, 'rustdesk_id' => $device->rustdesk_id],
            array_filter([
                'user_id' => $device->user_id,
                'hostname' => $device->hostname,
                'platform' => $device->os,
                'alias' => $this->presetString($request, 'address_book_alias'),
                'password' => $this->presetString($request, 'address_book_password'),
                'note' => $this->presetString($request, 'address_book_note'),
                'tags' => $tag !== null ? [$tag] : null,
            ], static fn ($v) => $v !== null)
        );
    }

    /**
     * A preset value as a bounded, non-empty string; anything else (arrays, objects, blanks,
     * oversized values) is treated as absent.
     */
    private function presetString(Request $request, string $key): ?string
    {
        $value = $request->input($key);
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || mb_strlen($value) > 255 ? null : $value;
    }

    /**
     * A client-reported inventory value; a non-scalar value keeps the stored one.
     */
    private function inventoryString(Request $request, string $key, mixed $current): string
    {
        $value = $request->input($key, $current);

        return is_scalar($value) ? (string) $value : (string) $current;
    }

    private function identityMatches(Device $device, string $uuid): bool
    {
        $storedUuid = (string) $device->uuid;

        return $this->validIdentityPart($uuid)
            && $storedUuid !== ''
            && hash_equals($storedUuid, $uuid);
    }

    private function validIdentityPart(string $value): bool
    {
        return $value !== '' && strlen($value) <= 255;
    }
}
