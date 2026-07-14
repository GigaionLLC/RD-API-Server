<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-source upload limiter with the JSON error key required by the RustDesk client.
 */
class ThrottleRecordingUploads
{
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        // Keep the default-disabled route side-effect free; authorization returns the JSON error.
        if (! (bool) config('recordings.upload.enabled', false)) {
            return $next($request);
        }

        $maxAttempts = max(1, (int) config('recordings.upload.rate_limit_per_minute', 600));
        $key = 'recording-upload:'.hash('sha256', (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()
                ->json(['error' => 'Too many recording upload requests'], 429)
                ->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}
