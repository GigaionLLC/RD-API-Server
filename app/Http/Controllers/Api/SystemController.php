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
            'cpu' => (string) $request->input('cpu', $device->cpu),
            'hostname' => (string) $request->input('hostname', $device->hostname),
            'memory' => (string) $request->input('memory', $device->memory),
            'os' => (string) $request->input('os', $device->os),
            'username' => (string) $request->input('username', $device->username),
            'version' => (string) $request->input('version', $device->version),
        ]);

        // Preset overrides for displayed identity.
        if ($v = $request->input('device_username')) {
            $device->device_username = $v;
        }
        if ($v = $request->input('device_name')) {
            $device->device_name = $v;
        }
        if ($v = $request->input('note')) {
            $device->note = $v;
        }

        // Default group for new / ungrouped devices (a device_group_name preset, applied in
        // applyPresets() below, still takes precedence over this).
        $device->device_group_id ??= DeviceGroup::defaultId();

        $device->save();

        $this->applyPresets($device, $request);

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
     */
    private function applyPresets(Device $device, Request $request): void
    {
        $dirty = false;

        // Assign to a named strategy.
        if ($name = $request->input('strategy_name')) {
            $strategy = Strategy::where('name', $name)->first();
            if ($strategy) {
                $device->strategy_id = $strategy->id;
                $dirty = true;
            }
        }

        // Assign to a (possibly new) device group.
        if ($name = $request->input('device_group_name')) {
            $group = DeviceGroup::firstOrCreate(['name' => $name]);
            $device->device_group_id = $group->id;
            $dirty = true;
        }

        if ($dirty) {
            $device->save();
        }

        // Auto-file the device into a (shared) address book.
        if ($abName = $request->input('address_book_name')) {
            $book = AddressBook::firstOrCreate(['name' => $abName, 'user_id' => $device->user_id]);

            $tag = $request->input('address_book_tag');
            AddressBookPeer::updateOrCreate(
                ['address_book_id' => $book->id, 'rustdesk_id' => $device->rustdesk_id],
                array_filter([
                    'user_id' => $device->user_id,
                    'hostname' => $device->hostname,
                    'platform' => $device->os,
                    'alias' => $request->input('address_book_alias'),
                    'password' => $request->input('address_book_password'),
                    'note' => $request->input('address_book_note'),
                    'tags' => $tag ? [$tag] : null,
                ], static fn ($v) => $v !== null)
            );
        }
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
