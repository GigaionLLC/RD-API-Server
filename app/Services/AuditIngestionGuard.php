<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Authenticates the fire-and-forget RustDesk audit feeds with the device id + UUID that the
 * upstream client already sends. Rejections are intentionally silent: the client ignores the
 * response body, so returning the usual empty acknowledgement preserves the wire contract while
 * preventing untrusted input from reaching the database, mailer, or webhook pipeline.
 */
final class AuditIngestionGuard
{
    /** @var array<string, int> */
    private const BODY_LIMITS = [
        'conn' => 16 * 1024,
        'file' => 64 * 1024,
        'alarm' => 16 * 1024,
    ];

    public function deviceFor(Request $request, string $kind): ?Device
    {
        $bodyLimit = self::BODY_LIMITS[$kind] ?? null;
        if ($bodyLimit === null) {
            return null;
        }

        $ip = (string) ($request->ip() ?? 'unknown');
        $ipHash = hash('sha256', $ip);
        $invalidIpKey = 'rd:audit:invalid-ip:'.$ipHash;

        if (RateLimiter::tooManyAttempts($invalidIpKey, $this->limit('invalid_per_ip', 300))) {
            return null;
        }

        $contentLength = $request->header('Content-Length');
        if ((is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > $bodyLimit)
            || strlen((string) $request->getContent()) > $bodyLimit) {
            $this->recordInvalid($invalidIpKey);

            return null;
        }

        $id = $request->input('id');
        $uuid = $request->input('uuid');
        if (! is_string($id) || ! is_string($uuid)
            || $id === '' || $uuid === ''
            || mb_strlen($id) > 255 || mb_strlen($uuid) > 255) {
            $this->recordInvalid($invalidIpKey);

            return null;
        }

        $device = Device::query()
            ->where('rustdesk_id', $id)
            ->where('approved', true)
            ->first();

        if (! $device || ! hash_equals((string) $device->uuid, $uuid)) {
            $this->recordInvalid($invalidIpKey);

            return null;
        }

        $validIpKey = 'rd:audit:valid-ip:'.$ipHash;
        if (! $this->consume($validIpKey, $this->limit('valid_per_ip', 12000))) {
            return null;
        }

        $deviceKey = 'rd:audit:device:'.hash('sha256', $kind."\0".$id."\0".$uuid);
        if (! $this->consume($deviceKey, $this->limit('per_device.'.$kind, match ($kind) {
            'conn' => 240,
            'file' => 1200,
            'alarm' => 60,
            default => 60,
        }))) {
            return null;
        }

        return $device;
    }

    private function consume(string $key, int $maxAttempts): bool
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }

    private function recordInvalid(string $key): void
    {
        RateLimiter::hit($key, 60);
    }

    private function limit(string $key, int $default): int
    {
        return max(1, (int) config('rustdesk.audit.rate_limits.'.$key, $default));
    }
}
