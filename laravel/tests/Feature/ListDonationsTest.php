<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan donations:list`: donations, one payment at a time.
 */
class ListDonationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The table's columns.
     */
    private const HEADERS = ['ID', 'Date', 'Donor', 'Type', 'Amount', 'Status'];

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));

        $this->donor = User::factory()->create(['name' => 'Jamie Rivera', 'email' => 'jamie@example.com']);
    }

    /**
     * A received payment from the donor, with any fields overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function donation(string $paymentId, array $attributes = []): Donation
    {
        return Donation::factory()->for($this->donor)->create(['gateway_payment_id' => $paymentId, ...$attributes]);
    }

    /**
     * A received payment of a new monthly or annual donation from the donor.
     */
    private function recurringDonation(string $paymentId, string $interval): Donation
    {
        $subscription = Subscription::factory()->for($this->donor)->create(['interval' => $interval]);

        return $this->donation($paymentId, ['subscription_id' => $subscription->id, 'amount_cents' => 2000, 'fee_cents' => 88]);
    }

    /**
     * Run the command with --csv and parse its output into rows.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<int, string>>
     */
    private function csvRows(array $options = []): array
    {
        $this->assertSame(0, Artisan::call('donations:list', ['--csv' => true, ...$options]));

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, Artisan::output());
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * The Stripe payment IDs listed in the CSV, in order.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, string>
     */
    private function paymentIds(array $options = []): array
    {
        return array_column(array_slice($this->csvRows($options), 1), 1);
    }

    public function test_lists_received_donations_in_a_table(): void
    {
        $oneTime = $this->donation('pi_onetime', ['amount_cents' => 125000]);
        $monthly = $this->recurringDonation('pi_monthly', Subscription::INTERVAL_MONTH);
        $annual = $this->recurringDonation('pi_annual', Subscription::INTERVAL_YEAR);
        Donation::factory()->for($this->donor)->failed()->create(['gateway_payment_id' => 'pi_declined']);
        Donation::factory()->for($this->donor)->pending()->create(['gateway_payment_id' => 'pi_settling']);

        $this->artisan('donations:list')
            ->expectsTable(self::HEADERS, [
                [$oneTime->id, 'Oct 21, 2026 7:30 PM MDT', 'Jamie Rivera', 'One-time', '$1,250.00', 'Paid'],
                [$monthly->id, 'Oct 21, 2026 7:30 PM MDT', 'Jamie Rivera', 'Monthly', '$20.00', 'Paid'],
                [$annual->id, 'Oct 21, 2026 7:30 PM MDT', 'Jamie Rivera', 'Annual', '$20.00', 'Paid'],
            ])
            // The failed and pending payments aren't counted
            ->expectsOutputToContain('3 donations received in the last 30 days.')
            ->assertSuccessful();
    }

    public function test_the_donor_is_their_email_address_when_we_have_no_name(): void
    {
        $this->donor->update(['name' => null]);
        $donation = $this->donation('pi_onetime');

        $this->artisan('donations:list')
            ->expectsTable(self::HEADERS, [
                [$donation->id, 'Oct 21, 2026 7:30 PM MDT', 'jamie@example.com', 'One-time', '$50.00', 'Paid'],
            ])
            ->assertSuccessful();
    }

    public function test_donor_names_cannot_add_console_formatting(): void
    {
        $this->donor->update(['name' => '<fg=red>Jamie</>']);
        $this->donation('pi_onetime');

        $this->artisan('donations:list')
            ->expectsOutputToContain('<fg=red>Jamie</>')
            ->assertSuccessful();
    }

    public function test_all_includes_pending_and_failed_payments(): void
    {
        $paid = $this->donation('pi_onetime');
        $failed = Donation::factory()->for($this->donor)->failed()->create(['gateway_payment_id' => 'pi_declined']);
        $pending = Donation::factory()->for($this->donor)->pending()->create(['gateway_payment_id' => 'pi_settling']);

        $this->artisan('donations:list', ['--all' => true])
            ->expectsTable(self::HEADERS, [
                [$paid->id, 'Oct 21, 2026 7:30 PM MDT', 'Jamie Rivera', 'One-time', '$50.00', 'Paid'],
                [$failed->id, 'Oct 21, 2026 7:30 PM MDT', 'Jamie Rivera', 'One-time', '$50.00', 'Failed'],
                [$pending->id, 'Oct 21, 2026 7:30 PM MDT', 'Jamie Rivera', 'One-time', '$50.00', 'Pending'],
            ])
            ->expectsOutputToContain('3 payments in the last 30 days.')
            ->assertSuccessful();

        // Fee, refunded, and net are only filled in for payments we received
        $rows = array_slice($this->csvRows(['--all' => true]), 1);

        $this->assertSame(['1.75', '0.00', '48.25'], array_slice($rows[0], 8, 3));
        $this->assertSame(['', '', ''], array_slice($rows[1], 8, 3));
    }

    public function test_type_options_narrow_the_list_and_can_be_combined(): void
    {
        $this->donation('pi_onetime');
        $this->recurringDonation('pi_monthly', Subscription::INTERVAL_MONTH);
        $this->recurringDonation('pi_annual', Subscription::INTERVAL_YEAR);

        $this->assertSame(['pi_monthly'], $this->paymentIds(['--monthly' => true]));
        $this->assertSame(['pi_onetime', 'pi_annual'], $this->paymentIds(['--one-time' => true, '--annual' => true]));
        $this->assertSame(['pi_onetime', 'pi_monthly', 'pi_annual'], $this->paymentIds(['--one-time' => true, '--monthly' => true, '--annual' => true]));

        $this->artisan('donations:list', ['--monthly' => true])
            ->expectsOutputToContain('1 monthly donation received in the last 30 days.')
            ->assertSuccessful();

        $this->artisan('donations:list', ['--one-time' => true, '--annual' => true])
            ->expectsOutputToContain('2 one-time and annual donations received in the last 30 days.')
            ->assertSuccessful();
    }

    public function test_days_counts_from_when_the_donor_paid_or_tried_to_pay(): void
    {
        $this->donation('pi_recent', ['paid_at' => now()->subDays(5)]);
        $this->donation('pi_older', ['paid_at' => now()->subDays(40)]);

        // Imported today, but the donor tried to pay 40 days ago
        Donation::factory()->for($this->donor)->failed()->create([
            'gateway_payment_id' => 'pi_older_attempt',
            'gateway_data' => ['id' => 'pi_older_attempt', 'created' => now()->subDays(40)->getTimestamp()],
        ]);

        $this->assertSame(['pi_recent'], $this->paymentIds(['--all' => true]));

        $rows = array_slice($this->csvRows(['--all' => true, '--days' => 60]), 1);

        $this->assertSame(['pi_older', 'pi_older_attempt', 'pi_recent'], array_column($rows, 1));
        $this->assertSame('Fri, Sep 11, 2026 7:30 PM MDT', $rows[1][2]);
    }

    public function test_refunds_are_subtracted_from_the_net_amount(): void
    {
        $this->donation('pi_refunded', ['amount_cents' => 5000, 'fee_cents' => 175, 'status' => Donation::STATUS_REFUNDED, 'refunded_cents' => 5000]);
        $this->donation('pi_partial', ['amount_cents' => 5000, 'fee_cents' => 175, 'status' => Donation::STATUS_PARTIALLY_REFUNDED, 'refunded_cents' => 2500]);

        $rows = array_slice($this->csvRows(), 1);

        // Status, amount, fee, refunded, net. Stripe keeps its fee after a
        // refund, and a negative net stays a number spreadsheets can add up.
        $this->assertSame(['Refunded', '50.00', '1.75', '50.00', '-1.75'], [$rows[0][4], ...array_slice($rows[0], 7, 4)]);
        $this->assertSame(['Partially refunded', '50.00', '1.75', '25.00', '23.25'], [$rows[1][4], ...array_slice($rows[1], 7, 4)]);
    }

    public function test_a_fee_not_recorded_yet_is_left_empty_in_the_csv(): void
    {
        $this->donation('pi_no_fee', ['fee_cents' => null]);

        // Fee, refunded, net: an unknown fee and net never add up as zero
        $this->assertSame(['', '0.00', ''], array_slice($this->csvRows()[1], 8, 3));
    }

    public function test_deleted_donations_are_kept_but_not_listed(): void
    {
        $this->donation('pi_kept');
        $deleted = $this->donation('pi_deleted');

        $deleted->delete();

        $this->assertSoftDeleted($deleted);
        $this->assertSame(['pi_kept'], $this->paymentIds());
    }

    public function test_csv_has_every_detail_and_is_safe_to_open_in_a_spreadsheet(): void
    {
        $this->donor->update(['name' => '=HYPERLINK("https://example.com")']);
        $donation = $this->donation('pi_onetime', ['amount_cents' => 125000, 'fee_cents' => 3655]);

        $rows = $this->csvRows();

        $this->assertSame(
            ['ID', 'Payment ID', 'Date', 'Type', 'Status', 'Name', 'Email', 'Amount', 'Stripe fee', 'Refunded', 'Net', 'Designation', 'Currency'],
            $rows[0],
        );
        $this->assertSame(
            [(string) $donation->id, 'pi_onetime', 'Wed, Oct 21, 2026 7:30 PM MDT', 'One-time', 'Paid', "'=HYPERLINK(\"https://example.com\")", 'jamie@example.com', '1250.00', '36.55', '0.00', '1213.45', 'General Fund', 'USD'],
            $rows[1],
        );
    }

    public function test_says_when_there_are_no_donations(): void
    {
        Donation::factory()->for($this->donor)->failed()->create();

        $this->artisan('donations:list')
            ->expectsOutputToContain('No donations received in the last 30 days.')
            ->assertSuccessful();

        $this->artisan('donations:list', ['--monthly' => true])
            ->expectsOutputToContain('No monthly donations received in the last 30 days.')
            ->assertSuccessful();

        $this->artisan('donations:list', ['--monthly' => true, '--all' => true])
            ->expectsOutputToContain('No monthly payments in the last 30 days.')
            ->assertSuccessful();
    }

    public function test_rejects_an_invalid_days_option(): void
    {
        $this->artisan('donations:list', ['--days' => 'month'])
            ->assertExitCode(2);
    }
}
