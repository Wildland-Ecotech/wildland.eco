<?php

namespace App\Http\Middleware;

use App\Models\LoginAttempt;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every attempt to sign in (POST /login) as a LoginAttempt, for
 * spotting someone trying to get in: the email address as typed, the admin
 * account with that address (if any), the IP address and browser, and what
 * happened. Never the password.
 *
 * It runs right after the session starts (bootstrap/app.php), before the
 * CSRF check, so an attempt from a page that had expired is recorded too.
 * LoginController says what happened (signed in, failed, or too many
 * attempts) with LoginAttempt::outcomeFor(); anything else is worked out
 * here from the response:
 * an expired page, an error, a missing proof of work, or a missing email
 * address or password.
 */
class RecordLoginAttempt
{
    public function handle(Request $request, Closure $next): Response
    {
        $attempt = new LoginAttempt;
        $request->attributes->set(LoginAttempt::REQUEST_ATTRIBUTE, $attempt);

        $response = $next($request);

        $email = $request->input('email');
        $email = is_string($email) && trim($email) !== '' ? Str::limit(mb_strtolower(trim($email)), 255, '') : null;

        $attempt->forceFill([
            'email' => $email,
            'user_id' => $email !== null ? User::where('email', $email)->where('role', User::ROLE_ADMIN)->value('id') : null,
            'ip_address' => (string) $request->ip(),
            'user_agent' => $request->userAgent() !== null ? Str::limit($request->userAgent(), 500, '') : null,
            'outcome' => $this->outcome($attempt, $request, $response),
        ])->save();

        return $response;
    }

    /**
     * What happened: the controller's outcome, or else one worked out from
     * the response and the messages flashed for the form.
     */
    private function outcome(LoginAttempt $attempt, Request $request, Response $response): string
    {
        if (in_array($attempt->outcome, LoginAttempt::OUTCOMES, true)) {
            return $attempt->outcome;
        }

        $errors = $request->hasSession() ? $request->session()->get('errors') : null;
        $fields = $errors instanceof ViewErrorBag ? array_keys($errors->getBag('default')->getMessages()) : [];

        return match (true) {
            $response->getStatusCode() === 419 => LoginAttempt::OUTCOME_EXPIRED,
            $response->getStatusCode() >= 500 => LoginAttempt::OUTCOME_ERROR,
            array_intersect($fields, ['pow_challenge', 'pow_nonce']) !== [] => LoginAttempt::OUTCOME_UNVERIFIED,
            default => LoginAttempt::OUTCOME_INCOMPLETE,
        };
    }
}
