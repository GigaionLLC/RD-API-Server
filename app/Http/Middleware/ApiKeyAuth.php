<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the admin REST API (/api/v1/*) with a scoped API key and, when a scope
 * argument is supplied (`apikey:devices.read`), enforces it. The key is read from
 * `Authorization: Bearer <key>` or the `X-API-Key` header. The key's owner becomes the
 * request user so controllers can call $request->user().
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $secret = $request->bearerToken() ?: $request->header('X-API-Key');

        if (! is_string($secret) || $secret === '') {
            return response()->json(['error' => 'API key required'], 401);
        }

        /** @var ApiKey|null $key */
        $key = ApiKey::where('token_hash', hash('sha256', $secret))->first();

        if (! $key || $key->isExpired()) {
            return response()->json(['error' => 'Invalid or expired API key'], 401);
        }

        $user = $key->user;
        if (! $user || ! $user->isActive()) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        if ($scope !== null && ! $key->hasScope($scope)) {
            return response()->json(['error' => "This API key lacks the required scope: {$scope}"], 403);
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
