<?php

namespace App\Payments;

use App\Models\Checkout;

/**
 * A payment processor, behind our own interface so the rest of the
 * application never depends on one directly. Stripe is the only
 * implementation (App\Payments\Stripe\StripeGateway); database columns
 * use `gateway` and `gateway_*_id` for the same reason.
 *
 * The interface grows as each part of the donation flow is built.
 */
interface PaymentGateway
{
    /**
     * The name stored in the `gateway` column of records from this gateway.
     */
    public function name(): string;

    /**
     * Whether payments can be taken: the gateway is configured for this
     * site. The donate page offers giving only while this is true.
     */
    public function isAvailable(): bool;

    /**
     * Start a hosted checkout for a donation attempt, where the donor pays on
     * the gateway's own page. The checkout's user becomes the gateway's
     * customer, reusing an existing customer record when there is one.
     */
    public function startCheckout(Checkout $checkout, string $successUrl, string $cancelUrl): HostedCheckout;
}
