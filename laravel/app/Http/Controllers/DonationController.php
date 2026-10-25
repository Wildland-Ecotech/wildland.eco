<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartDonationRequest;
use App\Models\Checkout;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Services\ProofOfWork;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * The donate page (/donate): the donor chooses a donation, is sent to the
 * payment gateway's hosted page to pay, and returns to a thank-you page.
 * Payments are recorded from the gateway's webhooks, not here.
 */
class DonationController extends Controller
{
    /**
     * Checkouts started per visitor (by IP address) per hour. Only started
     * checkouts count, so fixing validation errors never locks anyone out.
     */
    public const MAX_PER_HOUR = 10;

    /**
     * Shown in place of giving when the gateway is not configured.
     */
    public const UNAVAILABLE_MESSAGE = 'Online donations are not available right now. Please try again later, or email us at contact@wildland.eco to give.';

    /**
     * Show the donate page.
     */
    public function create(ProofOfWork $proofOfWork, PaymentGateway $gateway): View
    {
        return view('pages.donate', [
            'paymentsAvailable' => $gateway->isAvailable(),
            'proofOfWorkChallenge' => $proofOfWork->issue(),
        ]);
    }

    /**
     * Record the donation attempt and send the donor to pay.
     */
    public function store(StartDonationRequest $request, PaymentGateway $gateway): RedirectResponse
    {
        if (! $gateway->isAvailable()) {
            return back()->withInput()->withErrors(['form' => self::UNAVAILABLE_MESSAGE]);
        }

        $limiterKey = 'donations:' . $request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_PER_HOUR)) {
            return back()
                ->withInput()
                ->withErrors(['form' => 'You have started several donations in the last hour. Please try again later, or email us at contact@wildland.eco.']);
        }

        $checkout = DB::transaction(function () use ($request, $gateway) {
            $user = $this->donor($request->validated('email'), $request->validated('name'));

            return $user->checkouts()->create([
                'amount_cents' => $request->amountCents(),
                'currency' => 'usd',
                'frequency' => $request->validated('frequency'),
                'gateway' => $gateway->name(),
            ]);
        });

        try {
            $session = $gateway->startCheckout(
                $checkout,
                successUrl: route('donate.thank-you', $checkout),
                cancelUrl: route('donate'),
            );
        } catch (Exception $exception) {
            // The checkout stays recorded as started, without a session
            report($exception);

            return back()
                ->withInput()
                ->withErrors(['form' => 'We could not reach our payment processor. Please try again in a few minutes, or email us at contact@wildland.eco.']);
        }

        $checkout->update([
            'gateway_session_id' => $session->id,
            'expires_at' => $session->expiresAt,
        ]);

        RateLimiter::hit($limiterKey, 3600);

        return redirect()->away($session->url);
    }

    /**
     * Thank the donor after they pay. Reached from the gateway's success
     * redirect; the checkout's public reference in the URL can't be guessed.
     * The page shows only the donation, never the donor's name or email.
     */
    public function thankYou(Checkout $checkout): View
    {
        return view('pages.donate-thank-you', [
            'checkout' => $checkout,
            'portalLoginUrl' => config('services.stripe.portal_login_url'),
        ]);
    }

    /**
     * Find the donor by email, or create them. Typing an email address
     * proves nothing about owning it, so an existing donor's name is never
     * changed; it is only filled in when they don't have one yet. A
     * soft-deleted donor is reused without being restored: their address
     * stays reserved, and sync restores them if a payment succeeds.
     */
    private function donor(string $email, string $name): User
    {
        $user = User::withTrashed()->firstOrCreate(
            ['email' => mb_strtolower(trim($email))],
            ['name' => $name],
        );

        if (blank($user->name)) {
            $user->update(['name' => $name]);
        }

        return $user;
    }
}
