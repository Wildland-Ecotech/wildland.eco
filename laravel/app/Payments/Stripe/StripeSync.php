<?php

namespace App\Payments\Stripe;

use App\Models\Checkout;
use App\Models\Designation;
use App\Models\Donation;
use App\Models\GatewayCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Payments\Stripe\Concerns\CallsStripe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Brings our records up to date with Stripe, one function per object type.
 * Each fetches the current object from Stripe and updates our record by the
 * object's Stripe ID, including soft-deleted records, so receiving the same
 * change again, out of order, or from a re-import never creates duplicates
 * or moves a record backwards. Webhooks only say which object changed (see
 * StripeEventProcessor), and the history importer runs every object through
 * the same functions.
 *
 * Objects are read as arrays (toArray() and data_get()), because reading a
 * field a Stripe object doesn't have logs an error. Objects from the other
 * Stripe mode are refused (CallsStripe::assertLivemode()).
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
     * Our subscription status for each Stripe subscription status. We don't
     * offer trials, and a subscription whose first payment never completed
     * (incomplete_expired) has ended, like a canceled one.
     */
    private const SUBSCRIPTION_STATUSES = [
        'incomplete' => Subscription::STATUS_INCOMPLETE,
        'incomplete_expired' => Subscription::STATUS_CANCELED,
        'trialing' => Subscription::STATUS_ACTIVE,
        'active' => Subscription::STATUS_ACTIVE,
        'past_due' => Subscription::STATUS_PAST_DUE,
        'unpaid' => Subscription::STATUS_UNPAID,
        'paused' => Subscription::STATUS_PAUSED,
        'canceled' => Subscription::STATUS_CANCELED,
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
    public function checkoutSession(string $sessionId, string $source = SubscriptionEvent::SOURCE_WEBHOOK): ?Checkout
    {
        $session = $this->retrieve(fn () => $this->stripe()->checkout->sessions->retrieve($sessionId));

        $checkout = Checkout::findByGatewayId(StripeGateway::NAME, $session['id'])
            ?? $this->checkoutByReference(data_get($session, 'metadata.checkout_reference') ?? $session['client_reference_id'] ?? null);

        if (! $checkout) {
            return null;
        }

        $checkout->fill([
            'status' => self::CHECKOUT_STATUSES[$session['status']] ?? $checkout->status,
            'gateway_session_id' => $session['id'],
            'expires_at' => CarbonImmutable::createFromTimestamp($session['expires_at']),
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
     * Link a Stripe customer to our user for their email address, creating
     * the user if needed, and return the user. Typing an email address on
     * a payment page proves nothing about owning it, so an existing user's
     * name is only filled in when they don't have one. A customer without
     * an email address, or one deleted in Stripe before we ever recorded
     * it, has no user.
     */
    public function customer(string $customerId, string $source = SubscriptionEvent::SOURCE_WEBHOOK): ?User
    {
        $customer = $this->retrieve(fn () => $this->stripe()->customers->retrieve($customerId));
        $record = GatewayCustomer::findByGatewayId(StripeGateway::NAME, $customerId);

        if (data_get($customer, 'deleted')) {
            return $record ? User::withTrashed()->find($record->user_id) : null;
        }

        $user = $record
            ? User::withTrashed()->find($record->user_id)
            : $this->userForEmail(data_get($customer, 'email'), data_get($customer, 'name'));

        if (! $user) {
            return null;
        }

        if (! $record) {
            $user->gatewayCustomers()->create([
                'gateway' => StripeGateway::NAME,
                'gateway_customer_id' => $customerId,
            ]);
        }

        if (blank($user->name) && filled(data_get($customer, 'name'))) {
            $user->update(['name' => data_get($customer, 'name')]);
        }

        return $user;
    }

    /**
     * Update a recurring donation, and record what changed in its history:
     * created, amount changed, paused, resumed, or canceled. Renewals and
     * failed payments are recorded from invoices.
     */
    public function subscription(string $subscriptionId, string $source = SubscriptionEvent::SOURCE_WEBHOOK): ?Subscription
    {
        $stripeSubscription = $this->retrieve(fn () => $this->stripe()->subscriptions->retrieve($subscriptionId));

        $user = $this->userForCustomer(data_get($stripeSubscription, 'customer'));

        if (! $user) {
            Log::warning("Stripe subscription {$subscriptionId} has no customer with an email address; it was not recorded.");

            return null;
        }

        $item = data_get($stripeSubscription, 'items.data.0');
        $timestamp = fn (string $key) => ($value = data_get($stripeSubscription, $key)) ? CarbonImmutable::createFromTimestamp($value) : null;

        $subscription = Subscription::findByGatewayId(StripeGateway::NAME, $subscriptionId)
            ?? new Subscription(['gateway' => StripeGateway::NAME, 'gateway_subscription_id' => $subscriptionId]);

        $previousAmount = $subscription->exists ? $subscription->amount_cents : null;
        $previousStatus = $subscription->exists ? $subscription->status : null;

        $subscription->fill([
            'user_id' => $user->id,
            'designation_id' => $this->designationFor(data_get($stripeSubscription, 'metadata.designation'))->id,
            'amount_cents' => (int) data_get($item, 'price.unit_amount', 0) * (int) data_get($item, 'quantity', 1),
            'currency' => data_get($stripeSubscription, 'currency', 'usd'),
            'interval' => data_get($item, 'price.recurring.interval') === 'year' ? Subscription::INTERVAL_YEAR : Subscription::INTERVAL_MONTH,
            'status' => self::SUBSCRIPTION_STATUSES[data_get($stripeSubscription, 'status')] ?? Subscription::STATUS_INCOMPLETE,
            'started_at' => $timestamp('start_date'),
            'current_period_end' => ($end = data_get($item, 'current_period_end')) ? CarbonImmutable::createFromTimestamp($end) : null,
            'cancel_at' => $timestamp('cancel_at'),
            'canceled_at' => $timestamp('canceled_at') ?? $timestamp('ended_at'),
            'gateway_data' => $stripeSubscription,
        ]);

        $subscription->save();

        $this->recordEvent($subscription, SubscriptionEvent::TYPE_CREATED, $subscriptionId, $source, $subscription->started_at);

        if ($previousAmount !== null && $previousAmount !== $subscription->amount_cents) {
            $this->recordEvent($subscription, SubscriptionEvent::TYPE_AMOUNT_CHANGED, 'amount_changed:' . now()->timestamp, $source, now(), [
                'from_cents' => $previousAmount,
                'to_cents' => $subscription->amount_cents,
            ]);
        }

        if ($subscription->status === Subscription::STATUS_PAUSED && $previousStatus !== Subscription::STATUS_PAUSED) {
            $this->recordEvent($subscription, SubscriptionEvent::TYPE_PAUSED, 'paused:' . now()->timestamp, $source, now());
        }

        if ($previousStatus === Subscription::STATUS_PAUSED && $subscription->status === Subscription::STATUS_ACTIVE) {
            $this->recordEvent($subscription, SubscriptionEvent::TYPE_RESUMED, 'resumed:' . now()->timestamp, $source, now());
        }

        if ($subscription->status === Subscription::STATUS_CANCELED) {
            $this->recordEvent($subscription, SubscriptionEvent::TYPE_CANCELED, $subscriptionId, $source, $subscription->canceled_at ?? now());
        }

        return $subscription;
    }

    /**
     * Record the payments of a recurring donation's invoice, and the
     * renewal or failed payment in its history. Invoices that aren't for a
     * subscription are ignored. Returns the subscription, if any.
     */
    public function invoice(string $invoiceId, string $source = SubscriptionEvent::SOURCE_WEBHOOK): ?Subscription
    {
        $invoice = $this->retrieve(fn () => $this->stripe()->invoices->retrieve($invoiceId));
        $subscriptionId = data_get($invoice, 'parent.subscription_details.subscription');

        if (! $subscriptionId) {
            return null;
        }

        $subscription = $this->subscription($subscriptionId, $source);

        if (! $subscription) {
            return null;
        }

        // The first invoice is paid on our donate page, so its payment
        // belongs to the checkout that started the subscription
        $checkoutReference = data_get($invoice, 'billing_reason') === 'subscription_create'
            ? data_get($subscription->gateway_data, 'metadata.checkout_reference')
            : null;

        $payments = $this->call(fn () => $this->stripe()->invoicePayments->all(['invoice' => $invoiceId, 'limit' => 10]))->toArray();

        foreach (data_get($payments, 'data', []) as $payment) {
            if ($paymentIntentId = data_get($payment, 'payment.payment_intent')) {
                $this->paymentIntent($paymentIntentId, $source, $subscription, $invoiceId, $checkoutReference);
            }
        }

        if (data_get($invoice, 'status') === 'paid' && data_get($invoice, 'billing_reason') === 'subscription_cycle') {
            $paidAt = data_get($invoice, 'status_transitions.paid_at');

            $this->recordEvent($subscription, SubscriptionEvent::TYPE_RENEWED, $invoiceId, $source, $paidAt ? CarbonImmutable::createFromTimestamp($paidAt) : now());
        }

        if (data_get($invoice, 'status') === 'open' && data_get($invoice, 'attempted') && data_get($invoice, 'amount_remaining') > 0) {
            // One entry per failed attempt, so retries that fail again are recorded too
            $attempt = (int) data_get($invoice, 'attempt_count', 1);

            $this->recordEvent($subscription, SubscriptionEvent::TYPE_PAYMENT_FAILED, "{$invoiceId}:{$attempt}", $source, now(), ['attempt' => $attempt]);
        }

        return $subscription;
    }

    /**
     * Record a payment as a donation: one row per PaymentIntent, whether it
     * succeeded, is still settling (such as a bank payment), failed, or was
     * refunded later. A payment that was started but never attempted, such
     * as a checkout left before paying, is not recorded.
     *
     * Subscription payments are found through their invoice; invoice() passes
     * the subscription and invoice when it already has them. Other payments
     * belong to the checkout in their metadata, or, without one (such as
     * Payment Link donations), go to the General Fund. A soft-deleted donor
     * is restored when a payment from them is received.
     */
    public function paymentIntent(string $paymentIntentId, string $source = SubscriptionEvent::SOURCE_WEBHOOK, ?Subscription $subscription = null, ?string $invoiceId = null, ?string $checkoutReference = null): ?Donation
    {
        $paymentIntent = $this->retrieve(fn () => $this->stripe()->paymentIntents->retrieve($paymentIntentId, [
            'expand' => ['latest_charge.balance_transaction'],
        ]));

        $charge = data_get($paymentIntent, 'latest_charge');
        $charge = is_array($charge) ? $charge : null;
        $donation = Donation::findByGatewayId(StripeGateway::NAME, $paymentIntentId);
        $status = $this->donationStatus($paymentIntent, $charge);

        if ($status === null && ! $donation) {
            return null;
        }

        if (! $subscription && ! $invoiceId) {
            [$subscription, $invoiceId] = $this->subscriptionForPayment($paymentIntentId, $source);
        }

        $checkout = $this->checkoutByReference($checkoutReference ?? data_get($paymentIntent, 'metadata.checkout_reference'));

        $user = $subscription?->user()->withTrashed()->first()
            ?? $checkout?->user()->withTrashed()->first()
            ?? $this->userForCustomer(data_get($paymentIntent, 'customer'))
            ?? $this->userForEmail(data_get($charge, 'billing_details.email'), data_get($charge, 'billing_details.name'));

        if (! $user) {
            Log::warning("Stripe payment {$paymentIntentId} has no customer or email address; it was not recorded.");

            return null;
        }

        $designation = $subscription?->designation_id
            ?? $checkout?->designation_id
            ?? $this->designationFor(data_get($paymentIntent, 'metadata.designation'))->id;

        $donation ??= new Donation(['gateway' => StripeGateway::NAME, 'gateway_payment_id' => $paymentIntentId]);

        $donation->fill([
            'user_id' => $user->id,
            'designation_id' => $designation,
            'subscription_id' => $subscription?->id,
            'checkout_id' => $checkout?->id ?? $donation->checkout_id,
            'gateway_invoice_id' => $invoiceId,
            'amount_cents' => (int) data_get($paymentIntent, 'amount'),
            'fee_cents' => data_get($charge, 'balance_transaction.fee'),
            'refunded_cents' => (int) data_get($charge, 'amount_refunded', 0),
            'currency' => data_get($paymentIntent, 'currency', 'usd'),
            'status' => $status ?? $donation->status,
            'paid_at' => in_array($status, Donation::RECEIVED_STATUSES, true) && data_get($charge, 'created')
                ? CarbonImmutable::createFromTimestamp(data_get($charge, 'created'))
                : $donation->paid_at,
            'gateway_data' => $paymentIntent,
        ]);

        $donation->save();

        // A donor whose account was deleted, such as one mistaken for spam,
        // comes back when we actually receive their money
        if ($donation->wasReceived() && $user->trashed()) {
            $user->restore();
        }

        return $donation;
    }

    /**
     * Update the donation a charge was paid by, such as after a refund.
     */
    public function charge(string $chargeId, string $source = SubscriptionEvent::SOURCE_WEBHOOK): ?Donation
    {
        $charge = $this->retrieve(fn () => $this->stripe()->charges->retrieve($chargeId));
        $paymentIntentId = data_get($charge, 'payment_intent');

        return $paymentIntentId ? $this->paymentIntent($paymentIntentId, $source) : null;
    }

    /**
     * Retrieve a Stripe object, refuse it if it's from the other mode, and
     * return it as an array.
     *
     * @param  callable(): \Stripe\StripeObject  $request
     * @return array<string, mixed>
     */
    private function retrieve(callable $request): array
    {
        $object = $this->call($request);

        $this->assertLivemode($object);

        return $object->toArray();
    }

    /**
     * Our donation status for a PaymentIntent, or null for one that was
     * never attempted. Refunds keep a payment received.
     *
     * @param  array<string, mixed>  $paymentIntent
     * @param  array<string, mixed>|null  $charge
     */
    private function donationStatus(array $paymentIntent, ?array $charge): ?string
    {
        $failed = filled(data_get($paymentIntent, 'last_payment_error'));

        return match (data_get($paymentIntent, 'status')) {
            'succeeded' => match (true) {
                data_get($charge, 'amount_refunded', 0) >= data_get($paymentIntent, 'amount') => Donation::STATUS_REFUNDED,
                data_get($charge, 'amount_refunded', 0) > 0 => Donation::STATUS_PARTIALLY_REFUNDED,
                default => Donation::STATUS_SUCCEEDED,
            },
            'processing', 'requires_capture' => Donation::STATUS_PENDING,
            default => $failed ? Donation::STATUS_FAILED : null,
        };
    }

    /**
     * The subscription and invoice a payment was for, if any, found through
     * Stripe's invoice payments.
     *
     * @return array{0: ?Subscription, 1: ?string}
     */
    private function subscriptionForPayment(string $paymentIntentId, string $source): array
    {
        $invoicePayments = $this->call(fn () => $this->stripe()->invoicePayments->all([
            'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntentId],
            'limit' => 1,
        ]))->toArray();

        $invoiceId = data_get($invoicePayments, 'data.0.invoice');

        if (! $invoiceId) {
            return [null, null];
        }

        $invoice = $this->retrieve(fn () => $this->stripe()->invoices->retrieve($invoiceId));
        $subscriptionId = data_get($invoice, 'parent.subscription_details.subscription');

        $subscription = $subscriptionId
            ? Subscription::findByGatewayId(StripeGateway::NAME, $subscriptionId) ?? $this->subscription($subscriptionId, $source)
            : null;

        return [$subscription, $invoiceId];
    }

    /**
     * The user for a Stripe customer ID: the recorded one, or else the
     * customer synced from Stripe.
     */
    private function userForCustomer(?string $customerId): ?User
    {
        if (! $customerId) {
            return null;
        }

        $record = GatewayCustomer::findByGatewayId(StripeGateway::NAME, $customerId);

        return $record ? User::withTrashed()->find($record->user_id) : $this->customer($customerId);
    }

    /**
     * The user with an email address, including a soft-deleted one, or a
     * new user with that address and name.
     */
    private function userForEmail(?string $email, ?string $name): ?User
    {
        if (blank($email)) {
            return null;
        }

        return User::withTrashed()->firstOrCreate(
            ['email' => mb_strtolower(trim($email))],
            ['name' => filled($name) ? $name : null],
        );
    }

    /**
     * The designation with a slug, including a soft-deleted one, or else
     * the General Fund.
     */
    private function designationFor(?string $slug): Designation
    {
        return (filled($slug) ? Designation::withTrashed()->where('slug', $slug)->first() : null)
            ?? Designation::generalFund();
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

    /**
     * Add to a subscription's history, once per type and source reference.
     *
     * @param  array<string, mixed>|null  $data
     */
    private function recordEvent(Subscription $subscription, string $type, string $reference, string $source, mixed $occurredAt, ?array $data = null): void
    {
        SubscriptionEvent::firstOrCreate(
            ['subscription_id' => $subscription->id, 'type' => $type, 'source_reference' => $reference],
            ['source' => $source, 'occurred_at' => $occurredAt ?? now(), 'data' => $data],
        );
    }
}
