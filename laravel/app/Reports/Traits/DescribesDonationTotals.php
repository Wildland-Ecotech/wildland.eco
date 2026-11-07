<?php

namespace App\Reports\Traits;

use App\Reports\DonationTotalsReport;

/**
 * How reports describe donation totals from DonationTotalsReport, so
 * donations:totals, its CSV, and the admin dashboard's Donation Statistics
 * show the same rows.
 */
trait DescribesDonationTotals
{
    use FormatsMoney;

    /**
     * The columns of a table of totals. Every column but the period is a
     * number.
     */
    protected const DONATION_TOTALS_HEADERS = ['Period', 'Donations', 'Amount', 'Refunded', 'Stripe fees', 'Net'];

    /**
     * A total's row in a table, matching DONATION_TOTALS_HEADERS.
     *
     * @param  array<string, mixed>  $total
     * @return array<int, mixed>
     */
    protected function donationTotalRow(array $total): array
    {
        return [
            $total['label'],
            $total['donations'],
            $this->money($total['amount'], $total['currency']),
            $this->money($total['refunded'], $total['currency']),
            $this->money($total['fees'], $total['currency']),
            $this->money($total['net'], $total['currency']),
        ];
    }

    /**
     * The header row of a CSV of totals, with a Grouping column, such as
     * "By quarter", and a Currency column.
     *
     * @return array<int, string>
     */
    protected function donationTotalsCsvHeader(): array
    {
        return ['Grouping', 'Period', ...array_slice(self::DONATION_TOTALS_HEADERS, 1), 'Currency'];
    }

    /**
     * Every total as a CSV row, matching donationTotalsCsvHeader(), grouping
     * by grouping. Amounts are plain numbers.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $totals
     * @return array<int, array<int, mixed>>
     */
    protected function donationTotalsCsvRows(array $totals): array
    {
        $rows = [];

        foreach ($totals as $grouping => $periods) {
            foreach ($periods as $total) {
                $rows[] = [
                    DonationTotalsReport::GROUPINGS[$grouping],
                    $total['label'],
                    $total['donations'],
                    $this->decimal($total['amount']),
                    $this->decimal($total['refunded']),
                    $this->decimal($total['fees']),
                    $this->decimal($total['net']),
                    strtoupper($total['currency']),
                ];
            }
        }

        return $rows;
    }
}
