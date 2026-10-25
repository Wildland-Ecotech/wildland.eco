<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookEvent;
use App\Models\Checkout;
use App\Models\WebhookEvent;
use App\Payments\Stripe\StripeEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;
use Throwable;

/**
 * Processing stored Stripe events (ProcessWebhookEvent and
 * StripeEventProcessor), and syncing the objects they are about
 * (StripeSync), without calling Stripe (see FakesStripeApi).
 */
class StripeEventProcessingTest extends TestCase
{
    use FakesStripeApi, RefreshDatabase;

    private function storeEvent(string $type, string $objectId): WebhookEvent
    {
        return WebhookEvent::create([
            'gateway' => 'stripe',
            'event_id' => 'evt_' . uniqid(),
            'type' => $type,
            'payload' => ['id' => 'evt_example', 'type' => $type, 'data' => ['object' => ['id' => $objectId]]],
        ]);
    }

    /**
     * Run the job directly, as the queue worker would, returning whatever
     * it threw.
     */
    private function process(WebhookEvent $event): ?Throwable
    {
        try {
            app()->call([new ProcessWebhookEvent($event), 'handle']);
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * Queue Stripe's response to retrieving a Checkout Session.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function queueSession(string $id, string $status, array $overrides = []): void
    {
        $this->queueStripeResponse(200, array_merge([
            'id' => $id,
            'object' => 'checkout.session',
            'status' => $status,
            'expires_at' => 1793000000,
            'livemode' => false,
            'client_reference_id' => null,
            'metadata' => [],
        ], $overrides));
    }

    public function test_every_event_stripe_sends_is_listed_in_docker_compose(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        preg_match('/STRIPE_EVENTS: >-\s+(\S+)/', $compose, $match);

        $this->assertEqualsCanonicalizing(array_keys(StripeEventProcessor::EVENTS), explode(',', $match[1]));
    }

    public function test_a_completed_session_completes_its_checkout(): void
    {
        $checkout = Checkout::factory()->create(['gateway_session_id' => 'cs_test_paid']);
        $event = $this->storeEvent('checkout.session.completed', 'cs_test_paid');

        $this->queueSession('cs_test_paid', 'complete');

        $this->assertNull($this->process($event));

        $checkout->refresh();
        $event->refresh();

        $this->assertSame(Checkout::STATUS_COMPLETED, $checkout->status);
        $this->assertNotNull($checkout->completed_at);
        $this->assertSame(1793000000, $checkout->expires_at->getTimestamp());

        $this->assertSame(['get', '/v1/checkout/sessions/cs_test_paid'], [$this->stripeRequests[0]['method'], $this->stripeRequests[0]['path']]);
        $this->assertTrue($event->isProcessed());
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->error);
    }

    public function test_an_expired_session_expires_its_checkout(): void
    {
        $checkout = Checkout::factory()->create(['gateway_session_id' => 'cs_test_abandoned']);

        $this->queueSession('cs_test_abandoned', 'expired');

        $this->process($this->storeEvent('checkout.session.expired', 'cs_test_abandoned'));

        $this->assertSame(Checkout::STATUS_EXPIRED, $checkout->fresh()->status);
        $this->assertNull($checkout->fresh()->completed_at);
    }

    public function test_the_current_session_wins_over_the_event_type(): void
    {
        // An old "expired" event can't undo a completed checkout, because
        // the session is fetched as it is now
        $checkout = Checkout::factory()->create(['gateway_session_id' => 'cs_test_current']);

        $this->queueSession('cs_test_current', 'complete');

        $this->process($this->storeEvent('checkout.session.expired', 'cs_test_current'));

        $this->assertSame(Checkout::STATUS_COMPLETED, $checkout->fresh()->status);
    }

    public function test_resyncing_keeps_when_a_checkout_was_first_completed(): void
    {
        $checkout = Checkout::factory()->completed()->create(['gateway_session_id' => 'cs_test_again', 'completed_at' => now()->subDay()]);
        $completedAt = $checkout->fresh()->completed_at;

        $this->queueSession('cs_test_again', 'complete');

        $this->process($this->storeEvent('checkout.session.completed', 'cs_test_again'));

        $this->assertTrue($completedAt->equalTo($checkout->fresh()->completed_at));
    }

    public function test_a_checkout_without_its_session_id_is_found_by_reference(): void
    {
        // Such as when the session was created but saving its ID failed
        $checkout = Checkout::factory()->create(['gateway_session_id' => null]);
        $checkout->delete();

        $this->queueSession('cs_test_unsaved', 'complete', ['metadata' => ['checkout_reference' => $checkout->reference]]);

        $this->process($this->storeEvent('checkout.session.completed', 'cs_test_unsaved'));

        $checkout = Checkout::withTrashed()->find($checkout->id);

        $this->assertSame('cs_test_unsaved', $checkout->gateway_session_id);
        $this->assertSame(Checkout::STATUS_COMPLETED, $checkout->status);
        $this->assertTrue($checkout->trashed());
    }

    public function test_sessions_that_arent_ours_are_ignored(): void
    {
        // Such as a Payment Link's session
        $this->queueSession('cs_test_payment_link', 'complete');

        $event = $this->storeEvent('checkout.session.completed', 'cs_test_payment_link');

        $this->assertNull($this->process($event));
        $this->assertTrue($event->fresh()->isProcessed());
        $this->assertSame(0, Checkout::count());
    }

    public function test_events_without_a_sync_are_processed_without_calling_stripe(): void
    {
        $event = $this->storeEvent('product.updated', 'prod_example');

        $this->assertNull($this->process($event));
        $this->assertTrue($event->fresh()->isProcessed());
        $this->assertEmpty($this->stripeRequests);
    }

    public function test_a_failure_is_recorded_and_retried(): void
    {
        $checkout = Checkout::factory()->create(['gateway_session_id' => 'cs_test_retry']);
        $event = $this->storeEvent('checkout.session.completed', 'cs_test_retry');

        $this->queueStripeError(500, 'Stripe had a problem');

        $this->assertNotNull($this->process($event));

        $event->refresh();
        $this->assertFalse($event->isProcessed());
        $this->assertSame(1, $event->attempts);
        $this->assertStringContainsString('Stripe had a problem', $event->error);

        // The retry succeeds and clears the error
        $this->queueSession('cs_test_retry', 'complete');

        $this->assertNull($this->process($event));

        $event->refresh();
        $this->assertTrue($event->isProcessed());
        $this->assertSame(2, $event->attempts);
        $this->assertNull($event->error);
        $this->assertSame(Checkout::STATUS_COMPLETED, $checkout->fresh()->status);
    }

    public function test_an_object_from_the_other_stripe_mode_is_refused(): void
    {
        $checkout = Checkout::factory()->create(['gateway_session_id' => 'cs_live_wrong']);
        $event = $this->storeEvent('checkout.session.completed', 'cs_live_wrong');

        $this->queueSession('cs_live_wrong', 'complete', ['livemode' => true]);

        $this->assertNotNull($this->process($event));

        $this->assertStringContainsString('live mode', $event->fresh()->error);
        $this->assertSame(Checkout::STATUS_STARTED, $checkout->fresh()->status);
    }

    public function test_a_processed_event_is_not_processed_again(): void
    {
        $event = $this->storeEvent('checkout.session.completed', 'cs_test_done');
        $event->update(['processed_at' => now(), 'attempts' => 1]);

        $this->assertNull($this->process($event));

        $this->assertEmpty($this->stripeRequests);
        $this->assertSame(1, $event->fresh()->attempts);
    }

    public function test_the_job_retries_with_increasing_delays_and_is_unique_per_event(): void
    {
        $job = new ProcessWebhookEvent($this->storeEvent('invoice.paid', 'in_example'));

        $this->assertSame(6, $job->tries);
        $this->assertSame([60, 300, 1800, 7200, 43200], $job->backoff());
        $this->assertSame((string) $job->event->id, $job->uniqueId());
    }
}
