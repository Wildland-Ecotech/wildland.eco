<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Signing in with an email address and password (/login), and signing out.
 * Only admins can sign in until donor accounts are built; admins are created
 * with `php artisan users:admin`.
 */
class LoginController extends Controller
{
    /**
     * Failed sign-in attempts allowed per email address and IP address per
     * minute.
     */
    public const MAX_ATTEMPTS_PER_MINUTE = 5;

    /**
     * Shown for a wrong password and for an email address without an admin
     * account alike, so the form can't be used to find out who has one.
     */
    public const FAILED_MESSAGE = 'That email address and password don\'t match an admin account. Check them and try again.';

    public const TOO_MANY_ATTEMPTS_MESSAGE = 'Too many sign-in attempts. Please wait a minute, then try again.';

    /**
     * Show the sign-in form.
     */
    public function create(): View
    {
        return view('pages.login');
    }

    /**
     * Sign in, then go to the page the admin asked for, or the admin page.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $email = $request->validated('email');
        $limiterKey = 'login:' . $email . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS_PER_MINUTE)) {
            return back()->onlyInput('email')->withErrors(['form' => self::TOO_MANY_ATTEMPTS_MESSAGE]);
        }

        $credentials = [
            'email' => $email,
            'password' => $request->validated('password'),
            'role' => User::ROLE_ADMIN,
        ];

        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($limiterKey, 60);

            return back()->onlyInput('email')->withErrors(['form' => self::FAILED_MESSAGE]);
        }

        RateLimiter::clear($limiterKey);

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
