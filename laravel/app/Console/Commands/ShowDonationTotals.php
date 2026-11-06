<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Reports\DonationTotalsReport;
use App\Reports\Traits\DescribesDonationTotals;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Totals of the donations we received, month over month, by quarter, by year,
 * and for all time, with Stripe's fees and refunds, for keeping tabs on
 * giving and for board meetings, like the admin dashboard's Donation
 * Statistics (/admin). Periods are in Fort Collins time, and periods without
 * donations are left out.
 */
class ShowDonationTotals extends Command
{
    use DescribesDonationTotals, ListsRecords, WritesCsv;

    /**
     * @var string
     */
    protected $signature = 'donations:totals
        {--csv : Output CSV, for importing into a spreadsheet}';

    /**
     * @var string
     */
    protected $description = 'Show donation totals by month, quarter, and year';

    /**
     * The table's columns. The amount columns line up on the right.
     */
    public const HEADERS = self::DONATION_TOTALS_HEADERS;

    public const AMOUNT_COLUMNS = [1, 2, 3, 4, 5];

    public function handle(DonationTotalsReport $report): int
    {
        $totals = $report->totals();

        if ($this->option('csv')) {
            $this->writeCsv($this->donationTotalsCsvHeader(), $this->donationTotalsCsvRows($totals));

            return self::SUCCESS;
        }

        if ($totals['all'] === []) {
            $this->components->info('No donations received yet.');

            return self::SUCCESS;
        }

        foreach (DonationTotalsReport::GROUPINGS as $grouping => $title) {
            $this->newLine();
            $this->line("<options=bold>{$title}</>");

            $this->writeTable(self::HEADERS, array_map($this->donationTotalRow(...), $totals[$grouping]), self::AMOUNT_COLUMNS);
        }

        $this->newLine();
        $this->components->info('Received donations, including ones refunded later, by the date paid in Fort Collins time.');

        $missingFees = $report->missingFees($totals);

        if ($missingFees > 0) {
            $this->components->warn("Stripe's fee isn't recorded yet for {$missingFees} " . Str::plural('donation', $missingFees) . ', so the fees leave them out and the net is that much too high. Running stripe:sync again records them once Stripe has them.');
        }

        return self::SUCCESS;
    }
}
