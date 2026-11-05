<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\FiltersByPeriod;
use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Reports\FormSubmissionReport;
use App\Reports\Traits\DescribesFormSubmissions;
use App\Reports\Traits\FormatsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin Error Logs pages (/admin/errors): submissions of the donate and
 * leaf application forms that didn't go through, with whether each visitor
 * got through later, filters, and a CSV download, and one submission in full
 * by its reference. They show the same submissions and details as
 * submissions:list, from FormSubmissionReport and DescribesFormSubmissions.
 * Admins mark spam, such as bots filling in forms, from the list or a
 * submission's page. For now the log holds form submissions; it may grow
 * into general error reporting.
 */
class ErrorLogController extends Controller
{
    use DescribesFormSubmissions, FiltersByPeriod, FormatsCsv;

    /**
     * The form filters' labels, keyed by FormSubmission::FORMS.
     */
    public const FORM_LABELS = [
        FormSubmission::FORM_DONATION => 'Donation',
        FormSubmission::FORM_LEAF_APPLICATION => 'Leaf application',
    ];

    /**
     * The list's columns that make up a submission's line on a phone
     * (submitted, name, and what happened). No column is a number.
     */
    public const SUMMARY_COLUMNS = [1, 4, 3];

    /**
     * The list's Follow-up column, which shows an icon.
     */
    private const FOLLOW_UP_COLUMN = 5;

    /**
     * The status icon for each follow-up (see the admin-records component),
     * and the words read out with it on a phone's line.
     */
    private const FOLLOW_UP_ICONS = [
        self::FOLLOW_UP_RESOLVED => ['status' => 'success', 'label' => 'Submitted later'],
        self::FOLLOW_UP_UNRESOLVED => ['status' => 'failure', 'label' => 'No record'],
        self::FOLLOW_UP_SPAM => ['status' => 'muted', 'label' => 'Spam'],
    ];

    /**
     * The list of submissions, filtered by the query string.
     */
    public function index(Request $request, FormSubmissionReport $report): View
    {
        $filters = $this->filters($request);
        $submissions = $this->submissions($report, $filters);
        $count = $submissions->count();

        return view('pages.admin.errors.index', [
            'filters' => $filters,
            'summary' => $this->periodSummary($filters['period'], $count, $this->formSubmissionListName($filters['all'], $count)),
            'headers' => self::FORM_SUBMISSION_TABLE_HEADERS,
            'rows' => $submissions->map(function (FormSubmission $submission) {
                $cells = $this->formSubmissionTableRow($submission);
                $icon = self::FOLLOW_UP_ICONS[$this->formSubmissionFollowUp($submission)] ?? null;

                if ($icon !== null) {
                    $cells[self::FOLLOW_UP_COLUMN] = [
                        'text' => $cells[self::FOLLOW_UP_COLUMN],
                        'status' => $icon['status'],
                        'url' => $this->laterRecordUrl($submission),
                    ];
                }

                return [
                    'cells' => $cells,
                    'summary' => array_values(array_filter([$this->shortDate($submission->created_at), $cells[4], $cells[3]])),
                    'status' => $icon['status'] ?? null,
                    'statusLabel' => $icon['label'] ?? null,
                    'value' => $submission->reference,
                    'url' => route('admin.errors.show', $submission->reference),
                ];
            })->all(),
            'csvUrl' => route('admin.errors.csv', $this->query($filters)),
        ]);
    }

    /**
     * The same list as a CSV download, with every detail, like
     * `submissions:list --csv`.
     */
    public function download(Request $request, FormSubmissionReport $report): Response
    {
        $submissions = $this->submissions($report, $this->filters($request));

        $filename = 'error-logs-' . now(config('organization.timezone'))->format('Y-m-d') . '.csv';

        return response($this->csv($this->formSubmissionCsvHeader(), $submissions->map($this->formSubmissionCsvRow(...))), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * One submission in full, by its reference, whatever its outcome.
     */
    public function show(string $reference, FormSubmissionReport $report): View
    {
        $submission = $report->find($reference);

        abort_if($submission === null, 404);

        $fields = [
            'Submitted' => $this->localTime($submission->created_at),
            ...$this->formSubmissionFields($submission),
        ];
        $icon = self::FOLLOW_UP_ICONS[$this->formSubmissionFollowUp($submission)] ?? null;

        if ($icon !== null) {
            $fields['Follow-up'] = [
                'text' => $this->formSubmissionFollowUpText($submission),
                'status' => $icon['status'],
                'url' => $this->laterRecordUrl($submission),
            ];
        }

        $markedBy = $submission->markedSpamBy;

        return view('pages.admin.errors.show', [
            'submission' => $submission,
            'fields' => $fields,
            'spamNote' => $submission->isSpam()
                ? 'Marked as spam by ' . ($markedBy?->name ?: $markedBy?->email ?: 'an admin') . ' on ' . $this->tableTime($submission->marked_spam_at) . '.'
                : null,
        ]);
    }

    /**
     * Mark the chosen submissions as spam, from the list's checkboxes or a
     * submission's page, recording which admin did. Spam is left out of the
     * list and the dashboard's count.
     */
    public function markSpam(Request $request): RedirectResponse
    {
        $submissions = $this->chosenSubmissions($request, 'Select at least one submission to mark as spam.');

        $submissions->each(fn (FormSubmission $submission) => $submission->markAsSpam($request->user()));

        return back()->with('status', $this->countOf($submissions->count()) . ' marked as spam.');
    }

    /**
     * Undo a mistaken spam mark, from a submission's page.
     */
    public function unmarkSpam(Request $request): RedirectResponse
    {
        $submissions = $this->chosenSubmissions($request, 'Choose a submission to unmark as spam.');

        $submissions->each(fn (FormSubmission $submission) => $submission->unmarkSpam());

        return back()->with('status', $this->countOf($submissions->count()) . ' no longer marked as spam.');
    }

    /**
     * The submissions chosen by reference in `references[]`. Unknown
     * references are skipped; choosing none goes back with $message.
     *
     * @return Collection<int, FormSubmission>
     */
    private function chosenSubmissions(Request $request, string $message): Collection
    {
        $validated = $request->validate([
            'references' => ['required', 'array'],
            'references.*' => ['string', 'size:6'],
        ], [
            'references' => $message,
            'references.*' => $message,
        ]);

        // A row's checkbox appears twice, in the table and the phone list
        $references = array_values(array_unique(array_map(strtoupper(...), $validated['references'])));

        return FormSubmission::query()->whereIn('reference', $references)->orderBy('created_at')->get();
    }

    /**
     * "1 submission" or "3 submissions", for the message after marking.
     */
    private function countOf(int $count): string
    {
        return $count . ' ' . Str::plural('submission', $count);
    }

    /**
     * The page of what the visitor's later submission made: the leaf
     * application, or the donation. Null without one, or before the
     * donation has arrived from the payment processor.
     */
    private function laterRecordUrl(FormSubmission $submission): ?string
    {
        $later = $submission->laterSubmission;

        return match (true) {
            $later?->leafApplication !== null => route('admin.leaves.show', $later->leafApplication->id),
            $later?->donation !== null => route('admin.donations.show', $later->donation->id),
            default => null,
        };
    }

    /**
     * The submissions the filters choose, from the report.
     *
     * @param  array{period: string, forms: array<int, string>, all: bool, resolved: bool, spam: bool}  $filters
     * @return Collection<int, FormSubmission>
     */
    private function submissions(FormSubmissionReport $report, array $filters): Collection
    {
        return $report->list(self::PERIODS[$filters['period']], $filters['forms'], $filters['all'], $filters['resolved'], $filters['spam']);
    }

    /**
     * The list's filters from the query string. Anything unexpected falls
     * back to the defaults, since the form itself can only send valid values.
     * Submissions the visitor resolved are shown unless `resolved=0`.
     *
     * @return array{period: string, forms: array<int, string>, all: bool, resolved: bool, spam: bool}
     */
    private function filters(Request $request): array
    {
        $forms = $request->query('form');

        return [
            'period' => $this->periodFilter($request),
            'forms' => is_array($forms) ? array_values(array_intersect(FormSubmission::FORMS, $forms)) : [],
            'all' => $request->query('all') === '1',
            'resolved' => $request->query('resolved') !== '0',
            'spam' => $request->query('spam') === '1',
        ];
    }

    /**
     * The query string for the filters, for the CSV download link.
     *
     * @param  array{period: string, forms: array<int, string>, all: bool, resolved: bool, spam: bool}  $filters
     * @return array<string, mixed>
     */
    private function query(array $filters): array
    {
        return array_filter([
            'period' => $filters['period'],
            'form' => $filters['forms'],
            'all' => $filters['all'] ? '1' : null,
            'resolved' => $filters['resolved'] ? null : '0',
            'spam' => $filters['spam'] ? '1' : null,
        ]);
    }
}
