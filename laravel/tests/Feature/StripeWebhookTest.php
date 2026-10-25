<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

/**
 * Receiving Stripe webhooks at POST /webhooks/stripe: signature checks,
 * storage, and queueing. Processing is covered by StripeEventProcessingTest.
 */
class StripeWebhookTest extends TestCase
{
    use FakesStripeApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * A Stripe event payload, as Stripe sends it.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function event(array $overrides = []): string
    {
        return json_encode(array_merge([
            'id' => 'evt_test_example',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'api_version' => config('services.stripe.api_version'),
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => ['id' => 'cs_test_example', 'object' => 'checkout.session']],
        ], $overrides));
    }

    /**
     * A Stripe-Signature header for a payload, signed as Stripe signs it.
     */
    private function signature(string $payload, ?int $timestamp = null, ?string $secret = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? config('services.stripe.webhook_secret'));

        return "t={$timestamp},v1={$signature}";
    }

    private function send(string $payload, ?string $signature = null)
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($signature !== '') {
            $headers['Stripe-Signature'] = $signature ?? $this->signature($payload);
        }

        return $this->call('POST', route('webhooks.stripe'), [], [], [], $this->transformHeadersToServerVars($headers), $payload);
    }

    public function test_a_signed_event_is_stored_and_queued(): void
    {
        $this->send($this->event())
            ->assertOk()
            ->assertSee('Queued');

        $event = WebhookEvent::sole();

        $this->assertSame('stripe', $event->gateway);
        $this->assertSame('evt_test_example', $event->event_id);
        $this->assertSame('checkout.session.completed', $event->type);
        $this->assertSame('cs_test_example', $event->payload['data']['object']['id']);
        $this->assertFalse($event->isProcessed());

        Queue::assertPushed(ProcessWebhookEvent::class, fn ($job) => $job->event->is($event));
        $this->assertEmpty($this->stripeRequests);
    }

    public function test_events_we_dont_handle_are_accepted_too(): void
    {
        $this->send($this->event(['type' => 'product.updated']))->assertOk();

        $this->assertSame('product.updated', WebhookEvent::sole()->type);
    }

    public function test_a_redelivered_event_is_stored_once(): void
    {
        $this->send($this->event())->assertOk();
        $this->send($this->event())->assertOk()->assertSee('Queued');

        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_an_event_already_processed_is_acknowledged_without_queueing(): void
    {
        $this->send($this->event())->assertOk();
        WebhookEvent::sole()->update(['processed_at' => now()]);

        $this->send($this->event())->assertOk()->assertSee('Already processed');

        Queue::assertPushed(ProcessWebhookEvent::class, 1);
    }

    public function test_missing_and_invalid_signatures_are_rejected(): void
    {
        $payload = $this->event();

        $this->send($payload, '')->assertStatus(400);
        $this->send($payload, 't=' . time() . ',v1=0000')->assertStatus(400);
        $this->send($payload, $this->signature($payload, secret: 'whsec_someone_else'))->assertStatus(400);
        $this->send($payload, $this->signature($this->event(['type' => 'charge.refunded'])))->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_an_old_signature_is_rejected_so_events_cant_be_replayed(): void
    {
        $payload = $this->event();

        $this->send($payload, $this->signature($payload, time() - 600))->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_an_event_from_the_other_stripe_mode_is_rejected(): void
    {
        Log::spy();

        $this->send($this->event(['livemode' => true]))->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'wrong mode'));
    }

    public function test_an_event_with_another_api_version_is_accepted_with_a_warning(): void
    {
        Log::spy();

        $this->send($this->event(['api_version' => '2026-04-22.dahlia']))->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, '2026-04-22.dahlia'));
    }

    public function test_webhooks_fail_loudly_without_a_signing_secret(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->send($this->event(), 't=1,v1=0')->assertStatus(500);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_webhooks_skip_csrf_protection_and_sessions(): void
    {
        // Tests skip CSRF checks, so check the route's configuration instead
        $excluded = app('router')->getRoutes()->getByName('webhooks.stripe')->excludedMiddleware();

        $this->assertContains(PreventRequestForgery::class, $excluded);
        $this->assertContains(StartSession::class, $excluded);
    }
}
