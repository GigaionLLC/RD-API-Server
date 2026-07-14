<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the client-facing /api/* routes.
 *
 * Reads `Authorization: Bearer <token>`, resolves a non-expired, active AuthToken, loads
 * its (active) User, and exposes that user via the request's user resolver so controllers
 * can call $request->user(). Rejects with the contract's {"error":"..."} shape otherwise.
 */
class RustAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        if ($token === null || $token === '') {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        /** @var AuthToken|null $authToken */
        $authToken = AuthToken::where('token', $token)
            ->where('status', AuthToken::STATUS_ACTIVE)
            ->first();

        if (! $authToken) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        if ($authToken->expires_at !== null && $authToken->expires_at->isPast()) {
            return response()->json(['error' => 'Token expired'], 401);
        }

        /** @var User|null $user */
        $user = $authToken->user;

        if (! $user || ! $user->isActive()) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        if ((int) $authToken->credential_version !== max(1, (int) $user->credential_version)) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        // Touch last-used for activity tracking (best-effort, no validation).
        $authToken->forceFill(['last_used_at' => now()])->saveQuietly();

        // Expose the resolved user + token to controllers.
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_token', $authToken);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        // Fall back to the framework helper (also handles `access_token` query/body).
        return $request->bearerToken();
    }
}
