<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeafApplication;
use App\Reports\DonationReport;
use App\Reports\DonationTotalsReport;
use App\Reports\FollowUpReport;
use App\Reports\FormSubmissionReport;
use App\Reports\LeafApplicationReport;
use App\Reports\Traits\DescribesDonationTotals;
use App\Reports\Traits\DescribesFollowUps;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin dashboard (/admin): who is signed in, a warning when people
 * are waiting to hear from us or have bags ready, the last 90 days at a
 * glance (Recent Activity), with links to each list for the same period and
 * to the applications whose contact needs review, and the donations we
 * received month over month, by quarter, by year, and for all time
 * (Donation Statistics). The counts come from the same report classes and
 * queries as the lists, and the totals are the ones donations:totals shows,
 * from DonationTotalsReport and DescribesDonationTotals, so they always
 * match.
 */
class DashboardController extends Controller
{
    use DescribesDonationTotals, DescribesFollowUps;

    /**
     * The days the overview covers, matching a Period filter of the lists.
     */
    public const DAYS = 90;

    /**
     * The columns that make up a total's line on a phone (period, donations,
     * and amount), and the columns lined up on the right (every number).
     */
    public const TOTALS_SUMMARY_COLUMNS = [0, 1, 2];

    public const TOTALS_NUMBER_COLUMNS = [1, 2, 3, 4, 5];

    public function __invoke(
        Request $request,
        DonationReport $donations,
        LeafApplicationReport $applications,
        FormSubmissionReport $submissions,
        DonationTotalsReport $totalsReport,
        FollowUpReport $followUps,
    ): View {
        $totals = $totalsReport->totals();

        return view('pages.admin.home', [
            'admin' => $request->user(),
            'followUpNotice' => $this->followUpNotice($this->followUpsByContact($followUps)),
            'days' => self::DAYS,
            'overview' => [
                [
                    'count' => $donations->list(self::DAYS)->count(),
                    'label' => 'Donations received',
                    'url' => route('admin.donations', ['period' => (string) self::DAYS]),
                ],
                [
                    'count' => $applications->list(self::DAYS)->count(),
                    'label' => 'Leaf applications',
                    'url' => route('admin.leaves', ['period' => (string) self::DAYS]),
                ],
                [
                    // Leaving out errors the visitor got through later, and
                    // those an admin closed as resolved, a test, or spam
                    'count' => $submissions->list(self::DAYS, submittedLater: false, resolvedByAdmin: false)->count(),
                    'label' => 'Unresolved errors',
                    'url' => route('admin.errors', ['period' => (string) self::DAYS, 'later' => '0', 'resolved' => '0']),
                ],
                [
                    // Applications from the same days whose contact needs an
                    // admin to look; Needs Review lists them all
                    'count' => LeafApplication::needingContactReview()->where('created_at', '>=', now()->subDays(self::DAYS))->count(),
                    'label' => 'Applications to review',
                    'url' => route('admin.contacts.review'),
                ],
            ],
            'totalsHeaders' => self::DONATION_TOTALS_HEADERS,
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
            'missingFeesNotice' => $this->missingFeesNotice($totalsReport->missingFees($totals)),
        ]);
    }

    /**
     * The warning when contacts are waiting to hear from us or have bags
     * ready and haven't heard from us yet, counted as Contacts lists them,
     * or null when there are none.
     *
     * @param  Collection<int, array{tier: string}>  $followUps
     */
    private function followUpNotice(Collection $followUps): ?string
    {
        $waiting = $followUps->where('tier', FollowUpReport::WAITING)->count();
        $ready = $followUps->where('tier', FollowUpReport::READY)->count();
        $people = fn (int $count) => $count === 1 ? '1 person' : "{$count} people";

        return match (true) {
            $waiting > 0 && $ready > 0 => $people($waiting) . ($waiting === 1 ? ' is' : ' are') . " waiting to hear from us, and {$ready} more " . ($ready === 1 ? 'has' : 'have') . ' bags ready.',
            $waiting > 0 => $people($waiting) . ($waiting === 1 ? ' is' : ' are') . ' waiting to hear from us.',
            $ready > 0 => $people($ready) . ($ready === 1 ? ' has' : ' have') . ' bags ready and ' . ($ready === 1 ? 'hasn’t' : 'haven’t') . ' heard from us yet.',
            default => null,
        };
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
}
