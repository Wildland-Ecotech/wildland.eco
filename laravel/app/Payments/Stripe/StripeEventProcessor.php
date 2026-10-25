<?php

namespace App\Payments\Stripe;

use App\Models\WebhookEvent;

/**
 * Processes a stored Stripe webhook event by syncing the object it is about.
 * Events only signal which object changed: the sync functions fetch its
 * current state from Stripe, so duplicate and out-of-order events are
 * harmless. Events we don't handle are accepted and marked processed.
 */
class StripeEventProcessor
{
    /**
     * The events Stripe sends us, and the sync function for each. Keep in
     * step with STRIPE_EVENTS in docker-compose.yml and the events the
     * server webhook endpoints subscribe to (docs/07-deployment.md#stripe).
     * Payments, subscriptions, refunds, and customers are synced with
     * payment sync (milestone 6); until then their events are accepted
     * without changes.
     *
     * @var array<string, string|null>
     */
    public const EVENTS = [
        'checkout.session.completed' => 'checkoutSession',
        'checkout.session.expired' => 'checkoutSession',
        'checkout.session.async_payment_succeeded' => 'checkoutSession',
        'checkout.session.async_payment_failed' => 'checkoutSession',
        'invoice.paid' => null,
        'invoice.payment_failed' => null,
        'payment_intent.succeeded' => null,
        'payment_intent.payment_failed' => null,
        'charge.refunded' => null,
        'customer.subscription.created' => null,
        'customer.subscription.updated' => null,
        'customer.subscription.deleted' => null,
        'customer.updated' => null,
    ];

    public function __construct(private StripeSync $sync) {}

    public function process(WebhookEvent $event): void
    {
        $sync = self::EVENTS[$event->type] ?? null;
        $objectId = $event->payload['data']['object']['id'] ?? null;

        if ($sync && $objectId) {
            $this->sync->{$sync}($objectId);
        }
    }
}
