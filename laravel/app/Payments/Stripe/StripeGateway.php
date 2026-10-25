<?php

namespace App\Payments\Stripe;

use App\Models\Checkout;
use App\Models\GatewayCustomer;
use App\Models\User;
use App\Payments\HostedCheckout;
use App\Payments\PaymentGateway;
use App\Payments\Stripe\Concerns\CallsStripe;
use Carbon\CarbonImmutable;
use Stripe\Exception\InvalidRequestException;

/**
 * Stripe, through the Stripe PHP SDK with the API version pinned in
 * config/services.php. Donors pay on Stripe Checkout, and every gift uses
 * the one Donation product (STRIPE_DONATION_PRODUCT_ID) with its amount and
 * interval set inline, so any amount works without creating prices first.
 */
class StripeGateway implements PaymentGateway
{
    use CallsStripe;

    public const NAME = 'stripe';

    /**
     * Stripe Checkout's mode for each giving frequency, and the billing
     * interval of recurring ones.
     */
    private const FREQUENCIES = [
        Checkout::FREQUENCY_ONE_TIME => ['mode' => 'payment', 'interval' => null],
        Checkout::FREQUENCY_MONTHLY => ['mode' => 'subscription', 'interval' => 'month'],
        Checkout::FREQUENCY_ANNUAL => ['mode' => 'subscription', 'interval' => 'year'],
    ];

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Whether a secret key is set. A key for the wrong mode still fails
     * loudly when the client is first used (see StripeMode::assertValid()).
     */
    public function isAvailable(): bool
    {
        return StripeMode::isConfigured();
    }

    /**
     * Create a Checkout Session for the checkout. Payment methods are not
     * listed, so Checkout offers every method enabled in the Stripe
     * dashboard that supports the gift's frequency.
     */
    public function startCheckout(Checkout $checkout, string $successUrl, string $cancelUrl): HostedCheckout
    {
        $customerId = $this->customerFor($checkout->user);

        try {
            $session = $this->createSession($checkout, $customerId, $successUrl, $cancelUrl);
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeParam() !== 'customer') {
                throw $exception;
            }

            // The recorded customer was deleted in Stripe. Stop using it,
            // and start again with a current customer.
            GatewayCustomer::findByGatewayId(self::NAME, $customerId)?->delete();

            $customerId = $this->customerFor($checkout->user);
            $session = $this->createSession($checkout, $customerId, $successUrl, $cancelUrl);
        }

        return new HostedCheckout(
            id: $session->id,
            url: $session->url,
            expiresAt: CarbonImmutable::createFromTimestamp($session->expires_at),
        );
    }

    /**
     * The Stripe customer to use for a user: the most recent one recorded
     * for them, or else the most recent Stripe customer with their email
     * address (such as a donor who gave through a Payment Link before
     * donations moved to this site), or else a new customer. Reusing one
     * customer lets the Customer Portal show all of a donor's recurring
     * gifts together.
     *
     * Stripe's email filter is case-sensitive, and our addresses are
     * lowercase, so a customer created elsewhere with capital letters in
     * their address is not found, and a new customer is created.
     */
    private function customerFor(User $user): string
    {
        $recorded = $user->gatewayCustomers()
            ->where('gateway', self::NAME)
            ->latest('id')
            ->first();

        if ($recorded) {
            return $recorded->gateway_customer_id;
        }

        $existing = $this->call(fn () => $this->stripe()->customers->all([
            'email' => $user->email,
            'limit' => 1,
        ]))->data[0] ?? null;

        $customerId = $existing->id ?? $this->call(fn () => $this->stripe()->customers->create([
            'email' => $user->email,
            'name' => $user->name,
        ]))->id;

        // An existing customer may already be recorded, even soft-deleted;
        // never record it twice
        $record = GatewayCustomer::findByGatewayId(self::NAME, $customerId);

        if (! $record) {
            $user->gatewayCustomers()->create([
                'gateway' => self::NAME,
                'gateway_customer_id' => $customerId,
            ]);
        } elseif ($record->trashed() && $record->user_id === $user->id) {
            $record->restore();
        }

        return $customerId;
    }

    /**
     * Create the Checkout Session. The idempotency key makes a retried
     * request return the same session instead of creating another.
     */
    private function createSession(Checkout $checkout, string $customerId, string $successUrl, string $cancelUrl): \Stripe\Checkout\Session
    {
        $frequency = self::FREQUENCIES[$checkout->frequency];

        $metadata = [
            'checkout_reference' => $checkout->reference,
            'designation' => $checkout->designation->slug,
        ];

        $priceData = [
            'currency' => $checkout->currency,
            'product' => config('services.stripe.donation_product_id'),
            'unit_amount' => $checkout->amount_cents,
        ];

        if ($frequency['interval']) {
            $priceData['recurring'] = ['interval' => $frequency['interval']];
        }

        $params = [
            'mode' => $frequency['mode'],
            'customer' => $customerId,
            'client_reference_id' => $checkout->reference,
            'line_items' => [['price_data' => $priceData, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ];

        // The metadata is also copied to the payment or subscription, which
        // outlive the session, so sync and import can always find the
        // checkout and designation a payment belongs to
        if ($frequency['mode'] === 'payment') {
            // Labels the pay button "Donate"
            $params['submit_type'] = 'donate';
            $params['payment_intent_data'] = ['metadata' => $metadata];
        } else {
            $params['subscription_data'] = ['metadata' => $metadata];
        }

        return $this->call(fn () => $this->stripe()->checkout->sessions->create($params, [
            'idempotency_key' => "checkout-{$checkout->reference}-{$customerId}",
        ]));
    }
}
