@use('App\Models\Checkout')

@php
    // Whole dollars without cents ($25), otherwise with them ($37.50)
    $cents = $checkout->amount_cents;
    $amount = '$' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);

    $donation = match ($checkout->frequency) {
        Checkout::FREQUENCY_MONTHLY => "monthly donation of {$amount}",
        Checkout::FREQUENCY_ANNUAL => "annual donation of {$amount}",
        default => "donation of {$amount}",
    };

    $recurring = $checkout->frequency !== Checkout::FREQUENCY_ONE_TIME;
@endphp

<x-layout title="Thank You — Wildland Ecotech">
    <x-content-page class="donate-page">
        {{-- Shows only the donation, never the donor's name or email; see DonationController::thankYou() --}}
        <div class="donate-section thanks">
            <h3>— Support Our Mission</h3>
            <h1>Thank You</h1>

            <p class="lead">
                Your {{ $donation }} protects communities from wildfire, drought, and flooding
                while restoring habitat for future generations.
            </p>

            <p class="detail">
                Payments from a bank account can take a few business days to complete.
            </p>

            @if ($recurring && filled($portalLoginUrl))
                <div class="manage-donation">
                    <h2>Your Recurring Donation</h2>

                    <p class="detail">
                        To update your payment method or cancel your recurring donation, sign in with the email address
                        you used today. Stripe, our payment processor, will email you a secure sign-in link.
                    </p>

                    <a class="button full" href="{{ $portalLoginUrl }}">Manage Your Recurring Donation</a>
                </div>
            @endif

            <p class="tax-note">
                Wildland Ecotech is a 501(c)(3) nonprofit. All donations are tax-deductible. EIN: 33-3738514
            </p>
        </div>

        <x-slot:footer>
            <x-footer
                heading="Thank you for your support"
                title="Together we can restore wildlands for future generations."
                button-text="Back to Home"
                :button-href="route('home')"
                :show-donate="false"
            />
        </x-slot:footer>
    </x-content-page>
</x-layout>
