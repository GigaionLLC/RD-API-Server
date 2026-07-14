<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Applies the explicit opt-in and abuse bounds for unauthenticated device registration.
 */
final class DeviceAutoRegistrationGuard
{
    private const DECAY_SECONDS = 60;

    public function register(Request $request, string $rustdeskId, string $uuid): ?Device
    {
        if ((bool) config('rustdesk.devices.require_deployment', true)
            || ! (bool) config('rustdesk.devices.auto_register', false)) {
            return null;
        }

        $sourceIp = (string) ($request->ip() ?? '');
        if ($sourceIp === '' || strlen($sourceIp) > 45) {
            return null;
        }

        $ipKey = 'rd:device-auto-register:ip:'.hash('sha256', $sourceIp);
        if (! $this->consume($ipKey, $this->limit('per_ip_per_minute', 30))) {
            return null;
        }

        if (! $this->consume('rd:device-auto-register:global', $this->limit('global_per_minute', 100))) {
            return null;
        }

        if (Device::query()->count() >= $this->limit('max_devices', 5000)) {
            return null;
        }

        try {
            return Device::create([
                'rustdesk_id' => $rustdeskId,
                'uuid' => $uuid,
                'approved' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request may have registered this id between the lookup and insert.
            // It is safe to reuse only when it established the exact same approved identity.
            $device = Device::query()->where('rustdesk_id', $rustdeskId)->first();
            if ($device === null
                || ! $device->approved
                || (string) $device->uuid === ''
                || ! hash_equals((string) $device->uuid, $uuid)) {
                return null;
            }

            return $device;
        }
    }

    private function consume(string $key, int $maxAttempts): bool
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return true;
    }

    private function limit(string $key, int $default): int
    {
        return max(1, (int) config('rustdesk.devices.auto_registration.'.$key, $default));
    }
}
