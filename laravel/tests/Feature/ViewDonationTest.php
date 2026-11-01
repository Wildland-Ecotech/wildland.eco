<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `php artisan donations:view`: one donation in full.
 */
class ViewDonationTest extends TestCase
{
    use RefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));

        $this->donor = User::factory()->create(['name' => 'Jamie Rivera', 'email' => 'jamie@example.com']);
    }

    /**
     * A received payment of a monthly donation from the donor.
     */
    private function monthlyDonation(): Donation
    {
        $subscription = Subscription::factory()->for($this->donor)->create(['interval' => Subscription::INTERVAL_MONTH]);

        return Donation::factory()->for($this->donor)->create([
            'gateway_payment_id' => 'pi_monthly',
            'subscription_id' => $subscription->id,
            'amount_cents' => 2000,
            'fee_cents' => 88,
        ]);
    }

    public function test_shows_a_donation_in_full_by_its_id(): void
    {
        $donation = $this->monthlyDonation();

        $this->artisan('donations:view', ['donation' => (string) $donation->id])
            ->expectsOutputToContain("Donation {$donation->id}")
            ->expectsOutputToContain('Payment ID: pi_monthly')
            ->expectsOutputToContain('Date: Wed, Oct 21, 2026 7:30 PM MDT')
            ->expectsOutputToContain('Type: Monthly')
            ->expectsOutputToContain('Status: Paid')
            ->expectsOutputToContain('Name: Jamie Rivera')
            ->expectsOutputToContain('Email: jamie@example.com')
            ->expectsOutputToContain('Amount: $20.00')
            ->expectsOutputToContain('Stripe fee: $0.88')
            ->expectsOutputToContain('Refunded: $0.00')
            ->expectsOutputToContain('Net: $19.12')
            ->expectsOutputToContain('Designation: General Fund')
            ->assertSuccessful();
    }

    public function test_finds_a_donation_by_its_stripe_payment_id(): void
    {
        $donation = $this->monthlyDonation();

        $this->artisan('donations:view', ['donation' => 'pi_monthly'])
            ->expectsOutputToContain("Donation {$donation->id}")
            ->assertSuccessful();
    }

    public function test_a_failed_payment_has_no_fee_or_net_amount(): void
    {
        $donation = Donation::factory()->for($this->donor)->failed()->create();

        $this->artisan('donations:view', ['donation' => (string) $donation->id])
            ->expectsOutputToContain('Status: Failed')
            ->expectsOutputToContain('Stripe fee: —')
            ->expectsOutputToContain('Net: —')
            ->assertSuccessful();
    }

    public function test_a_fee_not_recorded_yet_is_pointed_out(): void
    {
        $donation = Donation::factory()->for($this->donor)->create(['fee_cents' => null]);

        $this->artisan('donations:view', ['donation' => (string) $donation->id])
            ->expectsOutputToContain('Stripe fee: Not recorded yet')
            ->expectsOutputToContain('Running stripe:sync again')
            ->assertSuccessful();
    }

    public function test_a_deleted_donation_is_shown_and_marked_as_deleted(): void
    {
        $donation = Donation::factory()->for($this->donor)->create();
        $donation->delete();

        $this->artisan('donations:view', ['donation' => (string) $donation->id])
            ->expectsOutputToContain("Donation {$donation->id}")
            ->expectsOutputToContain('This donation was deleted on Wed, Oct 21, 2026 7:30 PM MDT')
            ->assertSuccessful();
    }

    public function test_says_when_no_donation_has_the_id(): void
    {
        $this->artisan('donations:view', ['donation' => '999999'])
            ->expectsOutputToContain('No donation has the ID 999999.')
            ->assertFailed();

        $this->artisan('donations:view', ['donation' => 'pi_unknown'])
            ->expectsOutputToContain('No donation has the ID pi_unknown.')
            ->assertFailed();
    }

    public function test_rejects_anything_but_an_id_or_a_stripe_payment_id(): void
    {
        $this->artisan('donations:view', ['donation' => 'jamie@example.com'])->assertExitCode(2);
        $this->artisan('donations:view', ['donation' => 'pi_<b>bold</b>'])->assertExitCode(2);
    }
}
