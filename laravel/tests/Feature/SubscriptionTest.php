<?php

namespace Tests\Feature;

use App\Models\Designation;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subscription_belongs_to_its_donor_and_defaults_to_the_general_fund(): void
    {
        $user = User::factory()->create();

        $subscription = Subscription::factory()->for($user)->create(['gateway_data' => ['id' => 'sub_example']]);

        $subscription->refresh();

        $this->assertTrue($subscription->user->is($user));
        $this->assertTrue($user->subscriptions->contains($subscription));
        $this->assertTrue($subscription->designation->is(Designation::generalFund()));
        $this->assertSame('usd', $subscription->currency);
        $this->assertSame(['id' => 'sub_example'], $subscription->gateway_data);
    }

    public function test_the_factory_states_set_interval_and_status(): void
    {
        $this->assertSame(Subscription::INTERVAL_YEAR, Subscription::factory()->annual()->create()->interval);
        $this->assertSame(Subscription::STATUS_INCOMPLETE, Subscription::factory()->incomplete()->create()->status);

        $canceled = Subscription::factory()->canceled()->create();
        $this->assertSame(Subscription::STATUS_CANCELED, $canceled->status);
        $this->assertNotNull($canceled->canceled_at);
    }

    public function test_a_subscription_has_the_payments_it_collected(): void
    {
        $subscription = Subscription::factory()->create();

        $paid = Donation::factory()->for($subscription->user)->for($subscription)->create(['gateway_invoice_id' => 'in_paid']);
        $failed = Donation::factory()->failed()->for($subscription->user)->for($subscription)->create();

        $this->assertEqualsCanonicalizing([$paid->id, $failed->id], $subscription->donations->pluck('id')->all());
        $this->assertTrue($paid->subscription->is($subscription));
    }

    public function test_finding_by_gateway_id_includes_soft_deleted_subscriptions(): void
    {
        $subscription = Subscription::factory()->create(['gateway_subscription_id' => 'sub_deleted']);
        $subscription->delete();

        $this->assertSoftDeleted($subscription);
        $this->assertTrue(Subscription::findByGatewayId('stripe', 'sub_deleted')->is($subscription));
    }

    public function test_recording_the_same_event_again_does_not_duplicate_the_history(): void
    {
        $subscription = Subscription::factory()->create();

        $record = fn (string $source) => SubscriptionEvent::firstOrCreate(
            ['subscription_id' => $subscription->id, 'type' => SubscriptionEvent::TYPE_RENEWED, 'source_reference' => 'in_renewal'],
            ['source' => $source, 'occurred_at' => now()],
        );

        $fromWebhook = $record(SubscriptionEvent::SOURCE_WEBHOOK);
        $fromImport = $record(SubscriptionEvent::SOURCE_IMPORT);

        $this->assertTrue($fromImport->is($fromWebhook));
        $this->assertSame(SubscriptionEvent::SOURCE_WEBHOOK, $fromImport->source);
        $this->assertCount(1, $subscription->events);
    }

    public function test_events_are_listed_oldest_first(): void
    {
        $subscription = Subscription::factory()->create();

        foreach (['in_second' => now()->subMonth(), 'in_first' => now()->subMonths(2)] as $reference => $occurredAt) {
            $subscription->events()->create([
                'type' => SubscriptionEvent::TYPE_RENEWED,
                'source' => SubscriptionEvent::SOURCE_IMPORT,
                'source_reference' => $reference,
                'occurred_at' => $occurredAt,
            ]);
        }

        $this->assertSame(['in_first', 'in_second'], $subscription->events->pluck('source_reference')->all());
    }

    public function test_recorded_history_cannot_be_changed_or_deleted(): void
    {
        $event = Subscription::factory()->create()->events()->create([
            'type' => SubscriptionEvent::TYPE_CREATED,
            'source' => SubscriptionEvent::SOURCE_WEBHOOK,
            'source_reference' => 'evt_created',
            'occurred_at' => now(),
        ]);

        $this->assertThrows(fn () => $event->update(['type' => SubscriptionEvent::TYPE_CANCELED]), LogicException::class);
        $this->assertThrows(fn () => $event->delete(), LogicException::class);

        $this->assertSame(SubscriptionEvent::TYPE_CREATED, $event->fresh()->type);
    }

    public function test_a_gateway_subscription_id_is_recorded_only_once(): void
    {
        Subscription::factory()->create(['gateway_subscription_id' => 'sub_duplicate']);

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(UniqueConstraintViolationException::class);

        Subscription::factory()->create(['gateway_subscription_id' => 'sub_duplicate']);
    }
}
