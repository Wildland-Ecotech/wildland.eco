<?php

namespace App\Payments\Stripe;

use App\Models\SubscriptionEvent;
use App\Payments\Stripe\Concerns\CallsStripe;
use Carbon\CarbonInterface;
use Closure;

/**
 * Imports Stripe history by listing objects and running each through the
 * same sync functions as webhooks (StripeSync), recorded with the `import`
 * source. Every record is keyed on its Stripe ID, so importing again never
 * creates duplicates. Used by `php artisan stripe:sync`.
 *
 * Stripe keeps its event log for only about 30 days, so history is rebuilt
 * from objects as they are now: a subscription imported long after it was
 * canceled gets its start and cancellation, but not every change between.
 */
class StripeImporter
{
    use CallsStripe;

    /**
     * The kinds of Stripe objects imported, in order.
     */
    public const SUBSCRIPTIONS = 'subscriptions';

    public const INVOICES = 'invoices';

    public const PAYMENTS = 'payments';

    public const REFUNDS = 'refunds';

    public const CHECKOUT_SESSIONS = 'checkout sessions';

    public const KINDS = [self::SUBSCRIPTIONS, self::INVOICES, self::PAYMENTS, self::REFUNDS, self::CHECKOUT_SESSIONS];

    public function __construct(private StripeSync $sync) {}

    /**
     * Import everything, or only objects created since a time. Subscriptions
     * are always imported in full, since a long-running one can change at
     * any time; there are few enough of them to list every time.
     *
     * Each object is handed to $each with a closure that syncs it, one at a
     * time as the lists are paged through, so the caller can show progress
     * and must handle failures; the import continues with the next object.
     * The closure returns the synced record, or null for an object that
     * isn't ours or has nothing to record, such as a Payment Link's
     * checkout session or a payment that was never attempted.
     *
     * @param  callable(string $kind, string $id, Closure(): mixed $sync): void  $each
     */
    public function import(?CarbonInterface $createdSince, callable $each): void
    {
        $created = $createdSince ? ['created' => ['gte' => $createdSince->getTimestamp()]] : [];
        $source = SubscriptionEvent::SOURCE_IMPORT;

        // Subscriptions first, so their payments find them without asking
        // Stripe again
        $kinds = [
            self::SUBSCRIPTIONS => [
                fn () => $this->stripe()->subscriptions->all(['status' => 'all', 'limit' => 100]),
                fn ($subscription) => $this->sync->subscription($subscription->id, $source),
            ],
            self::INVOICES => [
                fn () => $this->stripe()->invoices->all($created + ['limit' => 100]),
                fn ($invoice) => $this->sync->invoice($invoice->id, $source),
            ],
            // Includes one-time donations from our checkouts and from Payment
            // Links, and payments of invoices, which sync again harmlessly
            self::PAYMENTS => [
                fn () => $this->stripe()->paymentIntents->all($created + ['limit' => 100]),
                fn ($paymentIntent) => $this->sync->paymentIntent($paymentIntent->id, $source),
            ],
            // Refunds made recently on older payments
            self::REFUNDS => [
                fn () => $this->stripe()->refunds->all($created + ['limit' => 100]),
                fn ($refund) => $refund['charge']
                    ? $this->sync->charge($refund['charge'], $source)
                    : ($refund['payment_intent'] ? $this->sync->paymentIntent($refund['payment_intent'], $source) : null),
            ],
            self::CHECKOUT_SESSIONS => [
                fn () => $this->stripe()->checkout->sessions->all($created + ['limit' => 100]),
                fn ($session) => $this->sync->checkoutSession($session->id, $source),
            ],
        ];

        foreach ($kinds as $kind => [$list, $sync]) {
            foreach ($this->call($list)->autoPagingIterator() as $object) {
                $each($kind, $object->id, fn () => $sync($object));
            }
        }
    }
}
