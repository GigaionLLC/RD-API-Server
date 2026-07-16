<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RecentAdminAuthentication;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects browser sessions created before the account's latest password replacement.
 *
 * A missing marker is accepted only for version-1 accounts so sessions that predate this
 * migration receive a seamless one-time upgrade. New logins are detected after the wrapped
 * controller authenticates and receive the current marker before the response is persisted.
 */
final class EnsureCredentialVersion
{
    private const SESSION_KEY = 'auth.credential_version';

    public function __construct(private readonly RecentAdminAuthentication $recentAuthentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $this->sessionMatches($request, $user)) {
            return $this->logout($request);
        }

        $response = $next($request);

        // Login, SSO, and successful post-password 2FA all authenticate inside their
        // controller. Seed the marker centrally so those flows cannot drift apart.
        $authenticated = $request->user();
        if (! $user instanceof User && $authenticated instanceof User) {
            $request->session()->put(self::SESSION_KEY, $this->version($authenticated));
            $this->recentAuthentication->mark($request->session(), $authenticated);
        }

        return $response;
    }

    private function sessionMatches(Request $request, User $user): bool
    {
        $version = $this->version($user);

        if (! $request->session()->has(self::SESSION_KEY)) {
            if ($version !== 1) {
                return false;
            }

            $request->session()->put(self::SESSION_KEY, $version);

            return true;
        }

        $stored = $request->session()->get(self::SESSION_KEY);

        return is_int($stored) && $stored === $version;
    }

    private function version(User $user): int
    {
        return max(1, (int) $user->credential_version);
    }

    private function logout(Request $request): JsonResponse|RedirectResponse
    {
        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('admin.login')
            ->withErrors(['username' => 'Your credentials changed. Sign in again.']);
    }
}
