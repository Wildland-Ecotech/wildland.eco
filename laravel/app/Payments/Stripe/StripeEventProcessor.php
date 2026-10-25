<?php

namespace App\Payments\Stripe;

use App\Models\SubscriptionEvent;
use App\Models\WebhookEvent;

/**
 * Processes a stored Stripe webhook event by syncing the object it is about.
 * Events only signal which object changed: the sync functions fetch its
 * current state from Stripe, so duplicate and out-of-order events are
 * harmless. Events we don't handle are accepted and marked processed.
 * Changes are recorded with the `webhook` source in subscription history.
 */
class StripeEventProcessor
{
    /**
     * The events Stripe sends us, and the sync function for each. Keep in
     * step with STRIPE_EVENTS in docker-compose.dev.yml and the events the
     * server webhook endpoints subscribe to (docs/07-deployment.md#stripe).
     *
     * @var array<string, string>
     */
    public const EVENTS = [
        'checkout.session.completed' => 'checkoutSession',
        'checkout.session.expired' => 'checkoutSession',
        'checkout.session.async_payment_succeeded' => 'checkoutSession',
        'checkout.session.async_payment_failed' => 'checkoutSession',
        'invoice.paid' => 'invoice',
        'invoice.payment_failed' => 'invoice',
        'payment_intent.succeeded' => 'paymentIntent',
        'payment_intent.payment_failed' => 'paymentIntent',
        'charge.refunded' => 'charge',
        'customer.subscription.created' => 'subscription',
        'customer.subscription.updated' => 'subscription',
        'customer.subscription.deleted' => 'subscription',
        'customer.updated' => 'customer',
    ];

    public function __construct(private StripeSync $sync) {}

    public function process(WebhookEvent $event): void
    {
        $sync = self::EVENTS[$event->type] ?? null;
        $objectId = $event->payload['data']['object']['id'] ?? null;

        if ($sync && $objectId) {
            $this->sync->{$sync}($objectId, SubscriptionEvent::SOURCE_WEBHOOK);
        }
    }
}
