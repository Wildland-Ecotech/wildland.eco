<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\FiltersByPeriod;
use App\Http\Controllers\Admin\Traits\ShowsFollowUps;
use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Reports\ContactReport;
use App\Reports\FormSubmissionReport;
use App\Reports\Traits\DescribesFormSubmissions;
use App\Reports\Traits\FormatsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The admin Error Logs pages (/admin/errors): submissions of the donate and
 * leaf application forms that didn't go through, with whether each visitor
 * got through later, filters, and a CSV download, and one submission in full
 * by its reference. They show the same submissions and details as
 * submissions:list, from FormSubmissionReport and DescribesFormSubmissions.
 * Admins close submissions that need no more follow-up as resolved, a test,
 * or spam, from the list or a submission's page, and reopen them. For now
 * the log holds form submissions; it may grow into general error reporting.
 */
class ErrorLogController extends Controller
{
    use DescribesFormSubmissions, FiltersByPeriod, FormatsCsv, ShowsFollowUps;

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
     * The list's Name column, which links to the person's contact, and its
     * Follow-up column, which shows an icon.
     */
    private const NAME_COLUMN = 4;

    private const FOLLOW_UP_COLUMN = 5;

    /**
     * The ways to close a submission, keyed by FormSubmission::RESOLUTIONS:
     * the choices on a submission's page, the list's menu, and the message
     * after closing one or more ("marked as …").
     */
    public const RESOLUTION_CHOICES = [
        FormSubmission::RESOLUTION_RESOLVED => 'Resolved: we followed up',
        FormSubmission::RESOLUTION_TEST => 'Test',
        FormSubmission::RESOLUTION_SPAM => 'Spam',
    ];

    public const RESOLUTION_MENU = [
        FormSubmission::RESOLUTION_SPAM => 'Spam',
        FormSubmission::RESOLUTION_TEST => 'Test',
        FormSubmission::RESOLUTION_RESOLVED => 'Resolved',
    ];

    /**
     * The longest note an admin can leave when closing a submission.
     */
    public const NOTE_MAX_LENGTH = 1000;

    /**
     * The list of submissions, filtered by the query string.
     */
    public function index(Request $request, FormSubmissionReport $report, ContactReport $contactReport): View
    {
        $filters = $this->filters($request);
        $submissions = $this->submissions($report, $filters);
        $count = $submissions->count();
        $contacts = $contactReport->contactsFor($submissions);

        return view('pages.admin.errors.index', [
            'filters' => $filters,
            'summary' => $this->periodSummary($filters['period'], $count, $this->formSubmissionListName($filters['all'], $count)),
            'headers' => self::FORM_SUBMISSION_TABLE_HEADERS,
            'rows' => $submissions->map(function (FormSubmission $submission) use ($contacts) {
                // Like submissions:list's row, with a shorter time for the page
                $cells = array_replace($this->formSubmissionTableRow($submission), [1 => $this->listTime($submission->created_at)]);
                $icon = $this->followUpIcon($submission);
                $name = $cells[self::NAME_COLUMN];
                $contact = $contacts[$submission->id] ?? null;

                $cells[self::FOLLOW_UP_COLUMN] = $this->followUpCell($submission, $cells[self::FOLLOW_UP_COLUMN]);

                // The name links to the person's contact
                if ($contact !== null) {
                    $cells[self::NAME_COLUMN] = ['text' => $name, 'url' => route('admin.contacts.show', $contact->id)];
                }

                return [
                    'cells' => $cells,
                    'summary' => array_values(array_filter([$this->shortDate($submission->created_at), $name, $cells[3]])),
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
    public function show(string $reference, FormSubmissionReport $report, ContactReport $contactReport): View
    {
        $submission = $report->find($reference);

        abort_if($submission === null, 404);

        $fields = [
            'Submitted' => $this->localTime($submission->created_at),
            ...$this->formSubmissionFields($submission),
        ];

        if ($this->followUpIcon($submission) !== null) {
            $fields['Follow-up'] = $this->followUpCell($submission, $this->formSubmissionFollowUpText($submission));
        }

        // The person's contact, by the application it made, or their email or phone number
        $contact = $contactReport->contactsFor(collect([$submission]))[$submission->id] ?? null;
        $fields = $this->insertAfter($fields, 'Name', [
            'Contact' => $contact ? ['text' => $contact->name, 'url' => route('admin.contacts.show', $contact->id)] : '',
        ]);

        return view('pages.admin.errors.show', [
            'submission' => $submission,
            'fields' => $fields,
            'closedNote' => $this->closedNote($submission),
        ]);
    }

    /**
     * Close the chosen submissions as resolved, a test, or spam, from the
     * list's checkboxes and menu or a submission's page (with an optional
     * note), recording which admin did. Closed submissions aren't counted
     * on the dashboard.
     */
    public function close(Request $request): RedirectResponse
    {
        $validated = $this->validateChoice($request, 'Select at least one submission to mark.', [
            'resolution' => ['required', Rule::in(FormSubmission::RESOLUTIONS)],
            'note' => ['nullable', 'string', 'max:' . self::NOTE_MAX_LENGTH],
        ], [
            'resolution' => 'Choose resolved, test, or spam.',
            'note' => 'Keep the note to ' . number_format(self::NOTE_MAX_LENGTH) . ' characters or fewer.',
        ]);
        $submissions = $this->chosenSubmissions($validated);
        $resolution = $validated['resolution'];

        $submissions->each(fn (FormSubmission $submission) => $submission->close($request->user(), $resolution, $validated['note'] ?? null));

        $count = $submissions->count();
        $closedAs = match ($resolution) {
            FormSubmission::RESOLUTION_TEST => $count === 1 ? 'a test' : 'tests',
            default => $resolution,
        };

        return back()->with('status', $this->countOf($count) . " marked as {$closedAs}.");
    }

    /**
     * Reopen a submission closed by mistake, from its page, so it needs a
     * follow-up again.
     */
    public function reopen(Request $request): RedirectResponse
    {
        $submissions = $this->chosenSubmissions($this->validateChoice($request, 'Choose a submission to reopen.'));

        $submissions->each(fn (FormSubmission $submission) => $submission->reopen());

        return back()->with('status', $this->countOf($submissions->count()) . ' reopened.');
    }

    /**
     * How a closed submission was closed, by whom, and when, with the note:
     * "Marked as a test by Rachel Shelton on Nov 5, 2026 12:30 PM MST: “My
     * own test donation.”". Null for one that isn't closed.
     */
    private function closedNote(FormSubmission $submission): ?string
    {
        if (! $submission->isClosed()) {
            return null;
        }

        $closed = match ($submission->resolution) {
            FormSubmission::RESOLUTION_RESOLVED => 'Resolved',
            FormSubmission::RESOLUTION_TEST => 'Marked as a test',
            default => 'Marked as spam',
        } . ' by ' . $this->formSubmissionClosedBy($submission) . ' on ' . $this->tableTime($submission->resolved_at);

        return $submission->resolution_note ? "{$closed}: “{$submission->resolution_note}”" : "{$closed}.";
    }

    /**
     * Validate the submissions chosen by reference in `references[]`, with
     * any other $rules and their $messages, in one pass so every error shows
     * at once. Choosing none goes back with $message.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    private function validateChoice(Request $request, string $message, array $rules = [], array $messages = []): array
    {
        return $request->validate([
            'references' => ['required', 'array'],
            'references.*' => ['string', 'size:6'],
            ...$rules,
        ], [
            'references' => $message,
            'references.*' => $message,
            ...$messages,
        ]);
    }

    /**
     * The submissions chosen in validated `references[]`. Unknown
     * references are skipped.
     *
     * @param  array<string, mixed>  $validated
     * @return Collection<int, FormSubmission>
     */
    private function chosenSubmissions(array $validated): Collection
    {
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
     * The submissions the filters choose, from the report.
     *
     * @param  array{period: string, forms: array<int, string>, all: bool, later: bool, resolved: bool, spam: bool}  $filters
     * @return Collection<int, FormSubmission>
     */
    private function submissions(FormSubmissionReport $report, array $filters): Collection
    {
        return $report->list(
            self::PERIODS[$filters['period']],
            $filters['forms'],
            $filters['all'],
            submittedLater: $filters['later'],
            resolvedByAdmin: $filters['resolved'],
            spamAndTests: $filters['spam'],
        );
    }

    /**
     * The list's filters from the query string. Anything unexpected falls
     * back to the defaults, since the form itself can only send valid values.
     * Submissions the visitor got through with later are shown unless
     * `later=0`, and those an admin resolved unless `resolved=0`; spam and
     * tests only with `spam=1`.
     *
     * @return array{period: string, forms: array<int, string>, all: bool, later: bool, resolved: bool, spam: bool}
     */
    private function filters(Request $request): array
    {
        $forms = $request->query('form');

        return [
            'period' => $this->periodFilter($request),
            'forms' => is_array($forms) ? array_values(array_intersect(FormSubmission::FORMS, $forms)) : [],
            'all' => $request->query('all') === '1',
            'later' => $request->query('later') !== '0',
            'resolved' => $request->query('resolved') !== '0',
            'spam' => $request->query('spam') === '1',
        ];
    }

    /**
     * The query string for the filters, for the CSV download link.
     *
     * @param  array{period: string, forms: array<int, string>, all: bool, later: bool, resolved: bool, spam: bool}  $filters
     * @return array<string, mixed>
     */
    private function query(array $filters): array
    {
        return array_filter([
            'period' => $filters['period'],
            'form' => $filters['forms'],
            'all' => $filters['all'] ? '1' : null,
            'later' => $filters['later'] ? null : '0',
            'resolved' => $filters['resolved'] ? null : '0',
            'spam' => $filters['spam'] ? '1' : null,
        ]);
    }

    /**
     * The fields with $insert added after the one labeled $after.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $insert
     * @return array<string, mixed>
     */
    private function insertAfter(array $fields, string $after, array $insert): array
    {
        $position = array_search($after, array_keys($fields), true) + 1;

        return array_slice($fields, 0, $position, true) + $insert + array_slice($fields, $position, null, true);
    }
}
