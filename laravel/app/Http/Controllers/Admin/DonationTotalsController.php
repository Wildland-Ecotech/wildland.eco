<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\DonationTotalsReport;
use App\Reports\Traits\DescribesDonationTotals;
use App\Reports\Traits\FormatsCsv;
use Illuminate\Http\Response;

/**
 * The donation totals as a CSV download (/admin/donations/totals/csv), from
 * the dashboard's Donation Statistics: the same columns as
 * `donations:totals --csv`, from DonationTotalsReport and
 * DescribesDonationTotals.
 */
class DonationTotalsController extends Controller
{
    use DescribesDonationTotals, FormatsCsv;

    /**
     * The totals as a CSV download, like `donations:totals --csv`.
     */
    public function download(DonationTotalsReport $report): Response
    {
        $filename = 'donation-totals-' . now(config('organization.timezone'))->format('Y-m-d') . '.csv';

        return response($this->csv($this->donationTotalsCsvHeader(), $this->donationTotalsCsvRows($report->totals())), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
