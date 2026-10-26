<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Payments\Stripe\StripeSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\FakesStripeApi;
use Tests\TestCase;

/**
 * `php artisan stripe:sync` and StripeImporter: listing Stripe objects and
 * handing each to the sync functions, which StripeSyncTest covers. The sync
 * functions are replaced by a mock here, and Stripe's lists by FakesStripeApi.
 */
class SyncStripeCommandTest extends TestCase
{
    use FakesStripeApi, RefreshDatabase;

    /**
     * Queue one page of a Stripe list.
     *
     * @param  array<int, array<string, mixed>>  $objects
     */
    private function queueList(string $url, array $objects, bool $hasMore = false): void
    {
        $this->queueStripeResponse(200, ['object' => 'list', 'url' => $url, 'has_more' => $hasMore, 'data' => $objects]);
    }

    /**
     * Queue one object of each kind, in the order the importer lists them.
     */
    private function queueOneOfEach(): void
    {
        $this->queueList('/v1/subscriptions', [['id' => 'sub_1', 'object' => 'subscription']]);
        $this->queueList('/v1/invoices', [['id' => 'in_1', 'object' => 'invoice']]);
        $this->queueList('/v1/payment_intents', [['id' => 'pi_1', 'object' => 'payment_intent']]);
        $this->queueList('/v1/refunds', [
            ['id' => 're_1', 'object' => 'refund', 'charge' => 'ch_1', 'payment_intent' => 'pi_1'],
            ['id' => 're_2', 'object' => 'refund', 'charge' => null, 'payment_intent' => 'pi_2'],
        ]);
        $this->queueList('/v1/checkout/sessions', [['id' => 'cs_1', 'object' => 'checkout.session']]);
    }

    public function test_everything_is_imported_through_the_sync_functions(): void
    {
        $import = SubscriptionEvent::SOURCE_IMPORT;

        // Sync functions return the synced record, or null when there's
        // nothing to record
        $this->mock(StripeSync::class, function (MockInterface $sync) use ($import) {
            $sync->shouldReceive('subscription')->once()->with('sub_1', $import)->andReturn(new Subscription);
            $sync->shouldReceive('invoice')->once()->with('in_1', $import)->andReturn(new Subscription);
            $sync->shouldReceive('paymentIntent')->once()->with('pi_1', $import)->andReturn(new Donation);
            $sync->shouldReceive('charge')->once()->with('ch_1', $import)->andReturn(new Donation);
            $sync->shouldReceive('paymentIntent')->once()->with('pi_2', $import)->andReturn(new Donation);
            $sync->shouldReceive('checkoutSession')->once()->with('cs_1', $import)->andReturnNull();
        });

        $this->queueOneOfEach();

        $this->artisan('stripe:sync')
            // The rest of this line wraps at the test terminal's width
            ->expectsOutputToContain('Test mode Stripe sync started')
            ->expectsOutputToContain('Subscription sub_1')
            ->expectsOutputToContain('Payment pi_1')
            ->expectsOutputToContain('Refund re_2')
            ->expectsOutputToContain('Checkout session cs_1')
            ->expectsTable(['Stripe objects', 'Synced', 'Skipped', 'Failed'], [
                ['subscriptions', 1, 0, 0],
                ['invoices', 1, 0, 0],
                ['payments', 1, 0, 0],
                ['refunds', 2, 0, 0],
                ['checkout sessions', 0, 1, 0],
            ])
            ->expectsOutputToContain('Stripe sync finished')
            ->assertSuccessful();

        $this->assertSame(
            ['/v1/subscriptions', '/v1/invoices', '/v1/payment_intents', '/v1/refunds', '/v1/checkout/sessions'],
            array_column($this->stripeRequests, 'path'),
        );
        $this->assertSame(['status' => 'all', 'limit' => 100], $this->stripeRequests[0]['params']);
        $this->assertSame(['limit' => 100], $this->stripeRequests[2]['params']);
    }

    public function test_days_limits_everything_but_subscriptions_to_recent_objects(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->mock(StripeSync::class)->shouldIgnoreMissing();
        $this->queueOneOfEach();

        $this->artisan('stripe:sync', ['--days' => 3])->assertSuccessful();

        $since = now()->subDays(3)->getTimestamp();

        $this->assertArrayNotHasKey('created', $this->stripeRequests[0]['params']);

        foreach (array_slice($this->stripeRequests, 1) as $request) {
            $this->assertSame(['gte' => $since], $request['params']['created'], $request['path']);
        }
    }

    public function test_every_page_of_a_list_is_imported(): void
    {
        $this->mock(StripeSync::class, function (MockInterface $sync) {
            $sync->shouldReceive('subscription')->twice();
        });

        $this->queueList('/v1/subscriptions', [['id' => 'sub_1', 'object' => 'subscription']], hasMore: true);
        $this->queueList('/v1/subscriptions', [['id' => 'sub_2', 'object' => 'subscription']]);

        foreach (['/v1/invoices', '/v1/payment_intents', '/v1/refunds', '/v1/checkout/sessions'] as $url) {
            $this->queueList($url, []);
        }

        $this->artisan('stripe:sync')->assertSuccessful();

        $this->assertSame('sub_1', $this->stripeRequests[1]['params']['starting_after']);
    }

    public function test_a_failure_is_reported_and_the_import_continues(): void
    {
        Exceptions::fake();

        $this->mock(StripeSync::class, function (MockInterface $sync) {
            $sync->shouldReceive('subscription')->once()->andThrow(new RuntimeException('Stripe was unreachable'));
            $sync->shouldReceive('invoice')->once()->andReturn(new Subscription);
            $sync->shouldReceive('paymentIntent')->twice()->andReturn(new Donation);
            $sync->shouldReceive('charge')->once()->andReturn(new Donation);
            $sync->shouldReceive('checkoutSession')->once()->andReturn(new Checkout);
        });

        $this->queueOneOfEach();

        // The objects after the failure still sync
        $this->artisan('stripe:sync')
            ->expectsOutputToContain('Subscription sub_1')
            ->expectsOutputToContain('Checkout session cs_1')
            ->expectsTable(['Stripe objects', 'Synced', 'Skipped', 'Failed'], [
                ['subscriptions', 0, 0, 1],
                ['invoices', 1, 0, 0],
                ['payments', 1, 0, 0],
                ['refunds', 2, 0, 0],
                ['checkout sessions', 1, 0, 0],
            ])
            ->expectsOutputToContain('Failed to sync sub_1 (subscriptions): Stripe was unreachable')
            ->expectsOutputToContain('1 failed')
            ->assertFailed();

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_nothing_is_synced_without_stripe_configured(): void
    {
        config(['services.stripe.secret' => null]);

        $this->artisan('stripe:sync')
            ->expectsOutputToContain('nothing to sync')
            ->assertSuccessful();

        $this->assertEmpty($this->stripeRequests);
    }

    public function test_days_must_be_a_positive_whole_number(): void
    {
        foreach (['0', '-1', 'week', '1.5'] as $days) {
            $this->artisan('stripe:sync', ['--days' => $days])->assertFailed();
        }

        $this->assertEmpty($this->stripeRequests);
    }

    public function test_live_runs_say_so(): void
    {
        config(['app.url' => 'https://wildland.eco', 'services.stripe.secret' => 'sk_live_example']);
        $this->mock(StripeSync::class);

        foreach (['/v1/subscriptions', '/v1/invoices', '/v1/payment_intents', '/v1/refunds', '/v1/checkout/sessions'] as $url) {
            $this->queueList($url, []);
        }

        $this->artisan('stripe:sync')
            ->expectsOutputToContain('Live Stripe sync started')
            ->assertSuccessful();
    }

    public function test_the_last_three_days_are_synced_every_night_once_stripe_is_configured(): void
    {
        // Loads routes/console.php, where the schedule is defined
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'stripe:sync --days=3'));

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertSame('America/Denver', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(storage_path('logs/stripe-sync.log'), $event->output);
        $this->assertTrue($event->shouldAppendOutput);
        $this->assertTrue($event->filtersPass($this->app));

        config(['services.stripe.secret' => null]);

        $this->assertFalse($event->filtersPass($this->app));
    }
}
