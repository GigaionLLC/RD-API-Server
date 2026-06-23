<?php

namespace App\Providers;

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

            return [
                Limit::perMinute(10)->by('rd-login-user:'.$username.'|'.$request->ip())->response($tooMany),
                Limit::perMinute(30)->by('rd-login-ip:'.$request->ip())->response($tooMany),
            ];
        });
    }
}
