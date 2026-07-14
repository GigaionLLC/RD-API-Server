<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Fail-closed authorization boundary for the stock client's otherwise anonymous uploader.
 */
class AuthorizeRecordingUpload
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('recordings.upload.enabled', false)) {
            return response()->json(['error' => 'Recording upload is disabled'], 403);
        }

        if ($this->validToken($request) || $this->sourceIpAllowed((string) $request->ip())) {
            return $next($request);
        }

        return response()->json(['error' => 'Recording upload is not authorized'], 403);
    }

    private function validToken(Request $request): bool
    {
        $configured = (string) config('recordings.upload.token', '');
        if (strlen($configured) < 32) {
            return false;
        }

        $provided = $request->bearerToken() ?: $request->header('X-Recording-Token');

        return is_string($provided)
            && $provided !== ''
            && hash_equals($configured, $provided);
    }

    private function sourceIpAllowed(string $sourceIp): bool
    {
        $allowed = config('recordings.upload.allowed_ips', []);
        if ($sourceIp === '' || ! is_array($allowed) || $allowed === []) {
            return false;
        }

        $ranges = array_values(array_map(
            'trim',
            array_filter(
                $allowed,
                static fn (mixed $range): bool => is_string($range) && trim($range) !== ''
            )
        ));
        if ($ranges === []) {
            return false;
        }

        foreach ($ranges as $range) {
            if (! $this->validIpRange($range)) {
                return false;
            }
        }

        try {
            return IpUtils::checkIp($sourceIp, $ranges);
        } catch (Throwable) {
            return false;
        }
    }

    private function validIpRange(string $range): bool
    {
        if (! str_contains($range, '/')) {
            return filter_var($range, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $range, 2);
        if ($prefix === '' || ! ctype_digit($prefix)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefixLength >= 1 && $prefixLength <= 32;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefixLength >= 1 && $prefixLength <= 128;
        }

        return false;
    }
}
