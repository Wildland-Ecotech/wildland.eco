<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\FiltersByPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\LinkApplicationContactRequest;
use App\Models\Contact;
use App\Models\ContactDetail;
use App\Models\LeafApplication;
use App\Reports\LeafApplicationReport;
use App\Reports\Traits\DescribesLeafApplications;
use App\Reports\Traits\FormatsCsv;
use Illuminate\Http\Request;
use App\Services\ApplicationReview;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin leaf application pages (/admin/leaves): the list of applications
 * with filters and a CSV download, and one application in full. They show
 * the same records and values as leaves:applications and leaves:view, from
 * LeafApplicationReport and DescribesLeafApplications. An application's page
 * also shows its contact, and why it needs review, with the forms that
 * settle it (ApplicationContactController).
 */
class LeafApplicationController extends Controller
{
    use DescribesLeafApplications, FiltersByPeriod, FormatsCsv;

    /**
     * The type filters' labels, keyed by LeafApplication::TYPES.
     */
    public const TYPE_LABELS = [
        LeafApplication::TYPE_DROP_OFF => 'Drop-off',
        LeafApplication::TYPE_PICKUP => 'Pickup',
    ];

    /**
     * The list's columns that make up an application's line on a phone
     * (applied, name, type, and bags), and the columns lined up on the right
     * (ID and bags).
     */
    public const SUMMARY_COLUMNS = [1, 2, 3, 4];

    public const NUMBER_COLUMNS = [0, 4];

    /**
     * The list of applications, filtered by the query string.
     */
    public function index(Request $request, LeafApplicationReport $report): View
    {
        $filters = $this->filters($request);
        $applications = $report->list(self::PERIODS[$filters['period']], $filters['types']);
        $count = $applications->count();

        return view('pages.admin.leaves.index', [
            'filters' => $filters,
            'summary' => $this->periodSummary($filters['period'], $count, $this->leafApplicationListName($filters['types'], $count)),
            'headers' => self::LEAF_APPLICATION_TABLE_HEADERS,
            'rows' => $applications->map(fn (LeafApplication $application) => [
                // Like leaves:applications's row, with a shorter time for the page
                'cells' => array_replace($this->leafApplicationTableRow($application), [1 => $this->listTime($application->created_at)]),
                'summary' => [
                    $this->shortDate($application->created_at),
                    $application->name,
                    $this->leafApplicationType($application),
                    $application->estimated_bag_count . ' ' . Str::plural('bag', $application->estimated_bag_count),
                ],
                'url' => route('admin.leaves.show', $application->id),
            ])->all(),
            'csvUrl' => route('admin.leaves.csv', $this->query($filters)),
        ]);
    }

    /**
     * The same list as a CSV download, with every detail, like
     * `leaves:applications --csv`.
     */
    public function download(Request $request, LeafApplicationReport $report): Response
    {
        $filters = $this->filters($request);
        $applications = $report->list(self::PERIODS[$filters['period']], $filters['types']);

        $filename = 'leaf-applications-' . now(config('organization.timezone'))->format('Y-m-d') . '.csv';

        return response($this->csv($this->leafApplicationCsvHeader(), $applications->map($this->leafApplicationCsvRow(...))), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * One application in full, including a deleted one, marked as deleted,
     * with its contact.
     */
    public function show(string $application, LeafApplicationReport $report, ApplicationReview $review): View
    {
        // The route only matches digits
        $record = $report->find((int) $application);

        abort_if($record === null, 404);

        $candidates = $review->candidates($record);

        return view('pages.admin.leaves.show', [
            'application' => $record,
            'fields' => [
                'Applied' => $this->localTime($record->created_at),
                ...$this->leafApplicationFields($record),
                'Terms accepted' => $this->localTime($record->terms_accepted_at),
            ],
            'deletedAt' => $record->trashed() ? $this->localTime($record->deleted_at) : null,
            'contact' => $record->contact,
            'reviewReasons' => $record->needsContactReview() ? $this->reviewReasons($record, $candidates) : [],
            'reviewedNote' => $this->reviewedNote($record),
            'contactOptions' => [
                ...$candidates->map(fn (array $candidate) => [
                    'value' => (string) $candidate['contact']->id,
                    'label' => $candidate['contact']->name . ' · ' . ($candidate['types'][0] === ContactDetail::TYPE_EMAIL ? $record->email : $record->phone),
                ])->all(),
                ['value' => LinkApplicationContactRequest::NEW_CONTACT, 'label' => 'A new contact'],
            ],
        ]);
    }

    /**
     * Why the application's contact needs review, one sentence each as
     * HTML, naming the other contacts it's about with links to them. Every
     * part is escaped here, so the page prints the sentences as they are.
     *
     * @param  Collection<int, array{contact: Contact, types: list<string>}>  $candidates
     * @return list<string>
     */
    private function reviewReasons(LeafApplication $application, Collection $candidates): array
    {
        $link = fn (array $candidate, string $note = '') => '<a href="' . e(route('admin.contacts.show', $candidate['contact']->id)) . '">' . e($candidate['contact']->name) . '</a>' . e($note);
        $sharing = fn (string $type) => $candidates->filter(fn (array $candidate) => in_array($type, $candidate['types'], true))->map(fn (array $candidate) => $link($candidate))->all();
        $sentence = fn (string $text, array $links = []) => e($text) . ($links === [] ? '' : ': ' . implode(', ', $links)) . '.';

        return collect($application->contact_review)->map(fn (string $reason) => match ($reason) {
            LeafApplication::REVIEW_SHARED_EMAIL => $sentence('Email also on another contact', $sharing(ContactDetail::TYPE_EMAIL)),
            LeafApplication::REVIEW_SHARED_PHONE => $sentence('Phone number also on another contact', $sharing(ContactDetail::TYPE_PHONE)),
            LeafApplication::REVIEW_ADDRESS => $sentence('Different address from the contact’s current one'),
            LeafApplication::REVIEW_CONFLICT => $sentence('Matches more than one contact with this name', $candidates->map(
                fn (array $candidate) => $link($candidate, ' (by ' . ($candidate['types'][0] === ContactDetail::TYPE_EMAIL ? 'email' : 'phone number') . ')'),
            )->all()),
            default => $sentence(LeafApplication::REVIEW_LABELS[$reason] ?? $reason),
        })->values()->all();
    }

    /**
     * Who reviewed the application's contact, and when, or null.
     */
    private function reviewedNote(LeafApplication $application): ?string
    {
        if ($application->contact_reviewed_at === null) {
            return null;
        }

        $reviewer = $application->contactReviewer;
        $by = $reviewer ? ' by ' . ($reviewer->name ?: $reviewer->email) : '';

        return "Reviewed{$by} on {$this->listTime($application->contact_reviewed_at)}.";
    }

    /**
     * The list's filters from the query string. Anything unexpected falls
     * back to the defaults, since the form itself can only send valid values.
     *
     * @return array{period: string, types: array<int, string>}
     */
    private function filters(Request $request): array
    {
        $types = $request->query('type');

        return [
            'period' => $this->periodFilter($request),
            'types' => is_array($types) ? array_values(array_intersect(LeafApplication::TYPES, $types)) : [],
        ];
    }

    /**
     * The query string for the filters, for the CSV download link.
     *
     * @param  array{period: string, types: array<int, string>}  $filters
     * @return array<string, mixed>
     */
    private function query(array $filters): array
    {
        return array_filter([
            'period' => $filters['period'],
            'type' => $filters['types'],
        ]);
    }
}
