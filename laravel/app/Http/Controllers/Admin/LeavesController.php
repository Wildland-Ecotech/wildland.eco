<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use App\Reports\LeafApplicationReport;
use App\Reports\LeafLedgerReport;
use App\Reports\Traits\DescribesLeafApplications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin Leaves dashboard (/admin/leaves), Leaves in the admin menu: the
 * current season's bags collected and logged pickups and drop-offs, the bags
 * still waiting, and the latest ledger entries and applications, each with a
 * button to its full page (LeafLedgerController and
 * LeafApplicationController).
 */
class LeavesController extends Controller
{
    use DescribesLeafApplications;

    /**
     * How many of the latest ledger entries and applications are shown.
     */
    public const LATEST = 5;

    public const LEDGER_HEADERS = ['Date', 'Name', 'Bags', 'Type'];

    /**
     * The ledger columns that make up an entry's line on a phone (all of
     * them), and the column lined up on the right (bags).
     */
    public const LEDGER_SUMMARY_COLUMNS = [0, 1, 2, 3];

    public const LEDGER_NUMBER_COLUMNS = [2];

    public function index(LeafLedgerReport $ledger, LeafApplicationReport $applications): View
    {
        $season = $ledger->current();
        $entries = $ledger->list($season);
        $bags = (int) $entries->sum('bag_count');

        return view('pages.admin.leaves.dashboard', [
            'seasonName' => $ledger->name($season),
            'seasonStart' => CarbonImmutable::create($season, LeafLedgerReport::SEASON_START_MONTH, 1)->format('F j, Y'),
            'overview' => [
                [
                    'count' => $bags,
                    'label' => Str::plural('Bag', $bags) . ' collected',
                    'url' => route('admin.leaves.ledger'),
                ],
                [
                    'count' => $entries->count(),
                    'label' => 'Pickups and drop-offs',
                    'url' => route('admin.leaves.ledger'),
                ],
                [
                    // Like the bags line on Leaf Applications for all time
                    'count' => (int) LeafApplication::query()->pending()->sum('estimated_bag_count'),
                    'label' => 'Bags still waiting',
                    'url' => route('admin.leaves.applications', ['period' => 'all']),
                ],
            ],
            'ledgerHeaders' => self::LEDGER_HEADERS,
            'ledgerRows' => $entries->reverse()->take(self::LATEST)->map(fn (ContactActivity $entry) => [
                'cells' => [
                    $this->listTime($entry->happened_at),
                    ['text' => $entry->contact->name, 'url' => route('admin.contacts.show', $entry->contact_id)],
                    number_format($entry->bag_count),
                    ContactActivityController::TYPE_LABELS[$entry->type],
                ],
                'summary' => [
                    $this->shortDate($entry->happened_at),
                    $entry->contact->name,
                    ContactActivityController::TYPE_LABELS[$entry->type],
                    number_format($entry->bag_count) . ' ' . Str::plural('bag', $entry->bag_count),
                ],
            ])->values()->all(),
            'applicationHeaders' => self::LEAF_APPLICATION_TABLE_HEADERS,
            'applicationRows' => $applications->list(null)->reverse()->take(self::LATEST)->map(fn (LeafApplication $application) => [
                // Like the Leaf Applications list, without the follow-up
                'cells' => array_replace($this->leafApplicationTableRow($application), [1 => $this->listTime($application->created_at)]),
                'summary' => [
                    $this->shortDate($application->created_at),
                    $application->name,
                    $this->leafApplicationType($application),
                    $application->estimated_bag_count . ' ' . Str::plural('bag', $application->estimated_bag_count),
                ],
                'url' => route('admin.leaves.show', $application->id),
            ])->values()->all(),
        ]);
    }
}
