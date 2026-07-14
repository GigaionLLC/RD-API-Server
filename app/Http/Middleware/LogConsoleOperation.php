<?php

namespace App\Http\Middleware;

use App\Models\ConsoleAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records admin-console mutations (the panel-internal action log). After the response,
 * for write methods (POST/PUT/PATCH/DELETE) performed by an authenticated user — excluding
 * the login/logout routes — it writes a ConsoleAudit row. GET requests are never logged.
 *
 * Best-effort: any failure here is swallowed so it can never break a successful request.
 */
class LogConsoleOperation
{
    /**
     * Route names that must never be recorded (auth lifecycle, not a mutation worth auditing).
     *
     * @var array<int, string>
     */
    private const EXCLUDED_ROUTES = ['admin.login', 'admin.logout'];

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        // Capture the actor before the controller. A self-password-reset intentionally logs
        // its browser session out, but the successful reset must still retain attribution.
        $actor = Auth::user();
        $response = $next($request);

        try {
            if (! in_array($request->getMethod(), self::WRITE_METHODS, true)) {
                return $response;
            }

            if (! $actor) {
                return $response;
            }

            $routeName = $request->route()?->getName();
            if ($routeName !== null && in_array($routeName, self::EXCLUDED_ROUTES, true)) {
                return $response;
            }

            ConsoleAudit::create([
                'user_id' => $actor->getAuthIdentifier(),
                'method' => $request->getMethod(),
                'route_name' => $routeName,
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable) {
            // Never let auditing break a request.
        }

        return $response;
    }
}
