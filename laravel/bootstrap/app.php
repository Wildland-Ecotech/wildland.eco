<?php

use App\Http\Middleware\RecordFormSubmission;
use App\Http\Middleware\RecordLoginAttempt;
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
        // generate https:// URLs and see the visitor's address, which Caddy
        // sends alone in X-Forwarded-For (deploy/caddy/site.caddy). Trusting
        // any address is safe because nginx is bound to 127.0.0.1, so only the
        // proxy on the same server can reach it.
        $middleware->trustProxies(at: '*');

        // Forms record each submission, and sign-in each attempt, as soon as
        // the session starts, before the CSRF check, so one from an expired
        // page is recorded too
        $middleware->appendToPriorityList(StartSession::class, RecordFormSubmission::class);
        $middleware->appendToPriorityList(StartSession::class, RecordLoginAttempt::class);

        // Someone already signed in who opens the sign-in page goes to the
        // admin page instead. Guests are sent to the `login` route.
        $middleware->redirectUsersTo(fn () => route('admin'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
