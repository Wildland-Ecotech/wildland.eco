<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactActivity;
use App\Reports\LeafLedgerReport;
use App\Reports\Traits\DescribesLedgerEntries;
use App\Reports\Traits\FormatsCsv;
use App\Reports\Traits\FormatsTimes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin Leaf Ledger page (/admin/leaves/ledger): pickups and drop-offs
 * logged for a season, with bags, notes, and the admin who logged each, a
 * line of totals, a Season filter, and a CSV download, from
 * LeafLedgerReport. Entries are logged on contacts' timelines and by marking
 * trailer loads done, not here.
 */
class LeafLedgerController extends Controller
{
    use DescribesLedgerEntries, FormatsCsv, FormatsTimes;

    public const HEADERS = ['Date', 'Name', 'Bags', 'Type', 'Notes', 'Logged by'];

    /**
     * The columns that make up an entry's line on a phone (date, name, bags,
     * and type), and the column lined up on the right (bags).
     */
    public const SUMMARY_COLUMNS = [0, 1, 2, 3];

    public const NUMBER_COLUMNS = [2];

    /**
     * The ledger for the season in the query string.
     */
    public function index(Request $request, LeafLedgerReport $report): View
    {
        $season = $this->seasonFilter($request, $report);
        $entries = $report->list($season);

        return view('pages.admin.leaves.ledger', [
            'season' => $season,
            'seasons' => collect($report->seasons())->mapWithKeys(fn (int $season) => [$season => $report->name($season)])->all(),
            'summary' => $this->summary($entries, $report->name($season)),
            'headers' => self::HEADERS,
            'rows' => $entries->map(fn (ContactActivity $entry) => [
                'cells' => [
                    $this->listTime($entry->happened_at),
                    $this->ledgerEntryNameCell($entry),
                    number_format($entry->bag_count),
                    ContactActivityController::TYPE_LABELS[$entry->type],
                    $entry->body ?? '',
                    $this->loggedBy($entry),
                ],
                'summary' => [
                    $this->shortDate($entry->happened_at),
                    $this->ledgerEntryName($entry),
                    ContactActivityController::TYPE_LABELS[$entry->type],
                    number_format($entry->bag_count) . ' ' . Str::plural('bag', $entry->bag_count),
                ],
            ])->all(),
            'csvUrl' => route('admin.leaves.ledger.csv', ['season' => $season]),
        ]);
    }

    /**
     * The same ledger as a CSV download, with full dates and times.
     */
    public function download(Request $request, LeafLedgerReport $report): Response
    {
        $season = $this->seasonFilter($request, $report);

        $rows = $report->list($season)->map(fn (ContactActivity $entry) => [
            $this->localTime($entry->happened_at, 'Y-m-d H:i'),
            $this->ledgerEntryName($entry),
            $entry->bag_count,
            ContactActivityController::TYPE_LABELS[$entry->type],
            $entry->body ?? '',
            $this->loggedBy($entry),
        ]);

        $filename = "leaf-ledger-{$season}.csv";

        return response($this->csv(self::HEADERS, $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * The `season` query value when it's one of the seasons, or else the
     * current season, since the form itself can only send valid values.
     */
    private function seasonFilter(Request $request, LeafLedgerReport $report): int
    {
        $season = $request->query('season');

        return is_string($season) && in_array((int) $season, $report->seasons(), true) ? (int) $season : $report->current();
    }

    /**
     * The season's totals, such as "312 bags collected in Autumn 2026, from
     * 41 pickups and 9 drop-offs."
     *
     * @param  Collection<int, ContactActivity>  $entries
     */
    private function summary(Collection $entries, string $name): string
    {
        if ($entries->isEmpty()) {
            return "No pickups or drop-offs logged in {$name}.";
        }

        $bags = (int) $entries->sum('bag_count');
        $pickups = $entries->where('type', ContactActivity::TYPE_PICKUP)->count();
        $dropOffs = $entries->where('type', ContactActivity::TYPE_DROP_OFF)->count();

        return number_format($bags) . ' ' . Str::plural('bag', $bags) . " collected in {$name}, from "
            . number_format($pickups) . ' ' . Str::plural('pickup', $pickups) . ' and '
            . number_format($dropOffs) . ' ' . Str::plural('drop-off', $dropOffs) . '.';
    }

    /**
     * The admin who logged an entry, by name or else email, or empty.
     */
    private function loggedBy(ContactActivity $entry): string
    {
        return $entry->loggedBy ? ($entry->loggedBy->name ?: $entry->loggedBy->email) : '';
    }
}
