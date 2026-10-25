<?php

namespace App\Payments\Stripe;

use App\Models\Checkout;
use App\Payments\Stripe\Concerns\CallsStripe;
use Carbon\CarbonImmutable;

/**
 * Brings our records up to date with Stripe, one function per object type.
 * Each fetches the current object from Stripe and updates our record by the
 * object's Stripe ID, including soft-deleted records, so receiving the same
 * change again, out of order, or from a re-import never creates duplicates.
 * Webhooks only say which object changed (see StripeEventProcessor).
 *
 * Checkout sessions are synced here; customers, subscriptions, payments, and
 * refunds are added with payment sync (milestone 6).
 */
class StripeSync
{
    use CallsStripe;

    /**
     * Our checkout status for each Checkout Session status.
     */
    private const CHECKOUT_STATUSES = [
        'open' => Checkout::STATUS_STARTED,
        'complete' => Checkout::STATUS_COMPLETED,
        'expired' => Checkout::STATUS_EXPIRED,
    ];

    /**
     * Update the checkout a Checkout Session belongs to: completed once the
     * donor finishes paying, or expired if they never do. A bank payment
     * that settles later still completes its checkout right away; whether
     * it succeeds is recorded on the donation.
     *
     * Sessions that aren't ours, such as those from Payment Links, are
     * ignored. Returns the checkout, if any.
     */
    public function checkoutSession(string $sessionId): ?Checkout
    {
        $session = $this->call(fn () => $this->stripe()->checkout->sessions->retrieve($sessionId));

        $this->assertLivemode($session);

        $checkout = Checkout::findByGatewayId(StripeGateway::NAME, $session->id)
            ?? $this->checkoutByReference($session->metadata['checkout_reference'] ?? $session->client_reference_id);

        if (! $checkout) {
            return null;
        }

        $checkout->fill([
            'status' => self::CHECKOUT_STATUSES[$session->status] ?? $checkout->status,
            'gateway_session_id' => $session->id,
            'expires_at' => CarbonImmutable::createFromTimestamp($session->expires_at),
        ]);

        // Kept from the first time it was seen completed, so re-syncing
        // doesn't move it
        if ($checkout->status === Checkout::STATUS_COMPLETED && ! $checkout->completed_at) {
            $checkout->completed_at = now();
        }

        $checkout->save();

        return $checkout;
    }

    /**
     * A checkout by its public reference, including a soft-deleted one.
     */
    private function checkoutByReference(?string $reference): ?Checkout
    {
        if (blank($reference)) {
            return null;
        }

        return Checkout::withTrashed()->where('reference', $reference)->first();
    }
}
