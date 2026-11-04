<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Reports\DonationReport;
use App\Reports\Traits\DescribesDonations;
use App\Reports\Traits\FormatsCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The admin donation pages (/admin/donations): the list of payments with
 * filters and a CSV download, and one donation in full. They show the same
 * records and values as donations:list and donations:view, from
 * DonationReport and DescribesDonations.
 */
class DonationController extends Controller
{
    use DescribesDonations, FormatsCsv;

    /**
     * The periods the list can show, keyed by the `period` query value: a
     * number of days back, or null for all time.
     */
    public const PERIODS = [
        '30' => 30,
        '90' => 90,
        '365' => 365,
        'all' => null,
    ];

    public const PERIOD_LABELS = [
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        '365' => 'Last 365 days',
        'all' => 'All time',
    ];

    public const DEFAULT_PERIOD = '30';

    /**
     * The type filters' labels, keyed by DonationReport::TYPES.
     */
    public const TYPE_LABELS = [
        DonationReport::TYPE_ONE_TIME => 'One-time',
        DonationReport::TYPE_MONTHLY => 'Monthly',
        DonationReport::TYPE_ANNUAL => 'Annual',
    ];

    /**
     * The list's columns that make up a record's line on a phone (date, donor,
     * and amount), and the columns lined up on the right (ID and amount).
     */
    public const SUMMARY_COLUMNS = [1, 2, 4];

    public const NUMBER_COLUMNS = [0, 4];

    /**
     * The list of payments, filtered by the query string.
     */
    public function index(Request $request, DonationReport $report): View
    {
        $filters = $this->filters($request);
        $donations = $report->list(self::PERIODS[$filters['period']], $filters['types'], $filters['all']);

        return view('pages.admin.donations.index', [
            'filters' => $filters,
            'summary' => $this->summary($filters, $donations->count()),
            'headers' => self::DONATION_TABLE_HEADERS,
            'rows' => $donations->map(fn (Donation $donation) => [
                'cells' => $this->donationTableRow($donation),
                'summary' => [
                    $this->shortDate($this->donationMadeAt($donation)),
                    $this->donor($donation),
                    $this->money($donation->amount_cents, $donation->currency),
                ],
                'url' => route('admin.donations.show', $donation->id),
            ])->all(),
            'csvUrl' => route('admin.donations.csv', $this->query($filters)),
        ]);
    }

    /**
     * The same list as a CSV download, with every detail, like
     * `donations:list --csv`.
     */
    public function download(Request $request, DonationReport $report): Response
    {
        $filters = $this->filters($request);
        $donations = $report->list(self::PERIODS[$filters['period']], $filters['types'], $filters['all']);

        $filename = 'donations-' . now(config('organization.timezone'))->format('Y-m-d') . '.csv';

        return response($this->csv($this->donationCsvHeader(), $donations->map($this->donationCsvRow(...))), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * One donation in full, by its ID or Stripe payment ID, including a
     * deleted one, marked as deleted.
     */
    public function show(string $donation, DonationReport $report): View
    {
        $record = $report->find($donation);

        abort_if($record === null, 404);

        return view('pages.admin.donations.show', [
            'donation' => $record,
            'fields' => [
                'Payment ID' => $record->gateway_payment_id,
                'Date' => $this->localTime($this->donationMadeAt($record)),
                ...$this->donationFields($record),
            ],
            'feeMissing' => $record->wasReceived() && $record->fee_cents === null,
            'deletedAt' => $record->trashed() ? $this->localTime($record->deleted_at) : null,
        ]);
    }

    /**
     * The list's filters from the query string. Anything unexpected, such as
     * a hand-edited URL, falls back to the defaults, since the form itself
     * can only send valid values.
     *
     * @return array{period: string, types: array<int, string>, all: bool}
     */
    private function filters(Request $request): array
    {
        $period = $request->query('period');
        $types = $request->query('type');

        return [
            'period' => is_string($period) && array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD,
            'types' => is_array($types) ? array_values(array_intersect(DonationReport::TYPES, $types)) : [],
            'all' => $request->query('all') === '1',
        ];
    }

    /**
     * The query string for the filters, for the CSV download link.
     *
     * @param  array{period: string, types: array<int, string>, all: bool}  $filters
     * @return array<string, mixed>
     */
    private function query(array $filters): array
    {
        return array_filter([
            'period' => $filters['period'],
            'type' => $filters['types'],
            'all' => $filters['all'] ? '1' : null,
        ]);
    }

    /**
     * What the list shows, such as "12 donations received in the last 30
     * days."
     *
     * @param  array{period: string, types: array<int, string>, all: bool}  $filters
     */
    private function summary(array $filters, int $count): string
    {
        $days = self::PERIODS[$filters['period']];

        if ($count === 0) {
            $name = $this->donationListName($filters['types'], $filters['all'], 2);

            return $days === null ? "No {$name} yet." : "No {$name} in the last {$days} days.";
        }

        $name = $this->donationListName($filters['types'], $filters['all'], $count);

        return $days === null ? "{$count} {$name} to date." : "{$count} {$name} in the last {$days} days.";
    }
}
