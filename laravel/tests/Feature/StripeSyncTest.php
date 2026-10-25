<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Designation;
use App\Models\Donation;
use App\Models\GatewayCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Payments\Stripe\StripeEventProcessor;
use App\Payments\Stripe\StripeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

/**
 * Payment sync (StripeSync): donations, subscriptions and their history,
 * refunds, and customers, from Stripe objects shaped as our pinned API
 * version returns them, without calling Stripe (see FakesStripeApi).
 */
class StripeSyncTest extends TestCase
{
    use FakesStripeApi, RefreshDatabase;

    private const PAID_AT = 1793000000;

    private function sync(): StripeSync
    {
        return app(StripeSync::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function charge(array $overrides = []): array
    {
        return array_merge([
            'id' => 'ch_example',
            'object' => 'charge',
            'amount' => 5000,
            'amount_refunded' => 0,
            'created' => self::PAID_AT,
            'payment_intent' => 'pi_example',
            'billing_details' => ['email' => null, 'name' => null],
            'balance_transaction' => ['id' => 'txn_example', 'object' => 'balance_transaction', 'fee' => 175],
            'livemode' => false,
        ], $overrides);
    }

    /**
     * Queue a PaymentIntent, as retrieved with its charge and fee expanded.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function queuePaymentIntent(string $id, string $status, array $overrides = []): void
    {
        $this->queueStripeResponse(200, array_merge([
            'id' => $id,
            'object' => 'payment_intent',
            'amount' => 5000,
            'currency' => 'usd',
            'status' => $status,
            'customer' => null,
            'metadata' => [],
            'last_payment_error' => null,
            'latest_charge' => $status === 'succeeded' ? $this->charge(['payment_intent' => $id]) : null,
            'livemode' => false,
        ], $overrides));
    }

    private function queueInvoicePayments(?string $invoiceId = null, ?string $paymentIntentId = null): void
    {
        $this->queueStripeResponse(200, [
            'object' => 'list',
            'url' => '/v1/invoice_payments',
            'has_more' => false,
            'data' => $invoiceId ? [[
                'id' => 'inpay_example',
                'object' => 'invoice_payment',
                'invoice' => $invoiceId,
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntentId],
                'livemode' => false,
            ]] : [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function queueInvoice(string $id, ?string $subscriptionId, string $status, string $billingReason, array $overrides = []): void
    {
        $this->queueStripeResponse(200, array_merge([
            'id' => $id,
            'object' => 'invoice',
            'status' => $status,
            'billing_reason' => $billingReason,
            'attempted' => true,
            'attempt_count' => 1,
            'amount_remaining' => 0,
            'customer' => 'cus_example',
            'parent' => $subscriptionId
                ? ['type' => 'subscription_details', 'subscription_details' => ['subscription' => $subscriptionId, 'metadata' => []]]
                : null,
            'status_transitions' => ['paid_at' => self::PAID_AT],
            'livemode' => false,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function queueSubscription(string $id, string $status, array $overrides = [], int $amount = 2000, string $interval = 'month'): void
    {
        $this->queueStripeResponse(200, array_merge([
            'id' => $id,
            'object' => 'subscription',
            'customer' => 'cus_example',
            'status' => $status,
            'currency' => 'usd',
            'start_date' => 1790000000,
            'cancel_at' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'metadata' => [],
            'livemode' => false,
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_example',
                'object' => 'subscription_item',
                'quantity' => 1,
                'current_period_end' => 1795000000,
                'price' => ['id' => 'price_example', 'object' => 'price', 'unit_amount' => $amount, 'recurring' => ['interval' => $interval]],
            ]]],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function queueCustomer(string $id, array $overrides = []): void
    {
        $this->queueStripeResponse(200, array_merge([
            'id' => $id,
            'object' => 'customer',
            'email' => 'donor@example.com',
            'name' => 'Pat Donor',
            'livemode' => false,
        ], $overrides));
    }

    /**
     * A donor whose Stripe customer (cus_example) is already recorded.
     */
    private function donor(): User
    {
        return GatewayCustomer::factory()->create(['gateway_customer_id' => 'cus_example'])->user;
    }

    public function test_a_one_time_donation_from_our_checkout_is_recorded_with_its_fee(): void
    {
        $checkout = Checkout::factory()->oneTime()->create(['amount_cents' => 5000]);

        $this->queuePaymentIntent('pi_one_time', 'succeeded', ['metadata' => ['checkout_reference' => $checkout->reference]]);
        $this->queueInvoicePayments();

        $donation = $this->sync()->paymentIntent('pi_one_time');

        $this->assertTrue($donation->user->is($checkout->user));
        $this->assertTrue($donation->checkout->is($checkout));
        $this->assertTrue($donation->designation->is(Designation::generalFund()));
        $this->assertNull($donation->subscription_id);
        $this->assertNull($donation->gateway_invoice_id);
        $this->assertSame(5000, $donation->amount_cents);
        $this->assertSame(175, $donation->fee_cents);
        $this->assertSame(0, $donation->refunded_cents);
        $this->assertSame(Donation::STATUS_SUCCEEDED, $donation->status);
        $this->assertSame(self::PAID_AT, $donation->paid_at->getTimestamp());
        $this->assertSame('pi_one_time', $donation->gateway_data['id']);

        $this->assertSame('/v1/payment_intents/pi_one_time', $this->stripeRequests[0]['path']);
        $this->assertSame(['latest_charge.balance_transaction'], $this->stripeRequests[0]['params']['expand']);
        $this->assertSame(['type' => 'payment_intent', 'payment_intent' => 'pi_one_time'], $this->stripeRequests[1]['params']['payment']);
    }

    public function test_syncing_a_payment_again_updates_the_same_row_even_when_soft_deleted(): void
    {
        $checkout = Checkout::factory()->oneTime()->create();

        foreach (range(1, 2) as $i) {
            $this->queuePaymentIntent('pi_twice', 'succeeded', ['metadata' => ['checkout_reference' => $checkout->reference]]);
            $this->queueInvoicePayments();
            $this->sync()->paymentIntent('pi_twice');
        }

        Donation::sole()->delete();

        $this->queuePaymentIntent('pi_twice', 'succeeded', ['metadata' => ['checkout_reference' => $checkout->reference]]);
        $this->queueInvoicePayments();
        $this->sync()->paymentIntent('pi_twice');

        $this->assertSame(1, Donation::withTrashed()->count());
        $this->assertSoftDeleted(Donation::withTrashed()->sole());
    }

    public function test_a_payment_link_donation_creates_the_donor_from_the_charge(): void
    {
        $this->queuePaymentIntent('pi_link', 'succeeded', [
            'latest_charge' => $this->charge(['billing_details' => ['email' => ' Link@Example.com ', 'name' => 'Sam Link']]),
        ]);
        $this->queueInvoicePayments();

        $donation = $this->sync()->paymentIntent('pi_link');

        $this->assertSame('link@example.com', $donation->user->email);
        $this->assertSame('Sam Link', $donation->user->name);
        $this->assertNull($donation->checkout_id);
        $this->assertTrue($donation->designation->is(Designation::generalFund()));
    }

    public function test_a_payment_from_a_new_stripe_customer_records_the_customer(): void
    {
        $this->queuePaymentIntent('pi_new_customer', 'succeeded', ['customer' => 'cus_new']);
        $this->queueInvoicePayments();
        $this->queueCustomer('cus_new', ['email' => 'New@Example.com', 'name' => 'Nova Donor']);

        $donation = $this->sync()->paymentIntent('pi_new_customer');

        $this->assertSame('new@example.com', $donation->user->email);
        $this->assertSame('Nova Donor', $donation->user->name);
        $this->assertTrue(GatewayCustomer::findByGatewayId('stripe', 'cus_new')->user->is($donation->user));
    }

    public function test_failed_and_settling_payments_are_recorded_but_unattempted_ones_are_not(): void
    {
        $this->donor();

        $this->queuePaymentIntent('pi_declined', 'requires_payment_method', [
            'customer' => 'cus_example',
            'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
        ]);
        $this->queueInvoicePayments();

        $this->assertSame(Donation::STATUS_FAILED, $this->sync()->paymentIntent('pi_declined')->status);

        $this->queuePaymentIntent('pi_bank', 'processing', ['customer' => 'cus_example']);
        $this->queueInvoicePayments();

        $pending = $this->sync()->paymentIntent('pi_bank');
        $this->assertSame(Donation::STATUS_PENDING, $pending->status);
        $this->assertNull($pending->paid_at);
        $this->assertFalse($pending->wasReceived());

        $requests = count($this->stripeRequests);
        $this->queuePaymentIntent('pi_abandoned', 'requires_payment_method', ['customer' => 'cus_example']);

        $this->assertNull($this->sync()->paymentIntent('pi_abandoned'));
        $this->assertCount($requests + 1, $this->stripeRequests);
        $this->assertSame(2, Donation::count());
    }

    public function test_a_bank_payment_that_settles_becomes_succeeded(): void
    {
        $this->donor();

        $this->queuePaymentIntent('pi_settles', 'processing', ['customer' => 'cus_example']);
        $this->queueInvoicePayments();
        $this->sync()->paymentIntent('pi_settles');

        $this->queuePaymentIntent('pi_settles', 'succeeded', ['customer' => 'cus_example']);
        $this->queueInvoicePayments();

        $donation = $this->sync()->paymentIntent('pi_settles');

        $this->assertSame(Donation::STATUS_SUCCEEDED, $donation->status);
        $this->assertNotNull($donation->paid_at);
        $this->assertSame(1, Donation::count());
    }

    public function test_refunds_update_the_donation_and_keep_it_received(): void
    {
        $this->donor();

        foreach ([2500 => Donation::STATUS_PARTIALLY_REFUNDED, 5000 => Donation::STATUS_REFUNDED] as $refunded => $status) {
            $this->queueStripeResponse(200, $this->charge(['id' => 'ch_refunded', 'payment_intent' => 'pi_refunded']));
            $this->queuePaymentIntent('pi_refunded', 'succeeded', [
                'customer' => 'cus_example',
                'latest_charge' => $this->charge(['amount_refunded' => $refunded]),
            ]);
            $this->queueInvoicePayments();

            $donation = $this->sync()->charge('ch_refunded');

            $this->assertSame($status, $donation->status);
            $this->assertSame($refunded, $donation->refunded_cents);
            $this->assertTrue($donation->wasReceived());
        }

        $this->assertSame('/v1/charges/ch_refunded', $this->stripeRequests[0]['path']);
    }

    public function test_a_soft_deleted_donor_is_restored_only_when_a_payment_is_received(): void
    {
        $user = $this->donor();
        $user->delete();

        $this->queuePaymentIntent('pi_failed', 'requires_payment_method', [
            'customer' => 'cus_example',
            'last_payment_error' => ['code' => 'card_declined'],
        ]);
        $this->queueInvoicePayments();
        $this->sync()->paymentIntent('pi_failed');

        $this->assertSoftDeleted($user);

        $this->queuePaymentIntent('pi_received', 'succeeded', ['customer' => 'cus_example']);
        $this->queueInvoicePayments();
        $this->sync()->paymentIntent('pi_received');

        $this->assertNotSoftDeleted($user);
    }

    public function test_a_subscription_is_recorded_with_its_history(): void
    {
        $user = $this->donor();
        $designation = Designation::factory()->restricted()->create(['slug' => 'high-park']);

        $this->queueSubscription('sub_monthly', 'active', ['metadata' => ['designation' => 'high-park']]);

        $subscription = $this->sync()->subscription('sub_monthly');

        $this->assertTrue($subscription->user->is($user));
        $this->assertTrue($subscription->designation->is($designation));
        $this->assertSame(2000, $subscription->amount_cents);
        $this->assertSame(Subscription::INTERVAL_MONTH, $subscription->interval);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(1790000000, $subscription->started_at->getTimestamp());
        $this->assertSame(1795000000, $subscription->current_period_end->getTimestamp());
        $this->assertSame(['created'], $subscription->events->pluck('type')->all());
        $this->assertSame(SubscriptionEvent::SOURCE_WEBHOOK, $subscription->events->first()->source);

        // The donor changes the amount, then cancels
        $this->queueSubscription('sub_monthly', 'active', ['metadata' => ['designation' => 'high-park']], amount: 3500);
        $this->sync()->subscription('sub_monthly');

        $this->queueSubscription('sub_monthly', 'canceled', ['canceled_at' => 1796000000], amount: 3500);
        $subscription = $this->sync()->subscription('sub_monthly', SubscriptionEvent::SOURCE_IMPORT);

        $this->assertSame(3500, $subscription->amount_cents);
        $this->assertSame(Subscription::STATUS_CANCELED, $subscription->status);
        $this->assertSame(1796000000, $subscription->canceled_at->getTimestamp());
        $this->assertSame(['created', 'amount_changed', 'canceled'], $subscription->events()->orderBy('id')->pluck('type')->all());
        // PostgreSQL's jsonb doesn't keep key order, so compare without it
        $this->assertEquals(['from_cents' => 2000, 'to_cents' => 3500], $subscription->events()->where('type', 'amount_changed')->sole()->data);

        // Syncing again adds nothing to the history
        $this->queueSubscription('sub_monthly', 'canceled', ['canceled_at' => 1796000000], amount: 3500);
        $this->sync()->subscription('sub_monthly');

        $this->assertSame(3, $subscription->events()->count());
        $this->assertSame(1, Subscription::count());
    }

    public function test_pausing_and_resuming_are_recorded(): void
    {
        $this->donor();

        foreach (['active', 'paused', 'active'] as $status) {
            $this->queueSubscription('sub_paused', $status);
            $this->sync()->subscription('sub_paused');
            $this->travel(1)->minute();
        }

        $this->assertSame(['created', 'paused', 'resumed'], Subscription::sole()->events()->orderBy('id')->pluck('type')->all());
    }

    public function test_annual_and_expired_incomplete_subscriptions(): void
    {
        $this->donor();

        $this->queueSubscription('sub_annual', 'incomplete_expired', ['ended_at' => 1791000000], amount: 10000, interval: 'year');

        $subscription = $this->sync()->subscription('sub_annual');

        $this->assertSame(Subscription::INTERVAL_YEAR, $subscription->interval);
        $this->assertSame(Subscription::STATUS_CANCELED, $subscription->status);
        $this->assertSame(1791000000, $subscription->canceled_at->getTimestamp());
        $this->assertTrue($subscription->designation->is(Designation::generalFund()));
    }

    public function test_a_subscription_for_an_unknown_customer_creates_the_donor(): void
    {
        $this->queueSubscription('sub_new', 'active', ['customer' => 'cus_unknown']);
        $this->queueCustomer('cus_unknown');

        $subscription = $this->sync()->subscription('sub_new');

        $this->assertSame('donor@example.com', $subscription->user->email);
        $this->assertSame('/v1/customers/cus_unknown', $this->stripeRequests[1]['path']);
    }

    public function test_a_paid_renewal_records_the_payment_and_the_renewal(): void
    {
        $this->donor();

        $this->queueInvoice('in_renewal', 'sub_renewing', 'paid', 'subscription_cycle');
        $this->queueSubscription('sub_renewing', 'active');
        $this->queueInvoicePayments('in_renewal', 'pi_renewal');
        $this->queuePaymentIntent('pi_renewal', 'succeeded', ['amount' => 2000, 'customer' => 'cus_example']);

        $this->sync()->invoice('in_renewal');

        $subscription = Subscription::sole();
        $donation = Donation::sole();

        $this->assertTrue($donation->subscription->is($subscription));
        $this->assertSame('in_renewal', $donation->gateway_invoice_id);
        $this->assertSame(2000, $donation->amount_cents);
        $this->assertSame($subscription->designation_id, $donation->designation_id);
        $this->assertNull($donation->checkout_id);

        $renewal = $subscription->events()->where('type', SubscriptionEvent::TYPE_RENEWED)->sole();
        $this->assertSame('in_renewal', $renewal->source_reference);
        $this->assertSame(self::PAID_AT, $renewal->occurred_at->getTimestamp());

        $this->assertSame(['invoice' => 'in_renewal', 'limit' => 10], $this->stripeRequests[2]['params']);
    }

    public function test_the_first_payment_of_a_subscription_belongs_to_its_checkout(): void
    {
        $checkout = Checkout::factory()->create();
        GatewayCustomer::factory()->for($checkout->user)->create(['gateway_customer_id' => 'cus_example']);

        $this->queueInvoice('in_first', 'sub_first', 'paid', 'subscription_create');
        $this->queueSubscription('sub_first', 'active', ['metadata' => ['checkout_reference' => $checkout->reference, 'designation' => 'general-fund']]);
        $this->queueInvoicePayments('in_first', 'pi_first');
        $this->queuePaymentIntent('pi_first', 'succeeded', ['amount' => 2000, 'customer' => 'cus_example']);

        $this->sync()->invoice('in_first');

        $this->assertTrue(Donation::sole()->checkout->is($checkout));
        $this->assertSame(0, SubscriptionEvent::where('type', SubscriptionEvent::TYPE_RENEWED)->count());
    }

    public function test_a_failed_renewal_records_the_failed_payment(): void
    {
        $this->donor();

        $this->queueInvoice('in_failed', 'sub_past_due', 'open', 'subscription_cycle', ['amount_remaining' => 2000, 'attempt_count' => 2]);
        $this->queueSubscription('sub_past_due', 'past_due');
        $this->queueInvoicePayments('in_failed', 'pi_failed_renewal');
        $this->queuePaymentIntent('pi_failed_renewal', 'requires_payment_method', [
            'amount' => 2000,
            'customer' => 'cus_example',
            'last_payment_error' => ['code' => 'card_declined'],
        ]);

        $this->sync()->invoice('in_failed');

        $subscription = Subscription::sole();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
        $this->assertSame(Donation::STATUS_FAILED, Donation::sole()->status);
        $this->assertSame('in_failed:2', $subscription->events()->where('type', SubscriptionEvent::TYPE_PAYMENT_FAILED)->sole()->source_reference);
    }

    public function test_a_payment_event_finds_its_subscription_through_the_invoice(): void
    {
        $this->donor();

        $this->queuePaymentIntent('pi_from_event', 'succeeded', ['amount' => 2000, 'customer' => 'cus_example']);
        $this->queueInvoicePayments('in_from_event', 'pi_from_event');
        $this->queueInvoice('in_from_event', 'sub_from_event', 'paid', 'subscription_cycle');
        $this->queueSubscription('sub_from_event', 'active');

        $donation = $this->sync()->paymentIntent('pi_from_event');

        $this->assertSame('sub_from_event', $donation->subscription->gateway_subscription_id);
        $this->assertSame('in_from_event', $donation->gateway_invoice_id);
    }

    public function test_invoices_not_for_a_subscription_are_ignored(): void
    {
        $this->queueInvoice('in_manual', null, 'paid', 'manual');

        $this->sync()->invoice('in_manual');

        $this->assertCount(1, $this->stripeRequests);
        $this->assertSame(0, Donation::count());
    }

    public function test_customer_updates_fill_a_missing_name_but_never_replace_one(): void
    {
        $user = $this->donor();
        $user->update(['name' => null]);

        $this->queueCustomer('cus_example', ['name' => 'From Stripe']);
        $this->sync()->customer('cus_example');

        $this->assertSame('From Stripe', $user->fresh()->name);

        $this->queueCustomer('cus_example', ['name' => 'Someone Else']);
        $this->sync()->customer('cus_example');

        $this->assertSame('From Stripe', $user->fresh()->name);
    }

    public function test_a_deleted_customer_keeps_its_recorded_donor_in_either_mode(): void
    {
        $user = $this->donor();

        // Deleted objects carry no livemode; production must still accept them
        config(['app.url' => 'https://wildland.eco', 'services.stripe.secret' => 'sk_live_example']);

        $this->queueStripeResponse(200, ['id' => 'cus_example', 'object' => 'customer', 'deleted' => true]);
        $this->assertTrue($this->sync()->customer('cus_example')->is($user));

        $this->queueStripeResponse(200, ['id' => 'cus_never_recorded', 'object' => 'customer', 'deleted' => true]);
        $this->assertNull($this->sync()->customer('cus_never_recorded'));
    }

    public function test_objects_from_the_other_stripe_mode_are_refused(): void
    {
        $this->queuePaymentIntent('pi_live', 'succeeded', ['livemode' => true]);

        $this->assertThrows(fn () => $this->sync()->paymentIntent('pi_live'), RuntimeException::class, 'live mode');

        $this->assertSame(0, Donation::count());
    }

    public function test_webhook_events_reach_payment_sync(): void
    {
        $this->donor();

        $this->queuePaymentIntent('pi_webhook', 'succeeded', ['customer' => 'cus_example']);
        $this->queueInvoicePayments();

        app(StripeEventProcessor::class)->process(WebhookEvent::create([
            'gateway' => 'stripe',
            'event_id' => 'evt_payment',
            'type' => 'payment_intent.succeeded',
            'payload' => ['data' => ['object' => ['id' => 'pi_webhook']]],
        ]));

        $this->assertSame('pi_webhook', Donation::sole()->gateway_payment_id);
    }
}
