<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\DonationReport;
use App\Reports\FormSubmissionReport;
use App\Reports\LeafApplicationReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin dashboard (/admin): who is signed in, and an overview of the
 * last 90 days, with links to each list for the same period. The counts come
 * from the same report classes as the lists, so they always match.
 */
class DashboardController extends Controller
{
    /**
     * The days the overview covers, matching a Period filter of the lists.
     */
    public const DAYS = 90;

    public function __invoke(
        Request $request,
        DonationReport $donations,
        LeafApplicationReport $applications,
        FormSubmissionReport $submissions,
    ): View {
        return view('pages.admin.home', [
            'admin' => $request->user(),
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
            ],
        ]);
    }
}
