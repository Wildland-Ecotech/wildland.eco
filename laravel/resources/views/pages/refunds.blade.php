{{-- The donation refund policy. Keep it in step with what Stripe and the site actually do (docs/04-frontend.md). --}}
@php
    $portalLoginUrl = config('services.stripe.portal_login_url');
@endphp

<x-layout title="Refund Policy — Wildland Ecotech">
    <x-content-page class="policy-page">
        <section class="intro">
            <h3>— Donations</h3>
            <h1>Refund Policy</h1>

            {{-- Set by hand whenever the policy changes --}}
            <p class="effective">Effective November 6, 2026</p>
        </section>

        <div class="prose">
            <p>
                Thank you for supporting Wildland Ecotech. Your donations fund hands-on restoration of degraded
                and polluted land. We know mistakes happen, and if something went wrong with your donation,
                we'll help.
            </p>

            <h2 id="within-30-days">Refunds Within 30 Days</h2>

            <p>
                You can ask for a refund of any donation within 30 days of the payment. That includes one-time
                donations, each monthly or annual payment, a wrong amount, and a donation made twice by mistake.
                After 30 days, donations are final.
            </p>

            <h2 id="what-you-get-back">What You Get Back</h2>

            <p>
                We refund your donation minus the processing fee that Stripe, our payment processor, charged
                for it. Stripe doesn't give that fee back when a payment is refunded, so we can't either. We'll
                tell you the exact amount when we reply.
            </p>

            <h2 id="canceling">Canceling a Recurring Donation</h2>

            <p>
                You can cancel a monthly or annual donation at any time. Use the Manage Your Recurring Donation
                link in any receipt for it{{ filled($portalLoginUrl) ? ', or the button below,' : '' }} and sign
                in with the email address you donated with. Stripe will email you a secure sign-in link. You can
                also email us and we'll cancel it for you.
            </p>

            <p>
                Canceling stops future payments. It doesn't refund past ones, but you can ask for a refund of
                any payment made in the last 30 days.
            </p>

            @if (filled($portalLoginUrl))
                <div class="actions">
                    <a class="button full" href="{{ $portalLoginUrl }}">Manage Your Recurring Donation</a>
                </div>
            @endif

            <h2 id="how-to-ask">How to Ask for a Refund</h2>

            <p>Email <a href="mailto:contact@wildland.eco">contact@wildland.eco</a> with:</p>

            <ul>
                <li>Your receipt number, which starts with WET-</li>
                <li>The email address you donated with</li>
                <li>What went wrong, if you'd like to tell us</li>
            </ul>

            <p>We reply within 5 business days.</p>

            <p>
                Please contact us before disputing a charge with your bank. We can usually sort it out faster,
                and a dispute costs us a fee that would otherwise fund restoration work.
            </p>

            <h2 id="how-refunds-are-paid">How Refunds Are Paid</h2>

            <p>
                Refunds go back to the card or account you paid with. We can't send them anywhere else, such as
                another card or a check. Card refunds usually appear within 5 to 10 business days, depending on
                your bank. If your card has been canceled or replaced, your bank normally passes the refund on
                to you.
            </p>

            <h2 id="taxes">Taxes</h2>

            <p>
                A refunded donation is no longer tax-deductible. Your receipt no longer covers the amount you got
                back, so keep a note of the refund with it. We aren't tax advisers, so ask yours if you're unsure
                what you can deduct.
            </p>

            <h2 id="payments-we-may-refuse">Payments We May Refuse</h2>

            <p>
                To protect donors and our organization, we may decline, cancel, or refund any payment that looks
                fraudulent, such as card testing, and block further payments from the same source.
            </p>

            <h2 id="leaf-donations">Leaf Donations</h2>

            <p>
                Our leaf program involves no payment, and bags of leaves can't be returned once we've received
                them.
            </p>

            <h2 id="questions">Questions</h2>

            <p>Email us at <a href="mailto:contact@wildland.eco">contact@wildland.eco</a>.</p>
        </div>

        <x-slot:footer>
            <x-footer />
        </x-slot:footer>
    </x-content-page>
</x-layout>
