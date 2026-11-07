<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\ProofOfWork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Signing in with an email address and password (/login), and signing out.
 * Only admins can sign in until donor accounts are built; admins are created
 * with `php artisan users:admin`. Signing in takes a proof of work
 * (LoginRequest), is limited per email and IP address and per IP address,
 * and every attempt is recorded (RecordLoginAttempt), this controller
 * saying whether it signed in, failed, or was refused for too many attempts.
 */
class LoginController extends Controller
{
    /**
     * Failed sign-in attempts allowed per email address and IP address per
     * minute.
     */
    public const MAX_ATTEMPTS_PER_MINUTE = 5;

    /**
     * Failed sign-in attempts allowed from one IP address per hour, across
     * every email address, so one address can't try account after account.
     */
    public const MAX_ATTEMPTS_PER_IP_PER_HOUR = 20;

    /**
     * Shown for a wrong password and for an email address without an admin
     * account alike, so the form can't be used to find out who has one.
     */
    public const FAILED_MESSAGE = 'That email address and password don\'t match an admin account. Check them and try again.';

    public const TOO_MANY_ATTEMPTS_MESSAGE = 'Too many sign-in attempts. Please wait a minute, then try again.';

    public const TOO_MANY_ATTEMPTS_FROM_IP_MESSAGE = 'Too many sign-in attempts from your network. Please wait an hour, then try again.';

    /**
     * Show the sign-in form, with a proof-of-work challenge for the browser
     * to solve.
     */
    public function create(ProofOfWork $proofOfWork): View
    {
        return view('pages.login', ['proofOfWorkChallenge' => $proofOfWork->issue()]);
    }

    /**
     * Sign in, then go to the page the admin asked for, or the admin page.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $email = $request->validated('email');
        $limiterKey = 'login:' . $email . '|' . $request->ip();
        $ipKey = 'login-ip:' . $request->ip();

        $tooMany = match (true) {
            RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP_PER_HOUR) => self::TOO_MANY_ATTEMPTS_FROM_IP_MESSAGE,
            RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS_PER_MINUTE) => self::TOO_MANY_ATTEMPTS_MESSAGE,
            default => null,
        };

        if ($tooMany !== null) {
            LoginAttempt::outcomeFor($request, LoginAttempt::OUTCOME_RATE_LIMITED);

            return back()->onlyInput('email')->withErrors(['form' => $tooMany]);
        }

        $credentials = [
            'email' => $email,
            'password' => $request->validated('password'),
            'role' => User::ROLE_ADMIN,
        ];

        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($limiterKey, 60);
            RateLimiter::hit($ipKey, 60 * 60);
            LoginAttempt::outcomeFor($request, LoginAttempt::OUTCOME_FAILED);

            return back()->onlyInput('email')->withErrors(['form' => self::FAILED_MESSAGE]);
        }

        // The per-address count starts over; the IP address's stays, so a
        // right password doesn't hide a run of wrong ones
        RateLimiter::clear($limiterKey);
        LoginAttempt::outcomeFor($request, LoginAttempt::OUTCOME_SIGNED_IN);

        // A new session ID, so one set before signing in can't be reused
        $request->session()->regenerate();

        return redirect()->intended(route('admin'));
    }

    /**
     * Sign out, ending the session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have signed out.');
    }
}
