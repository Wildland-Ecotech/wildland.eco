<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_checkout_is_started_in_dollars_with_a_uuid_reference(): void
    {
        $user = User::factory()->create();

        $checkout = $user->checkouts()->create([
            'amount_cents' => 5000,
            'frequency' => Checkout::FREQUENCY_ONE_TIME,
            'gateway' => 'stripe',
        ]);

        $checkout->refresh();

        $this->assertTrue(Str::isUuid($checkout->reference));
        $this->assertIsInt($checkout->id);
        $this->assertSame(Checkout::STATUS_STARTED, $checkout->status);
        $this->assertSame('usd', $checkout->currency);
        $this->assertSame(5000, $checkout->amount_cents);
        $this->assertNull($checkout->gateway_session_id);
        $this->assertTrue($checkout->user->is($user));
    }

    public function test_every_checkout_gets_its_own_reference(): void
    {
        $first = Checkout::factory()->create();
        $second = Checkout::factory()->create();

        $this->assertNotSame($first->reference, $second->reference);
    }

    public function test_the_factory_states_set_frequency_and_status(): void
    {
        $this->assertSame(Checkout::FREQUENCY_ONE_TIME, Checkout::factory()->oneTime()->create()->frequency);
        $this->assertSame(Checkout::FREQUENCY_ANNUAL, Checkout::factory()->annual()->create()->frequency);

        $completed = Checkout::factory()->completed()->create();
        $this->assertSame(Checkout::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->completed_at);

        $this->assertSame(Checkout::STATUS_EXPIRED, Checkout::factory()->expired()->create()->status);
    }

    public function test_finding_by_gateway_session_includes_soft_deleted_checkouts(): void
    {
        $checkout = Checkout::factory()->create(['gateway_session_id' => 'cs_test_deleted']);
        $checkout->delete();

        $this->assertSoftDeleted($checkout);
        $this->assertTrue(Checkout::findByGatewayId('stripe', 'cs_test_deleted')->is($checkout));
    }

    public function test_several_checkouts_can_wait_for_a_gateway_session(): void
    {
        Checkout::factory()->count(2)->create(['gateway_session_id' => null]);

        $this->assertSame(2, Checkout::whereNull('gateway_session_id')->count());
    }

    public function test_a_gateway_session_belongs_to_only_one_checkout(): void
    {
        Checkout::factory()->create(['gateway_session_id' => 'cs_test_duplicate']);

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(UniqueConstraintViolationException::class);

        Checkout::factory()->create(['gateway_session_id' => 'cs_test_duplicate']);
    }
}
