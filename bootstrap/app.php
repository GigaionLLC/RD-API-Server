<?php

use App\Http\Middleware\ApiKeyAuth;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureCredentialVersion;
use App\Http\Middleware\LogConsoleOperation;
use App\Http\Middleware\RustAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'permission' => CheckPermission::class,
            'rustauth' => RustAuth::class,
            'console.audit' => LogConsoleOperation::class,
            'apikey' => ApiKeyAuth::class,
        ]);
        $middleware->authenticateSessions();
        $middleware->appendToGroup('web', EnsureCredentialVersion::class);
        // Unauthenticated admin requests go to the admin login page.
        $middleware->redirectGuestsTo('/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
