<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\DonationTotalsReport;
use App\Reports\Traits\DescribesDonationTotals;
use App\Reports\Traits\FormatsCsv;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin totals page (/admin/donations/totals): the donations we received
 * month over month, by quarter, by year, and for all time, with a CSV
 * download. It shows the same totals as donations:totals, from
 * DonationTotalsReport and DescribesDonationTotals.
 */
class DonationTotalsController extends Controller
{
    use DescribesDonationTotals, FormatsCsv;

    /**
     * The columns that make up a total's line on a phone (period, donations,
     * and amount), and the columns lined up on the right (every number).
     */
    public const SUMMARY_COLUMNS = [0, 1, 2];

    public const NUMBER_COLUMNS = [1, 2, 3, 4, 5];

    /**
     * The totals, one list per grouping.
     */
    public function index(DonationTotalsReport $report): View
    {
        $totals = $report->totals();

        return view('pages.admin.donations.totals', [
            'headers' => self::DONATION_TOTALS_HEADERS,
            'groupings' => collect(DonationTotalsReport::GROUPINGS)->map(fn (string $title, string $grouping) => [
                'title' => $title,
                'rows' => array_map(fn (array $total) => [
                    'cells' => $this->donationTotalRow($total),
                    'summary' => [
                        $total['label'],
                        $total['donations'] . ' ' . Str::plural('donation', $total['donations']),
                        $this->money($total['amount'], $total['currency']),
                    ],
                ], $totals[$grouping]),
            ])->values()->all(),
            'hasTotals' => $totals['all'] !== [],
            'missingFeesNotice' => $this->missingFeesNotice($report->missingFees($totals)),
        ]);
    }

    /**
     * A notice when Stripe's fee isn't recorded yet for some donations, so
     * the totals' fees and net are off, or null.
     */
    private function missingFeesNotice(int $missingFees): ?string
    {
        if ($missingFees === 0) {
            return null;
        }

        $donations = $missingFees === 1 ? '1 donation, so the fees leave it out' : "{$missingFees} donations, so the fees leave them out";

        return "Stripe's fee isn't recorded yet for {$donations} and the net is that much too high. The nightly Stripe sync records the fees once Stripe has them.";
    }

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
