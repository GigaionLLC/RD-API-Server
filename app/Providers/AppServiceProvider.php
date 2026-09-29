<?php

namespace App\Providers;

use App\Support\LoginThrottle;
use App\Support\MariaDbConnectionBoundary;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        MariaDbConnectionBoundary::enforce($this->app);
        MariaDbConnectionBoundary::registerLiveGuard($this->app);
        $this->configureRateLimiting();
    }

    /**
     * Brute-force protection for the client login endpoint (POST /api/login). Two layers:
     * a per-account+IP limit (the common online-guessing case) and a looser per-IP limit so
     * an attacker cannot simply cycle usernames from one host. Exceeding either returns the
     * {error} shape the RustDesk client surfaces (docs/modernization/16-response-contract.md §2.2).
     *
     * The admin web login is throttled separately, in-controller, so it can redirect back
     * with a form error instead of a JSON body (Admin\AuthController::login).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api-login', function (Request $request): array {
            $username = Str::lower((string) $request->input('username', ''));
            $tooMany = fn (): JsonResponse => response()->json(
                ['error' => 'Too many login attempts. Please wait a minute and try again.'],
                429,
            );

            // IPv6 callers are bucketed per /64 so address rotation inside one network does not
            // reset the limits. A per-account ceiling across all sources lives in LoginThrottle.
            $source = LoginThrottle::ipBucket($request->ip());

            return [
                Limit::perMinute(10)->by('rd-login-user:'.$username.'|'.$source)->response($tooMany),
                Limit::perMinute(30)->by('rd-login-ip:'.$source)->response($tooMany),
            ];
        });

        // Starting an OIDC device login inserts a pending row and performs outbound provider
        // discovery. A stock client starts one flow per sign-in click.
        RateLimiter::for('oidc-auth', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('rd-oidc-auth:'.LoginThrottle::ipBucket($request->ip()))
            ->response(fn (): JsonResponse => response()->json(
                ['error' => 'Too many sign-in attempts. Please wait a minute and try again.'],
                429,
            )));

        // The browser approval step for an OIDC device login.
        RateLimiter::for('oidc-confirm', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('rd-oidc-confirm:'.LoginThrottle::ipBucket($request->ip())));
    }
}
