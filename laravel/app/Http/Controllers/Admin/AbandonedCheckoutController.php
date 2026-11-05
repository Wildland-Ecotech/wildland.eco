<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\FiltersByPeriod;
use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Reports\AbandonedCheckoutReport;
use App\Reports\Traits\DescribesCheckouts;
use App\Reports\Traits\FormatsCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin abandoned checkouts page (/admin/donations/abandoned): checkouts
 * donors left without paying, with whether each donor donated later, a
 * Period filter, and a CSV download. It shows the same checkouts as
 * donations:abandoned, from AbandonedCheckoutReport and DescribesCheckouts.
 */
class AbandonedCheckoutController extends Controller
{
    use DescribesCheckouts, FiltersByPeriod, FormatsCsv;

    /**
     * The columns that make up a checkout's line on a phone (started, donor,
     * and amount), and the column lined up on the right (amount).
     */
    public const SUMMARY_COLUMNS = [0, 1, 3];

    public const NUMBER_COLUMNS = [3];

    /**
     * The list of abandoned checkouts for the period in the query string.
     */
    public function index(Request $request, AbandonedCheckoutReport $report): View
    {
        $period = $this->periodFilter($request);
        $checkouts = $report->list(self::PERIODS[$period]);
        $count = $checkouts->count();

        $summary = $this->periodSummary($period, $count, 'abandoned ' . Str::plural('checkout', $count));

        if ($count > 0) {
            $donatedLater = $checkouts->filter(fn (Checkout $checkout) => $checkout->donated_later)->count();
            $summary = rtrim($summary, '.') . "; {$donatedLater} donated later.";
        }

        return view('pages.admin.donations.abandoned', [
            'period' => $period,
            'summary' => $summary,
            'headers' => self::CHECKOUT_TABLE_HEADERS,
            'rows' => $checkouts->map(fn (Checkout $checkout) => [
                // Like donations:abandoned's row, with a shorter time for the page
                'cells' => array_replace($this->checkoutTableRow($checkout), [0 => $this->listTime($checkout->created_at)]),
                'summary' => [
                    $this->shortDate($checkout->created_at),
                    $this->donor($checkout),
                    $this->money($checkout->amount_cents, $checkout->currency),
                ],
            ])->all(),
            'csvUrl' => route('admin.donations.abandoned.csv', ['period' => $period]),
        ]);
    }

    /**
     * The same list as a CSV download, with the donors' names and email
     * addresses, like `donations:abandoned --csv`.
     */
    public function download(Request $request, AbandonedCheckoutReport $report): Response
    {
        $checkouts = $report->list(self::PERIODS[$this->periodFilter($request)]);

        $filename = 'abandoned-checkouts-' . now(config('organization.timezone'))->format('Y-m-d') . '.csv';

        return response($this->csv($this->checkoutCsvHeader(), $checkouts->map($this->checkoutCsvRow(...))), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
