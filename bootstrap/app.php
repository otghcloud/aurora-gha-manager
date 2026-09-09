<?php

use App\Http\Middleware\EnsureAppConfigured;
use App\Http\Middleware\RedirectIfAppConfigured;
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
            'setup.incomplete' => RedirectIfAppConfigured::class,
        ]);

        // Runs ahead of authentication: an unconfigured install has no users to authenticate.
        $middleware->prependToGroup('web', EnsureAppConfigured::class);

        // GitHub signs webhook deliveries with an HMAC and temporary build VMs use per-build
        // bearer credentials, so neither machine-to-machine endpoint uses browser CSRF tokens.
        $middleware->validateCsrfTokens(except: ['webhook/*', 'api/builds/*/events']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
