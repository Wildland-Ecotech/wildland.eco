<?php

namespace App\Reports;

use App\Models\Donation;
use Carbon\CarbonImmutable;

/**
 * Totals of the donations we received, month over month, by quarter, by
 * year, and for all time, with Stripe's fees and refunds, for
 * donations:totals and the admin pages. Periods are in Fort Collins time,
 * and periods without donations are left out.
 */
class DonationTotalsReport
{
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
     * The totals for each grouping, keyed by GROUPINGS, oldest period first,
     * with only the periods that had donations. Each total has a label, the
     * currency, the number of donations, the amount, refunded, fee, and net
     * totals in cents, and how many donations have no fee recorded yet.
     * Currencies are totaled separately.
     *
     * The net is what we kept: the amount less refunds and Stripe's fees.
     * Stripe keeps its fee when a payment is refunded.
     *
     * The database adds up each month in Fort Collins time; quarters, years,
     * and all time are added up from the months.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function totals(): array
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

        return array_map(fn (array $periods) => array_values(array_map(
            fn (array $total) => [...$total, 'net' => $total['amount'] - $total['refunded'] - $total['fees']],
            $periods,
        )), $totals);
    }

    /**
     * How many received donations have no Stripe fee recorded yet, from
     * totals(), so the fees leave them out and the net is that much too high.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $totals
     */
    public function missingFees(array $totals): int
    {
        return array_sum(array_column($totals['all'], 'missing_fees'));
    }
}
