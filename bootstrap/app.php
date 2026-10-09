<?php

use App\Http\Middleware\EnsureOwnerActive;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RoleMiddleware;
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
            'role' => RoleMiddleware::class,
            'owner.active' => EnsureOwnerActive::class,
            'password.change' => RequirePasswordChange::class,
        ]);

        // The app always sits behind nginx (Docker image or a reverse proxy in
        // front of PHP-FPM), so trust forwarded headers to keep the real client
        // IP in audit logs and rate limiters.
        $middleware->trustProxies(at: '*');

        // Apply the "api" rate limiter to every API route.
        $middleware->throttleApi();

        $middleware->redirectGuestsTo(function ($request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        $middleware->redirectUsersTo('/login');

        $middleware->validateCsrfTokens(except: [
            'api/*',
            'sms/delivery-report',
            'sms/inbox',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
