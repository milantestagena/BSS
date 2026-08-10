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
        // Only the 'partner' guard uses Laravel's redirect-based `auth` middleware today —
        // customer auth is handled via Lighthouse's @auth directive (GraphQL, no redirect).
        // Laravel's default guest-redirect looks for a route named 'login', which doesn't
        // exist in this app, so it must be set explicitly here.
        $middleware->redirectGuestsTo(fn () => route('partner.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
