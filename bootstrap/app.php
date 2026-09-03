<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));

        // Di belakang reverse proxy internal (nginx/HAProxy) yang terminasi TLS,
        // agar HTTPS & SESSION_SECURE_COOKIE dikenali. Set TRUSTED_PROXIES di .env.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
