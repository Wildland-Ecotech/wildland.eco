<?php

namespace Tests\Feature;

use App\Console\Commands\ShowDonationTotals;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `php artisan donations:totals`: what we received, by month, quarter, and year.
 */
class DonationTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 19:30', 'America/Denver'));

        $this->donor = User::factory()->create();
    }

    /**
     * A received donation paid at the given Fort Collins time, with any
     * fields overridden. Times are stored in UTC.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function paid(string $localTime, int $amountCents, int $feeCents, array $attributes = []): Donation
    {
        return Donation::factory()->for($this->donor)->create([
            'paid_at' => Carbon::parse($localTime, 'America/Denver')->utc(),
            'amount_cents' => $amountCents,
            'fee_cents' => $feeCents,
            ...$attributes,
        ]);
    }

    /**
     * Donations in December 2025, January, February, and October 2026, and
     * payments that don't count.
     */
    private function history(): void
    {
        // December 31st in Fort Collins, already January 1st in UTC
        $this->paid('2025-12-31 23:30', 2500, 103);
        $this->paid('2026-01-15 10:00', 5000, 175);
        $this->paid('2026-02-10 10:00', 2000, 88);
        $this->paid('2026-02-28 10:00', 5000, 175, ['status' => Donation::STATUS_REFUNDED, 'refunded_cents' => 5000]);
        $this->paid('2026-10-01 09:00', 10000, 320);

        // Not received, or deleted
        Donation::factory()->for($this->donor)->failed()->create();
        Donation::factory()->for($this->donor)->pending()->create();
        $this->paid('2026-03-05 10:00', 9900, 300)->delete();
    }

    /**
     * Run the command with --csv and parse its output into rows.
     *
     * @return array<int, array<int, string>>
     */
    private function csvRows(): array
    {
        $this->assertSame(0, Artisan::call('donations:totals', ['--csv' => true]));

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

    public function test_totals_by_month_quarter_year_and_all_time_without_empty_periods(): void
    {
        $this->history();

        $this->assertSame([
            ['Grouping', 'Period', 'Donations', 'Amount', 'Refunded', 'Stripe fees', 'Net', 'Currency'],
            ['Month over month', 'Dec 2025', '1', '25.00', '0.00', '1.03', '23.97', 'USD'],
            ['Month over month', 'Jan 2026', '1', '50.00', '0.00', '1.75', '48.25', 'USD'],
            ['Month over month', 'Feb 2026', '2', '70.00', '50.00', '2.63', '17.37', 'USD'],
            ['Month over month', 'Oct 2026', '1', '100.00', '0.00', '3.20', '96.80', 'USD'],
            ['By quarter', 'Q4 2025', '1', '25.00', '0.00', '1.03', '23.97', 'USD'],
            ['By quarter', 'Q1 2026', '3', '120.00', '50.00', '4.38', '65.62', 'USD'],
            ['By quarter', 'Q4 2026', '1', '100.00', '0.00', '3.20', '96.80', 'USD'],
            ['By year', '2025', '1', '25.00', '0.00', '1.03', '23.97', 'USD'],
            ['By year', '2026', '4', '220.00', '50.00', '7.58', '162.42', 'USD'],
            ['All time', 'All time', '5', '245.00', '50.00', '8.61', '186.39', 'USD'],
        ], $this->csvRows());
    }

    public function test_shows_each_grouping_as_a_table(): void
    {
        $this->history();

        $this->artisan('donations:totals')
            ->expectsOutputToContain('Month over month')
            ->expectsOutputToContain('By quarter')
            ->expectsOutputToContain('By year')
            ->expectsTable(ShowDonationTotals::HEADERS, [
                ['2025', '1', '$25.00', '$0.00', '$1.03', '$23.97'],
                ['2026', '4', '$220.00', '$50.00', '$7.58', '$162.42'],
            ], 'default', ShowDonationTotals::rightAlignedColumns(ShowDonationTotals::AMOUNT_COLUMNS))
            ->doesntExpectOutputToContain('Mar 2026')
            ->doesntExpectOutputToContain('Q2 2026')
            ->assertSuccessful();
    }

    public function test_a_fee_not_recorded_yet_is_pointed_out(): void
    {
        $this->paid('2026-10-01 09:00', 10000, 320);
        $this->paid('2026-10-02 09:00', 5000, 0, ['fee_cents' => null]);

        $this->artisan('donations:totals')
            ->expectsOutputToContain('recorded yet for 1 donation')
            ->assertSuccessful();

        // The known fee is counted; the missing one isn't counted as anything
        $this->assertSame(['2', '150.00', '0.00', '3.20', '146.80'], array_slice($this->csvRows()[1], 2, 5));
    }

    public function test_says_when_there_are_no_donations(): void
    {
        Donation::factory()->for($this->donor)->failed()->create();

        $this->artisan('donations:totals')
            ->expectsOutputToContain('No donations received yet.')
            ->assertSuccessful();
    }
}
