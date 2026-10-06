<?php

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
        // Trust the reverse proxy / load balancer so Laravel detects HTTPS from X-Forwarded-Proto.
        $middleware->trustProxies(at: '*');
        
        // `unsubscribe` also takes RFC 8058 one-click POSTs from mail providers, authorized by its URL signature.
        // Gateway return URLs may be POSTed to cross-site; PostedReturnController only redirects them to their GET route.
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'unsubscribe', 'pay/*/*/return', 'portal/pay/*/*/return']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
