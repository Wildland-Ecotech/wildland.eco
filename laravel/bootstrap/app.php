<?php

use App\Http\Middleware\RecordFormSubmission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // On servers, a reverse proxy terminates HTTPS and forwards requests to
        // nginx over plain HTTP. Trusting its X-Forwarded-* headers lets Laravel
        // generate https:// URLs. Trusting any address is safe because nginx is
        // bound to 127.0.0.1, so only the proxy on the same server can reach it.
        $middleware->trustProxies(at: '*');

        // Forms record each submission as soon as the session starts, before
        // the CSRF check, so a submission from an expired page is recorded too
        $middleware->appendToPriorityList(StartSession::class, RecordFormSubmission::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
