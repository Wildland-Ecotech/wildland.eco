<?php

namespace App\Console\Commands;

use App\Console\Traits\FormatsMoney;
use App\Console\Traits\ListsRecords;
use App\Console\Traits\WritesCsv;
use App\Models\Donation;
use Carbon\CarbonImmutable;
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
     * Each grouping's title, in the order they are shown.
     */
    public const GROUPINGS = [
        'month' => 'Month over month',
        'quarter' => 'By quarter',
        'year' => 'By year',
        'all' => 'All time',
    ];

    /**
     * The table's columns. The amount columns line up on the right.
     */
    public const HEADERS = ['Period', 'Donations', 'Amount', 'Refunded', 'Stripe fees', 'Net'];

    public const AMOUNT_COLUMNS = [1, 2, 3, 4, 5];

    public function handle(): int
    {
        $totals = $this->totals();

        if ($this->option('csv')) {
            $this->writeCsv(
                ['Grouping', 'Period', ...array_slice(self::HEADERS, 1), 'Currency'],
                collect($totals)->flatMap(fn (array $periods, string $grouping) => array_map(fn (array $total) => [
                    self::GROUPINGS[$grouping],
                    $total['label'],
                    $total['donations'],
                    $this->decimal($total['amount']),
                    $this->decimal($total['refunded']),
                    $this->decimal($total['fees']),
                    $this->decimal($this->net($total)),
                    strtoupper($total['currency']),
                ], $periods)),
            );

            return self::SUCCESS;
        }

        if ($totals['all'] === []) {
            $this->components->info('No donations received yet.');

            return self::SUCCESS;
        }

        foreach (self::GROUPINGS as $grouping => $title) {
            $this->newLine();
            $this->line("<options=bold>{$title}</>");

            $this->writeTable(self::HEADERS, array_map(fn (array $total) => [
                $total['label'],
                $total['donations'],
                $this->money($total['amount'], $total['currency']),
                $this->money($total['refunded'], $total['currency']),
                $this->money($total['fees'], $total['currency']),
                $this->money($this->net($total), $total['currency']),
            ], $totals[$grouping]), self::AMOUNT_COLUMNS);
        }

        $this->newLine();
        $this->components->info('Received donations, including ones refunded later, by the date paid in Fort Collins time.');

        $missingFees = array_sum(array_column($totals['all'], 'missing_fees'));

        if ($missingFees > 0) {
            $this->components->warn("Stripe's fee isn't recorded yet for {$missingFees} " . Str::plural('donation', $missingFees) . ', so the fees leave them out and the net is that much too high. Running stripe:sync again records them once Stripe has them.');
        }

        return self::SUCCESS;
    }

    /**
     * The totals for each grouping, oldest period first, with only the
     * periods that had donations. Each total has a label, the currency, the
     * number of donations, the amount, refunded, and fee totals in cents,
     * and how many donations have no fee recorded yet.
     *
     * The database adds up each month in Fort Collins time; quarters, years,
     * and all time are added up from the months.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function totals(): array
    {
        // paid_at is stored in UTC without a time zone
        $local = '(paid_at AT TIME ZONE \'UTC\') AT TIME ZONE ?';
        $timezone = config('organization.timezone');

        $months = Donation::query()
            ->received()
            ->whereNotNull('paid_at')
            ->selectRaw(
                "CAST(EXTRACT(YEAR FROM {$local}) AS integer) AS paid_year,
                CAST(EXTRACT(MONTH FROM {$local}) AS integer) AS paid_month,
                currency,
                COUNT(*) AS donations,
                SUM(amount_cents) AS amount,
                SUM(refunded_cents) AS refunded,
                COALESCE(SUM(fee_cents), 0) AS fees,
                COUNT(*) - COUNT(fee_cents) AS missing_fees",
                [$timezone, $timezone],
            )
            ->groupByRaw('paid_year, paid_month, currency')
            ->orderByRaw('paid_year, paid_month, currency')
            ->toBase()
            ->get();

        $totals = array_fill_keys(array_keys(self::GROUPINGS), []);

        foreach ($months as $month) {
            $start = CarbonImmutable::create((int) $month->paid_year, (int) $month->paid_month, 1);
            $quarter = intdiv($start->month - 1, 3) + 1;

            foreach ([
                'month' => [$start->format('Y-m'), $start->format('M Y')],
                'quarter' => ["{$start->year}-{$quarter}", "Q{$quarter} {$start->year}"],
                'year' => [(string) $start->year, (string) $start->year],
                'all' => ['all', 'All time'],
            ] as $grouping => [$key, $label]) {
                $key .= "-{$month->currency}";

                $totals[$grouping][$key] ??= [
                    'label' => $label,
                    'currency' => $month->currency,
                    'donations' => 0,
                    'amount' => 0,
                    'refunded' => 0,
                    'fees' => 0,
                    'missing_fees' => 0,
                ];

                foreach (['donations', 'amount', 'refunded', 'fees', 'missing_fees'] as $sum) {
                    $totals[$grouping][$key][$sum] += (int) $month->{$sum};
                }
            }
        }

        return array_map(array_values(...), $totals);
    }

    /**
     * What we kept: the amount less refunds and Stripe's fees. Stripe keeps
     * its fee when a payment is refunded.
     *
     * @param  array<string, mixed>  $total
     */
    private function net(array $total): int
    {
        return $total['amount'] - $total['refunded'] - $total['fees'];
    }
}
