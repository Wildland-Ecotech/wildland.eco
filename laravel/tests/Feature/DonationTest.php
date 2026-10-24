<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Designation;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_one_time_donation_belongs_to_its_donor_and_checkout(): void
    {
        $checkout = Checkout::factory()->oneTime()->completed()->create();

        $donation = Donation::factory()->for($checkout->user)->for($checkout)->create();

        $this->assertTrue($donation->user->is($checkout->user));
        $this->assertTrue($donation->checkout->is($checkout));
        $this->assertNull($donation->subscription);
        $this->assertTrue($checkout->user->donations->contains($donation));
        $this->assertTrue($donation->designation->is(Designation::generalFund()));
    }

    public function test_a_new_donation_is_in_dollars_with_nothing_refunded(): void
    {
        $donation = User::factory()->create()->donations()->create([
            'gateway' => 'stripe',
            'gateway_payment_id' => 'pi_new',
            'amount_cents' => 2500,
            'status' => Donation::STATUS_PENDING,
        ]);

        $donation->refresh();

        $this->assertSame('usd', $donation->currency);
        $this->assertSame(0, $donation->refunded_cents);
        $this->assertNull($donation->fee_cents);
    }

    public function test_received_payments_include_refunds_but_not_failed_or_pending_ones(): void
    {
        $succeeded = Donation::factory()->create();
        $refunded = Donation::factory()->refunded()->create();
        $partial = Donation::factory()->partiallyRefunded()->create();
        Donation::factory()->failed()->create();
        Donation::factory()->pending()->create();

        $this->assertEqualsCanonicalizing(
            [$succeeded->id, $refunded->id, $partial->id],
            Donation::received()->pluck('id')->all(),
        );
        $this->assertTrue($refunded->wasReceived());
        $this->assertSame(5000, $refunded->refunded_cents);
        $this->assertSame(2500, $partial->refunded_cents);
        $this->assertFalse(Donation::factory()->failed()->make()->wasReceived());
    }

    public function test_finding_by_gateway_payment_includes_soft_deleted_donations(): void
    {
        $donation = Donation::factory()->create(['gateway_payment_id' => 'pi_deleted']);
        $donation->delete();

        $this->assertSoftDeleted($donation);
        $this->assertTrue(Donation::findByGatewayId('stripe', 'pi_deleted')->is($donation));
    }

    public function test_a_single_gift_receipt_belongs_to_its_donation(): void
    {
        $donation = Donation::factory()->create();

        $receipt = DonationReceipt::create([
            'user_id' => $donation->user_id,
            'donation_id' => $donation->id,
            'receipt_number' => 'TEST-1',
            'type' => DonationReceipt::TYPE_SINGLE,
            'tax_year' => 2026,
            'amount_cents' => $donation->amount_cents,
        ]);

        $this->assertTrue($donation->receipt->is($receipt));
        $this->assertTrue($receipt->donation->is($donation));
        $this->assertTrue($donation->user->donationReceipts->contains($receipt));
        $this->assertNull($receipt->sent_at);
    }

    public function test_a_deleted_receipt_keeps_its_number_reserved(): void
    {
        $user = User::factory()->create();
        $receipt = fn () => $user->donationReceipts()->create([
            'receipt_number' => 'TEST-2026',
            'type' => DonationReceipt::TYPE_ANNUAL_SUMMARY,
            'tax_year' => 2026,
            'amount_cents' => 120000,
        ]);

        $receipt()->delete();

        $this->assertSame(1, DonationReceipt::onlyTrashed()->count());

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(UniqueConstraintViolationException::class);

        $receipt();
    }

    public function test_a_payment_is_recorded_only_once(): void
    {
        Donation::factory()->create(['gateway_payment_id' => 'pi_duplicate']);

        // Any query after this fails, since PostgreSQL aborts the test's transaction
        $this->expectException(UniqueConstraintViolationException::class);

        Donation::factory()->failed()->create(['gateway_payment_id' => 'pi_duplicate']);
    }
}
