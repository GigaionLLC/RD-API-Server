<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Strategy;
use App\Models\StrategyAssignment;

/**
 * Resolves the effective Strategy for a device and produces the heartbeat payload the
 * RustDesk client consumes (docs/modernization/02-client-api-contract.md §1).
 *
 * Priority (highest first), matching RustDesk Server Pro:
 *   Device  >  User  >  Device Group
 * A direct devices.strategy_id acts as the device-level assignment shortcut.
 */
class StrategyService
{
    public function resolveForDevice(Device $device): ?Strategy
    {
        // Device-level direct assignment wins.
        if ($device->strategy_id) {
            $strategy = Strategy::where('id', $device->strategy_id)->where('enabled', true)->first();
            if ($strategy) {
                return $strategy;
            }
        }

        $tiers = [
            ['device', $device->id],
            ['user', $device->user_id],
            ['device_group', $device->device_group_id],
        ];

        foreach ($tiers as [$type, $targetId]) {
            if (! $targetId) {
                continue;
            }

            $assignment = StrategyAssignment::where('target_type', $type)
                ->where('target_id', $targetId)
                ->first();

            if ($assignment) {
                $strategy = Strategy::where('id', $assignment->strategy_id)
                    ->where('enabled', true)
                    ->first();
                if ($strategy) {
                    return $strategy;
                }
            }
        }

        // Lowest-priority fallback: the designated default strategy, so devices with no
        // device/user/group assignment still receive a policy instead of nothing.
        return Strategy::default();
    }

    /**
     * Build the heartbeat response fragment for a device. Returns an empty array when there
     * is nothing to push, or when the client's known timestamp already matches the server's.
     *
     * @return array<string, mixed>
     */
    public function heartbeatPayload(Device $device, int $clientModifiedAt): array
    {
        $strategy = $this->resolveForDevice($device);

        if (! $strategy) {
            return [];
        }

        $serverModifiedAt = (int) $strategy->modified_at;

        // Only push when the client's known version differs (the change-detection handshake).
        if ($serverModifiedAt === $clientModifiedAt) {
            return [];
        }

        return [
            'modified_at' => $serverModifiedAt,
            'strategy' => [
                // Both MUST serialize as JSON objects: the client deserializes them into
                // HashMap<String,String> (sync.rs StrategyOptions). An empty PHP array encodes
                // as "[]", which fails serde (expects a map) and silently drops the whole
                // strategy — the client keeps modified_at but never applies config_options.
                // Cast to object so empty becomes "{}".
                'config_options' => (object) ($strategy->options ?? []),
                'extra' => (object) ($strategy->extra ?? []),
            ],
        ];
    }
}
