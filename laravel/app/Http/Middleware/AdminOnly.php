<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin pages: only signed-in admins can see them, and their responses are
 * never cached or indexed, since they show donors' personal details. Runs
 * after `auth`, which sends guests to the sign-in page. The role is checked
 * on every request, so `users:admin --remove` takes effect right away.
 */
class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), Response::HTTP_FORBIDDEN);

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
