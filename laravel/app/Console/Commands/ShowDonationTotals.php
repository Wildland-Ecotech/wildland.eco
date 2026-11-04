<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Reports\DonationTotalsReport;
use App\Reports\Traits\FormatsMoney;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Totals of the donations we received, month over month, by quarter, by year,
 * and for all time, with Stripe's fees and refunds, for keeping tabs on
 * giving and for board meetings until the administrator pages are built
 * (milestone 10). Periods are in Fort Collins time, and periods without
 * donations are left out.
 */
class ShowDonationTotals extends Command
{
    use FormatsMoney, ListsRecords, WritesCsv;

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
    public const HEADERS = ['Period', 'Donations', 'Amount', 'Refunded', 'Stripe fees', 'Net'];

    public const AMOUNT_COLUMNS = [1, 2, 3, 4, 5];

    public function handle(DonationTotalsReport $report): int
    {
        $totals = $report->totals();

        if ($this->option('csv')) {
            $this->writeCsv(
                ['Grouping', 'Period', ...array_slice(self::HEADERS, 1), 'Currency'],
                collect($totals)->flatMap(fn (array $periods, string $grouping) => array_map(fn (array $total) => [
                    DonationTotalsReport::GROUPINGS[$grouping],
                    $total['label'],
                    $total['donations'],
                    $this->decimal($total['amount']),
                    $this->decimal($total['refunded']),
                    $this->decimal($total['fees']),
                    $this->decimal($report->net($total)),
                    strtoupper($total['currency']),
                ], $periods)),
            );

            return self::SUCCESS;
        }

        if ($totals['all'] === []) {
            $this->components->info('No donations received yet.');

            return self::SUCCESS;
        }

        foreach (DonationTotalsReport::GROUPINGS as $grouping => $title) {
            $this->newLine();
            $this->line("<options=bold>{$title}</>");

            $this->writeTable(self::HEADERS, array_map(fn (array $total) => [
                $total['label'],
                $total['donations'],
                $this->money($total['amount'], $total['currency']),
                $this->money($total['refunded'], $total['currency']),
                $this->money($total['fees'], $total['currency']),
                $this->money($report->net($total), $total['currency']),
            ], $totals[$grouping]), self::AMOUNT_COLUMNS);
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
